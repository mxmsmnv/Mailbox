<?php namespace ProcessWire;

/** Signed, DNS-pinned HTTPS delivery for redacted notification events. */
final class MailboxWebhookClient {
    private const MAX_RESPONSE_BYTES = 65536;

    public function deliver(string $url, array $payload, string $secret): array {
        if(strlen($secret) < 32 || strlen($secret) > 4096 || strpos($secret, "\0") !== false) throw new WireException('Mailbox webhook secret must contain 32 to 4096 bytes.');
        if(strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) throw new WireException('Mailbox webhook URL contains invalid characters.');
        $parts = parse_url($url);
        if(!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) throw new WireException('Mailbox webhook URL must be an absolute HTTPS URL on port 443.');
        $host = strtolower((string) $parts['host']);
        if(filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host)) throw new WireException('Mailbox webhook requires a public DNS hostname.');
        $addresses = $this->publicAddresses($host);
        if(!$addresses) throw new WireException('Mailbox webhook host has no safe public address.');
        if(!function_exists('curl_init')) throw new WireException('PHP cURL is required for webhook delivery.');
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($body) || strlen($body) > 4096) throw new WireException('Mailbox webhook payload is too large.');
        $timestamp = (string) time();
        $eventId = (string) ($payload['event_id'] ?? '');
        if(!preg_match('/^[a-f0-9]{32,64}$/', $eventId)) throw new WireException('Mailbox webhook event identity is invalid.');
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
        $response = '';
        $curl = curl_init($url);
        if($curl === false) throw new WireException('Unable to initialize the Mailbox webhook client.');
        $address = $addresses[0];
        $resolveAddress = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Mailbox-Event: new_message', 'X-Mailbox-Event-Id: ' . $eventId, 'X-Mailbox-Timestamp: ' . $timestamp, 'X-Mailbox-Signature: v1=' . $signature],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'ProcessWire-Mailbox/1.13 webhook',
            CURLOPT_RESOLVE => [$host . ':443:' . $resolveAddress],
            CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$response): int {
                if(strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) return 0;
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if($ok === false || $status < 200 || $status >= 300) throw new WireException('Mailbox webhook endpoint did not accept the event.');
            return ['delivered' => true, 'status' => $status, 'response_bytes' => strlen($response), 'body_sha256' => hash('sha256', $response)];
        } finally {
            unset($curl);
        }
    }

    private function publicAddresses(string $host): array {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if(!is_array($records)) return [];
        $result = [];
        foreach($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [];
            $result[] = $ip;
        }
        return array_values(array_unique($result));
    }
}

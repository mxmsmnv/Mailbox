<?php namespace ProcessWire;

/** Credential-free RFC 6186/8314 and Thunderbird autoconfiguration discovery. */
final class MailboxDiscovery {
    private const MAX_XML_BYTES = 131072;

    /** @var array */
    private $presets;
    /** @var callable|null */
    private $srvResolver;
    /** @var callable|null */
    private $httpFetcher;

    public function __construct(array $presets, ?callable $srvResolver = null, ?callable $httpFetcher = null) {
        $this->presets = $presets;
        $this->srvResolver = $srvResolver;
        $this->httpFetcher = $httpFetcher;
    }

    public function discover(string $email): array {
        $email = trim($email);
        if(strlen($email) > 320 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new WireException('A valid mailbox email address is required.');
        $separator = strrpos($email, '@');
        $domain = strtolower(substr($email, $separator + 1));
        $email = substr($email, 0, $separator) . '@' . $domain;
        $this->assertHostname($domain);
        $candidates = [];
        $preset = $this->knownPreset($domain);
        if($preset !== null) $candidates[] = $this->presetCandidate($preset, $email);
        $srv = $this->srvCandidate($domain, $email);
        if($srv !== null) $candidates[] = $srv;
        foreach($this->autoconfigCandidates($domain, $email) as $candidate) $candidates[] = $candidate;
        $unique = [];
        foreach($candidates as $candidate) {
            $settings = (array) ($candidate['settings'] ?? []);
            $signature = implode('|', [(string) ($settings['host'] ?? ''), (int) ($settings['port'] ?? 0), (string) ($settings['encryption'] ?? ''), (string) ($settings['smtpHost'] ?? ''), (int) ($settings['smtpPort'] ?? 0)]);
            if(!isset($unique[$signature])) $unique[$signature] = $candidate;
        }
        return [
            'email' => $email,
            'domain' => $domain,
            'candidates' => array_values($unique),
            'credentials_used' => false,
            'applied' => false,
            'warnings' => [
                'Discovery results are untrusted candidates until an administrator reviews and saves them.',
                'Run the separate IMAP and SMTP connection tests before using an account.',
            ],
        ];
    }

    private function knownPreset(string $domain): ?string {
        $map = [
            'gmail.com' => 'gmail', 'googlemail.com' => 'gmail',
            'outlook.com' => 'microsoft365', 'hotmail.com' => 'microsoft365', 'live.com' => 'microsoft365', 'msn.com' => 'microsoft365',
            'icloud.com' => 'icloud', 'me.com' => 'icloud', 'mac.com' => 'icloud',
            'yahoo.com' => 'yahoo', 'ymail.com' => 'yahoo',
            'fastmail.com' => 'fastmail', 'fastmail.fm' => 'fastmail',
            'zoho.com' => 'zoho', 'zohomail.com' => 'zoho',
        ];
        return $map[$domain] ?? null;
    }

    private function presetCandidate(string $name, string $email): array {
        $preset = $this->presets[$name];
        $settings = $this->settingsFromDefinition($preset);
        $settings['preset'] = $name;
        $warnings = [];
        if(($settings['authentication'] ?? '') === 'oauth') $warnings[] = 'A registered provider OAuth client ID is still required.';
        return ['source' => 'built-in-preset', 'confidence' => 'high', 'username' => $email, 'settings' => $settings, 'warnings' => $warnings];
    }

    private function srvCandidate(string $domain, string $email): ?array {
        $imap = $this->firstSrv([['_imaps._tcp.', 'ssl'], ['_imap._tcp.', 'tls']], $domain);
        if($imap === null) return null;
        $smtp = $this->firstSrv([['_submissions._tcp.', 'ssl'], ['_submission._tcp.', 'tls']], $domain);
        $settings = [
            'preset' => 'custom', 'host' => $imap['host'], 'port' => $imap['port'], 'encryption' => $imap['encryption'],
            'validateCertificate' => 1, 'authentication' => 'password', 'defaultFolder' => 'INBOX', 'folderPattern' => '*',
        ];
        if($smtp !== null) $settings += ['smtpHost' => $smtp['host'], 'smtpPort' => $smtp['port'], 'smtpEncryption' => $smtp['encryption'], 'smtpValidateCertificate' => 1];
        return ['source' => 'dns-srv', 'confidence' => 'medium', 'username' => $email, 'settings' => $settings, 'warnings' => ['DNS SRV discovery does not prove server ownership; TLS certificate validation remains mandatory.']];
    }

    private function firstSrv(array $services, string $domain): ?array {
        foreach($services as $service) {
            $records = $this->resolveSrv($service[0] . $domain);
            usort($records, static function(array $a, array $b): int {
                $priority = ((int) ($a['pri'] ?? 65535)) <=> ((int) ($b['pri'] ?? 65535));
                return $priority !== 0 ? $priority : ((int) ($b['weight'] ?? 0)) <=> ((int) ($a['weight'] ?? 0));
            });
            foreach($records as $record) {
                $host = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
                $port = (int) ($record['port'] ?? 0);
                if($host === '' || $host === '.' || $port < 1 || $port > 65535) continue;
                try { $this->assertHostname($host); } catch(WireException $error) { continue; }
                return ['host' => $host, 'port' => $port, 'encryption' => $service[1]];
            }
        }
        return null;
    }

    private function resolveSrv(string $name): array {
        if($this->srvResolver !== null) {
            $records = ($this->srvResolver)($name);
            return is_array($records) ? $records : [];
        }
        if(!function_exists('dns_get_record') || !defined('DNS_SRV')) return [];
        $records = @dns_get_record($name, DNS_SRV);
        return is_array($records) ? $records : [];
    }

    private function autoconfigCandidates(string $domain, string $email): array {
        if(!class_exists('DOMDocument')) return [];
        $urls = [
            ['provider-autoconfig', 'https://autoconfig.' . $domain . '/mail/config-v1.1.xml?emailaddress=' . rawurlencode($email)],
            ['provider-well-known', 'https://' . $domain . '/.well-known/autoconfig/mail/config-v1.1.xml'],
        ];
        foreach($urls as $item) {
            try {
                $xml = $this->fetchXml($item[1]);
                if($xml === null) continue;
                $parsed = $this->parseAutoconfig($xml, $email);
                if($parsed !== null) return [['source' => $item[0], 'confidence' => 'high', 'username' => $parsed['username'], 'settings' => $parsed['settings'], 'warnings' => $parsed['warnings']]];
            } catch(\Throwable $error) {
                continue;
            }
        }
        return [];
    }

    private function fetchXml(string $url): ?string {
        if($this->httpFetcher !== null) {
            $result = ($this->httpFetcher)($url, self::MAX_XML_BYTES);
            return is_string($result) && strlen($result) <= self::MAX_XML_BYTES ? $result : null;
        }
        if(!function_exists('curl_init')) return null;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $this->assertHostname($host);
        $addresses = $this->publicAddresses($host);
        if(!$addresses) return null;
        $body = '';
        $contentType = '';
        $curl = curl_init($url);
        if($curl === false) return null;
        $address = $addresses[0];
        $resolveAddress = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ProcessWire-Mailbox/1.12 autodiscovery', CURLOPT_RESOLVE => [$host . ':443:' . $resolveAddress],
            CURLOPT_HEADERFUNCTION => static function($handle, string $line) use (&$contentType): int {
                if(stripos($line, 'Content-Type:') === 0) $contentType = strtolower(trim(substr($line, 13)));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$body): int {
                if(strlen($body) + strlen($chunk) > self::MAX_XML_BYTES) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if(curl_exec($curl) === false || (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) return null;
            if(strpos($contentType, 'xml') === false) return null;
            return $body;
        } finally {
            unset($curl);
        }
    }

    private function parseAutoconfig(string $xml, string $email): ?array {
        if($xml === '' || strlen($xml) > self::MAX_XML_BYTES || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) return null;
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if(!$loaded) return null;
        $xpath = new \DOMXPath($document);
        $incoming = $xpath->query('//*[local-name()="incomingServer" and translate(@type,"IMAP","imap")="imap"]');
        if(!$incoming) return null;
        foreach($incoming as $server) {
            $host = strtolower($this->childText($server, 'hostname'));
            $port = (int) $this->childText($server, 'port');
            $socket = strtoupper($this->childText($server, 'socketType'));
            if(!in_array($socket, ['SSL', 'STARTTLS'], true) || $port < 1 || $port > 65535) continue;
            try { $this->assertHostname($host); } catch(WireException $error) { continue; }
            $authentication = $this->authenticationMode($server, $host);
            if($authentication === null) continue;
            $username = $this->replacePlaceholders($this->childText($server, 'username'), $email);
            if($username === '') $username = $email;
            if(strlen($username) > 320 || preg_match('/[\x00-\x1F\x7F]/', $username)) continue;
            $settings = [
                'preset' => 'custom', 'host' => $host, 'port' => $port, 'encryption' => $socket === 'SSL' ? 'ssl' : 'tls',
                'validateCertificate' => 1, 'authentication' => $authentication['authentication'], 'oauthProvider' => $authentication['provider'],
                'defaultFolder' => 'INBOX', 'folderPattern' => '*',
            ];
            $outgoing = $xpath->query('//*[local-name()="outgoingServer" and translate(@type,"SMTP","smtp")="smtp"]');
            if($outgoing) foreach($outgoing as $smtp) {
                $smtpHost = strtolower($this->childText($smtp, 'hostname'));
                $smtpPort = (int) $this->childText($smtp, 'port');
                $smtpSocket = strtoupper($this->childText($smtp, 'socketType'));
                if(in_array($smtpSocket, ['SSL', 'STARTTLS'], true) && $smtpPort > 0 && $smtpPort <= 65535) {
                    try { $this->assertHostname($smtpHost); } catch(WireException $error) { continue; }
                    $smtpAuthentication = $this->authenticationMode($smtp, $smtpHost);
                    if($smtpAuthentication === null || $smtpAuthentication['authentication'] !== $authentication['authentication'] || $smtpAuthentication['provider'] !== $authentication['provider']) continue;
                    $settings += ['smtpHost' => $smtpHost, 'smtpPort' => $smtpPort, 'smtpEncryption' => $smtpSocket === 'SSL' ? 'ssl' : 'tls', 'smtpValidateCertificate' => 1];
                    break;
                }
            }
            return ['username' => $username, 'settings' => $settings, 'warnings' => $authentication['warnings']];
        }
        return null;
    }

    private function authenticationMode(\DOMElement $server, string $host): ?array {
        $values = [];
        foreach($server->childNodes as $child) if($child instanceof \DOMElement && $child->localName === 'authentication') $values[] = strtolower(trim($child->textContent));
        $oauth = in_array('oauth2', $values, true);
        if($oauth && preg_match('/(?:^|\.)gmail\.com$/', $host)) return ['authentication' => 'oauth', 'provider' => 'google', 'warnings' => ['A registered Google OAuth client ID is still required.']];
        if($oauth && ($host === 'outlook.office365.com' || preg_match('/(?:^|\.)office365\.com$/', $host))) return ['authentication' => 'oauth', 'provider' => 'microsoft', 'warnings' => ['A registered Microsoft OAuth client ID and tenant are still required.']];
        foreach($values as $value) if(in_array($value, ['password-cleartext', 'password-encrypted', 'plain', 'secure'], true)) return ['authentication' => 'password', 'provider' => '', 'warnings' => []];
        return null;
    }

    private function childText(\DOMElement $parent, string $name): string {
        foreach($parent->childNodes as $child) if($child instanceof \DOMElement && $child->localName === $name) return trim($child->textContent);
        return '';
    }

    private function replacePlaceholders(string $value, string $email): string {
        $local = substr($email, 0, strrpos($email, '@'));
        $domain = substr($email, strrpos($email, '@') + 1);
        return str_replace(['%EMAILADDRESS%', '%EMAILLOCALPART%', '%EMAILDOMAIN%'], [$email, $local, $domain], $value);
    }

    private function settingsFromDefinition(array $definition): array {
        $keys = ['host', 'port', 'encryption', 'validateCertificate', 'authentication', 'oauthProvider', 'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpValidateCertificate', 'defaultFolder', 'folderPattern'];
        $settings = [];
        foreach($keys as $key) if(array_key_exists($key, $definition)) $settings[$key] = $definition[$key];
        return $settings;
    }

    private function assertHostname(string $host): void {
        if($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host)) throw new WireException('Discovery requires a valid public DNS hostname.');
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

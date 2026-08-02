<?php namespace ProcessWire;

/**
 * Bounded HTTPS workflow client with allowlist, DNS pinning, and SSRF guards.
 * Redirects are returned to the caller but never followed.
 */
final class MailboxConfirmationClient {

    /** @var array<int, string> */
    private $allowedHosts;
    /** @var int */
    private $timeout;
    /** @var int */
    private $maxBytes;

    public function __construct(array $allowedHosts, int $timeout, int $maxBytes) {
        $this->allowedHosts = $allowedHosts;
        $this->timeout = $timeout;
        $this->maxBytes = $maxBytes;
    }

    public static function assertUrlAllowed(string $url, array $allowedHosts): void {
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if(($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new WireException('Confirmation URLs must use HTTPS and may not contain credentials.');
        }
        if(isset($parts['port']) && (int) $parts['port'] !== 443) throw new WireException('Confirmation URLs must use HTTPS port 443.');
        foreach($allowedHosts as $allowed) {
            $allowed = strtolower(rtrim((string) $allowed, '.'));
            if($host === $allowed) return;
            if(strpos($allowed, '*.') === 0) {
                $suffix = substr($allowed, 1);
                if(substr($host, -strlen($suffix)) === $suffix && $host !== substr($suffix, 1)) return;
            }
        }
        throw new WireException('The confirmation host is not allowlisted.');
    }

    public function get(string $url): array {
        return $this->execute($url, ['mode' => 'get', 'max_steps' => 1]);
    }

    public function execute(string $url, array $workflow, ?string $code = null): array {
        $mode = (string) ($workflow['mode'] ?? 'get');
        if(!in_array($mode, ['get', 'form', 'code_form'], true)) throw new WireException('Invalid confirmation workflow mode.');
        if($mode === 'code_form' && ($code === null || !preg_match('/^[A-Za-z0-9]{4,10}$/', $code))) throw new WireException('A valid confirmation code is required.');
        if(!function_exists('curl_init')) throw new WireException('PHP cURL is required for confirmation requests.');
        $curl = curl_init();
        if($curl === false) throw new WireException('Unable to initialize the confirmation client.');
        curl_setopt($curl, CURLOPT_COOKIEFILE, '');
        $steps = [];
        try {
            $response = $this->request($curl, $url, 'GET');
            $steps[] = $this->publicResult($response);
            if($mode === 'get' || $response['status'] >= 300) return $this->workflowResult($steps);
            $maximum = max(1, min(3, (int) ($workflow['max_steps'] ?? 1)));
            for($index = 0; $index < $maximum; $index++) {
                $form = $this->confirmationForm((string) $response['body'], (string) $response['url'], $mode, (string) ($workflow['code_field'] ?? ''));
                if($form === null) {
                    if($index === 0) throw new WireException('No unambiguous safe confirmation form was found.');
                    return $this->workflowResult($steps);
                }
                if($mode === 'code_form') $form['fields'][$form['code_field']] = (string) $code;
                $response = $this->request($curl, (string) $form['action'], 'POST', $form['fields']);
                $steps[] = $this->publicResult($response);
                if($response['status'] >= 300) return $this->workflowResult($steps);
            }
            if($this->confirmationForm((string) $response['body'], (string) $response['url'], $mode, (string) ($workflow['code_field'] ?? '')) !== null) {
                throw new WireException('Confirmation workflow exceeded the approved step limit.');
            }
            return $this->workflowResult($steps);
        } finally {
            unset($curl);
        }
    }

    private function request($curl, string $url, string $method, array $fields = []): array {
        self::assertUrlAllowed($url, $this->allowedHosts);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $addresses = $this->publicAddresses($host);
        if(!$addresses) throw new WireException('The confirmation host has no public IP address.');
        $address = $addresses[0];
        $body = '';
        $headers = [];
        $resolveAddress = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ProcessWire-Mailbox/1.11 confirmation-client',
            CURLOPT_RESOLVE => [$host . ':443:' . $resolveAddress],
            CURLOPT_HEADERFUNCTION => static function($handle, string $line) use (&$headers): int {
                $position = strpos($line, ':');
                if($position !== false) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    if(in_array($name, ['content-type', 'location'], true)) $headers[$name] = trim(substr($line, $position + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function($handle, string $chunk) use (&$body): int {
                if(strlen($body) + strlen($chunk) > $this->maxBytes) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if($method === 'POST') {
            $encoded = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
            if(strlen($encoded) > 32768) throw new WireException('Confirmation form payload exceeds the safe limit.');
            $options[CURLOPT_HTTPGET] = false;
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $encoded;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        } else {
            $options[CURLOPT_POST] = false;
            $options[CURLOPT_POSTFIELDS] = null;
            $options[CURLOPT_HTTPGET] = true;
            $options[CURLOPT_HTTPHEADER] = [];
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if($ok === false) throw new WireException('Confirmation request failed.');
        if($status < 200 || $status >= 400) throw new WireException('Confirmation endpoint returned HTTP ' . $status . '.');
        return ['url' => $url, 'method' => $method, 'status' => $status, 'content_type' => (string) ($headers['content-type'] ?? ''), 'location' => (string) ($headers['location'] ?? ''), 'body' => $body, 'field_count' => count($fields)];
    }

    private function publicResult(array $response): array {
        return [
            'ok' => true,
            'method' => (string) $response['method'],
            'status' => (int) $response['status'],
            'content_type' => (string) $response['content_type'],
            'redirect_host' => $this->safeRedirectHost((string) $response['location'], (string) $response['url']),
            'response_bytes' => strlen((string) $response['body']),
            'body_sha256' => hash('sha256', (string) $response['body']),
            'field_count' => (int) $response['field_count'],
        ];
    }

    private function workflowResult(array $steps): array {
        $last = $steps[count($steps) - 1];
        return $last + ['steps' => $steps, 'step_count' => count($steps)];
    }

    private function confirmationForm(string $html, string $baseUrl, string $mode, string $explicitCodeField): ?array {
        if($html === '' || !class_exists('DOMDocument')) return null;
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = @$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if(!$loaded) return null;
        $candidates = [];
        $blockedConfirmationForm = false;
        foreach($document->getElementsByTagName('form') as $form) {
            if(strtolower(trim((string) $form->getAttribute('method')) ?: 'get') !== 'post') continue;
            $rawAction = trim((string) $form->getAttribute('action'));
            $confirmationLike = $this->isConfirmationLabel((string) $form->textContent . ' ' . $rawAction);
            $enctype = strtolower(trim((string) $form->getAttribute('enctype')) ?: 'application/x-www-form-urlencoded');
            if($enctype !== 'application/x-www-form-urlencoded') { $blockedConfirmationForm = $blockedConfirmationForm || $confirmationLike; continue; }
            try {
                $action = $this->resolveUrl($rawAction, $baseUrl);
                self::assertUrlAllowed($action, $this->allowedHosts);
                $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
                $actionHost = strtolower((string) parse_url($action, PHP_URL_HOST));
                if($baseHost === '' || !hash_equals($baseHost, $actionHost)) throw new WireException('Confirmation forms must submit to the originating host.');
            } catch(WireException $error) {
                if($confirmationLike) throw $error;
                continue;
            }
            $fields = [];
            $codeFields = [];
            $submits = [];
            $unsupported = false;
            foreach($form->getElementsByTagName('input') as $input) {
                if($input->hasAttribute('disabled')) continue;
                $name = trim((string) $input->getAttribute('name'));
                $type = strtolower(trim((string) $input->getAttribute('type')) ?: 'text');
                if(in_array($type, ['submit', 'button'], true) && $input->hasAttribute('formaction')) { $unsupported = true; break; }
                $value = (string) $input->getAttribute('value');
                if($name === '') {
                    if(in_array($type, ['submit', 'button'], true)) $submits[] = ['name' => '', 'value' => $value, 'label' => $value];
                    continue;
                }
                if(!preg_match('/^[A-Za-z][A-Za-z0-9_.\[\]-]{0,127}$/', $name)) { $unsupported = true; break; }
                if(strlen($value) > 4096) { $unsupported = true; break; }
                if($type === 'hidden') {
                    if(array_key_exists($name, $fields)) { $unsupported = true; break; }
                    $fields[$name] = $value;
                }
                else if(in_array($type, ['submit', 'button'], true)) $submits[] = ['name' => $name, 'value' => $value, 'label' => $value];
                else if(in_array($type, ['text', 'number', 'tel'], true) && $this->isCodeField($name, $explicitCodeField)) {
                    if(in_array($name, $codeFields, true)) { $unsupported = true; break; }
                    $codeFields[] = $name;
                }
                else { $unsupported = true; break; }
                if(count($fields) > 64) { $unsupported = true; break; }
            }
            foreach($form->getElementsByTagName('button') as $button) {
                if($button->hasAttribute('disabled')) continue;
                $type = strtolower(trim((string) $button->getAttribute('type')) ?: 'submit');
                if($type !== 'submit') continue;
                if($button->hasAttribute('formaction')) { $unsupported = true; break; }
                $name = trim((string) $button->getAttribute('name'));
                if($name !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_.\[\]-]{0,127}$/', $name)) { $unsupported = true; break; }
                $value = (string) $button->getAttribute('value');
                if(strlen($value) > 4096) { $unsupported = true; break; }
                $submits[] = ['name' => $name, 'value' => $value, 'label' => trim((string) $button->textContent)];
            }
            foreach(['select', 'textarea'] as $tag) {
                foreach($form->getElementsByTagName($tag) as $control) if(trim((string) $control->getAttribute('name')) !== '') { $unsupported = true; break 2; }
            }
            if($unsupported) { $blockedConfirmationForm = $blockedConfirmationForm || $confirmationLike; continue; }
            if($mode === 'code_form' && count($codeFields) !== 1) { $blockedConfirmationForm = $blockedConfirmationForm || $confirmationLike; continue; }
            if($mode === 'form' && count($codeFields) !== 0) { $blockedConfirmationForm = $blockedConfirmationForm || $confirmationLike; continue; }
            $approvedSubmits = array_values(array_filter($submits, function(array $submit): bool { return $this->isConfirmationLabel($submit['label'] . ' ' . $submit['value']); }));
            if(count($approvedSubmits) !== 1) { $blockedConfirmationForm = $blockedConfirmationForm || $confirmationLike; continue; }
            $submit = $approvedSubmits[0];
            if($submit['name'] !== '') $fields[$submit['name']] = $submit['value'];
            $candidates[] = ['action' => $action, 'fields' => $fields, 'code_field' => $mode === 'code_form' ? (string) $codeFields[0] : ''];
        }
        if(count($candidates) > 1) throw new WireException('Multiple confirmation forms were found.');
        if(!$candidates && $blockedConfirmationForm) throw new WireException('A confirmation-like form uses unsupported or ambiguous controls.');
        return $candidates ? $candidates[0] : null;
    }

    private function isCodeField(string $name, string $explicit): bool {
        if($explicit !== '') return hash_equals($explicit, $name);
        return (bool) preg_match('/(?:^|[_.\[-])(code|otp|pin|token)(?:$|[_.\]-])/i', $name);
    }

    private function isConfirmationLabel(string $label): bool {
        return (bool) preg_match('/(?:^|[^\p{L}\p{N}])(confirm|verify|activate|approve|accept|continue|подтверд|активир|верифиц)[\p{L}\p{N}_-]*/iu', $label);
    }

    private function resolveUrl(string $action, string $baseUrl): string {
        if($action === '') return $baseUrl;
        if($action[0] === '#') return preg_replace('/#.*$/', '', $baseUrl);
        $actionParts = parse_url($action);
        if(isset($actionParts['scheme'])) {
            if(strtolower((string) $actionParts['scheme']) !== 'https') throw new WireException('Confirmation form actions must use HTTPS.');
            return $action;
        }
        $base = parse_url($baseUrl);
        $origin = 'https://' . (string) ($base['host'] ?? '');
        if(strpos($action, '//') === 0) return 'https:' . $action;
        if($action[0] === '/') return $origin . $action;
        $path = (string) ($base['path'] ?? '/');
        if($action[0] === '?') return $origin . $path . $action;
        return $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $action;
    }

    private function publicAddresses(string $host): array {
        if(filter_var($host, FILTER_VALIDATE_IP)) $addresses = [$host];
        else {
            $addresses = [];
            foreach((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                if(isset($record['ip'])) $addresses[] = $record['ip'];
                if(isset($record['ipv6'])) $addresses[] = $record['ipv6'];
            }
        }
        foreach(array_unique($addresses) as $address) {
            if(!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new WireException('The confirmation host resolves to a private or reserved address.');
            }
        }
        return array_values(array_unique($addresses));
    }

    private function safeRedirectHost(string $location, string $baseUrl): string {
        if($location === '') return '';
        if(strpos($location, '//') === 0) $location = 'https:' . $location;
        if(strpos($location, '/') === 0) return (string) parse_url($baseUrl, PHP_URL_HOST);
        return strtolower((string) parse_url($location, PHP_URL_HOST));
    }
}

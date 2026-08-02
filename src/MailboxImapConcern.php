<?php namespace ProcessWire;

/** Read-only IMAP transport, MIME parsing, and safe message DTOs. */
trait MailboxImapConcern {
    public function getDefaultFolder(): string {
        $folder = trim((string) $this->accountSetting('defaultFolder'));
        return $folder !== '' ? $folder : 'INBOX';
    }

    /**
     * Return every mailbox exposed by the configured account.
     *
     * Each item contains name (server-native folder name), label, delimiter,
     * selectable, and attributes.
     */
    public function listFolders(): array {
        if($this->usesWebklexTransport()) {
            return $this->withWebklexTransport(function(MailboxWebklexTransport $transport): array {
                return $transport->listFolders((bool) $this->accountSetting('showUnselectableFolders'));
            });
        }
        return $this->withMailbox($this->getDefaultFolder(), function($connection): array {
            $prefix = $this->serverPrefix();
            $mailboxes = @imap_getmailboxes($connection, $prefix, $this->folderPattern());
            if($mailboxes === false) {
                throw new WireException($this->imapError('Unable to read IMAP folders.'));
            }

            $folders = [];
            foreach($mailboxes as $mailbox) {
                $name = (string) $mailbox->name;
                if(strpos($name, $prefix) === 0) $name = substr($name, strlen($prefix));
                if($name === '') continue;

                $attributes = (int) $mailbox->attributes;
                $noSelect = defined('LATT_NOSELECT') && ($attributes & LATT_NOSELECT);
                if($noSelect && !(int) $this->accountSetting('showUnselectableFolders')) continue;
                $folders[] = [
                    'name' => $name,
                    'label' => $this->decodeFolderName($name),
                    'delimiter' => isset($mailbox->delimiter) ? (string) $mailbox->delimiter : '/',
                    'selectable' => !$noSelect,
                    'attributes' => $attributes,
                ];
            }

            usort($folders, function(array $a, array $b): int {
                $aInbox = strcasecmp($a['name'], 'INBOX') === 0;
                $bInbox = strcasecmp($b['name'], 'INBOX') === 0;
                if($aInbox !== $bInbox) return $aInbox ? -1 : 1;
                return strnatcasecmp($a['label'], $b['label']);
            });

            return $folders;
        });
    }

    /**
     * Return one page of message summaries from a folder, newest first.
     */
    public function listMessages(string $folder = 'INBOX', int $page = 1, ?int $limit = null): array {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit ?: (int) $this->accountSetting('messagesPerPage')));

        if($this->usesWebklexTransport()) {
            return $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder, $page, $limit): array {
                return $transport->listMessages($folder, $page, $limit);
            });
        }

        return $this->withMailbox($folder, function($connection) use ($page, $limit): array {
            $total = (int) imap_num_msg($connection);
            $upper = $total - (($page - 1) * $limit);
            $lower = max(1, $upper - $limit + 1);
            $messages = [];

            if($upper >= 1) {
                $overview = @imap_fetch_overview($connection, $lower . ':' . $upper, 0);
                if($overview === false) {
                    throw new WireException($this->imapError('Unable to read message list.'));
                }

                foreach($overview as $item) {
                    $messageNumber = (int) ($item->msgno ?? 0);
                    if(!$messageNumber) continue;
                    $timestamp = isset($item->udate) ? (int) $item->udate : strtotime((string) ($item->date ?? ''));
                    $messages[] = [
                        'uid' => (int) imap_uid($connection, $messageNumber),
                        'subject' => $this->decodeMimeHeader((string) ($item->subject ?? '(no subject)')),
                        'from' => $this->decodeMimeHeader((string) ($item->from ?? '')),
                        'date' => $timestamp > 0 ? $timestamp : 0,
                        'seen' => !empty($item->seen),
                        'answered' => !empty($item->answered),
                        'flagged' => !empty($item->flagged),
                        'size' => max(0, (int) ($item->size ?? 0)),
                    ];
                }

                usort($messages, function(array $a, array $b): int {
                    return $b['uid'] <=> $a['uid'];
                });
            }

            return [
                'messages' => $messages,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => max(1, (int) ceil($total / $limit)),
            ];
        });
    }

    /** Return one message for trusted local workflows, including bounded HTML/raw views. */
    public function getMessage(string $folder, int $uid): array {
        if($uid < 1) throw new WireException('Invalid message UID.');

        if($this->usesWebklexTransport()) {
            return $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder, $uid): array {
                $message = $transport->getMessage($folder, $uid);
                $plain = $this->normalizeText((string) $message['plain']);
                $html = (string) $message['html'];
                $body = $plain !== '' ? $plain : $this->htmlToText($html);
                return [
                    'uid' => $uid,
                    'message_id' => trim((string) ($message['message_id'] ?? ''), '<>'),
                    'subject' => (string) $message['subject'],
                    'from' => (string) $message['from'],
                    'to' => (string) $message['to'],
                    'cc' => (string) $message['cc'],
                    'date' => (int) $message['date'],
                    'seen' => (bool) ($message['seen'] ?? false),
                    'body' => $this->limitBody($this->normalizeText($body)),
                    'html' => $this->limitSource($html, 'HTML body'),
                    'raw' => null,
                    'attachments' => (array) $message['attachments'],
                    'links' => $this->extractLinks($plain, $html, (string) $message['subject']),
                ];
            });
        }

        return $this->withMailbox($folder, function($connection) use ($uid): array {
            $messageNumber = (int) imap_msgno($connection, $uid);
            if($messageNumber < 1) throw new WireException('The requested message no longer exists.');

            $overviewRows = @imap_fetch_overview($connection, (string) $messageNumber, 0);
            $overview = is_array($overviewRows) && $overviewRows ? reset($overviewRows) : null;
            $messageBytes = max(0, (int) ($overview->size ?? 0));
            if($messageBytes > max((int) $this->accountSetting('maxBodyBytes'), (int) $this->accountSetting('maxAttachmentBytes'))) throw new WireException('Message exceeds the configured safe fetch limit.');
            $structure = @imap_fetchstructure($connection, $messageNumber);
            if(!$structure) throw new WireException($this->imapError('Unable to read message structure.'));

            $content = ['plain' => '', 'html' => '', 'attachments' => []];
            $this->collectMessageParts($connection, $messageNumber, $structure, '', $content);

            $body = $content['plain'];
            if($body === '' && $content['html'] !== '') $body = $this->htmlToText($content['html']);
            if($body === '') {
                $raw = @imap_body($connection, $messageNumber, FT_PEEK);
                if(is_string($raw)) $body = $raw;
            }
            $body = $this->limitBody($this->normalizeText($body));
            $headerSource = @imap_fetchheader($connection, $messageNumber, FT_PREFETCHTEXT);
            $bodySource = @imap_body($connection, $messageNumber, FT_PEEK);
            $raw = is_string($headerSource) && is_string($bodySource) ? $headerSource . $bodySource : null;
            $links = $this->extractLinks(
                $content['plain'],
                $content['html'],
                $this->decodeMimeHeader((string) ($overview->subject ?? ''))
            );

            $timestamp = $overview && isset($overview->udate)
                ? (int) $overview->udate
                : strtotime((string) ($overview->date ?? ''));

            return [
                'uid' => $uid,
                'message_id' => trim((string) ($overview->message_id ?? ''), '<>'),
                'subject' => $this->decodeMimeHeader((string) ($overview->subject ?? '(no subject)')),
                'from' => $this->decodeMimeHeader((string) ($overview->from ?? '')),
                'to' => $this->decodeMimeHeader((string) ($overview->to ?? '')),
                'cc' => $this->decodeMimeHeader((string) ($overview->cc ?? '')),
                'date' => $timestamp > 0 ? $timestamp : 0,
                'seen' => !empty($overview->seen),
                'body' => $body,
                'html' => $this->limitSource((string) $content['html'], 'HTML body'),
                'raw' => is_string($raw) ? $this->limitSource($raw, 'raw source') : null,
                'attachments' => $content['attachments'],
                'links' => $links,
            ];
        });
    }

    /**
     * Agent-safe message DTO. Link targets and raw paths may contain one-time
     * secrets, so agents receive stable hashes instead of executable URLs.
     */
    public function getAgentMessage(string $folder, int $uid): array {
        $message = $this->getMessage($folder, $uid);
        foreach($message['links'] as &$link) {
            $url = (string) ($link['url'] ?? '');
            if($url !== '') $message['body'] = str_replace($url, '[mailbox-link:' . $link['hash'] . ']', $message['body']);
            unset($link['url'], $link['path']);
        }
        unset($link);
        unset($message['html'], $message['raw']);
        return $message;
    }

    public function getAgentLinks(string $folder, int $uid): array {
        return $this->getAgentMessage($folder, $uid)['links'];
    }

    /** Authenticate the saved IMAP transport without enumerating mailbox data. */
    public function testAuthentication(): array {
        $started = microtime(true);
        $folder = $this->getDefaultFolder();
        $result = $this->usesWebklexTransport()
            ? $this->withWebklexTransport(static function(MailboxWebklexTransport $transport): array {
                return ['ok' => true];
            })
            : $this->withMailbox($folder, static function($connection): array {
                return ['ok' => true];
            });

        return $result + [
            'mailbox' => $folder,
            'host' => trim((string) $this->accountSetting('host')),
            'port' => (int) $this->accountSetting('port'),
            'encryption' => (string) $this->accountSetting('encryption'),
            'authentication' => $this->usesOAuthTransport() ? 'oauth' : 'password',
            'imap_transport' => $this->usesWebklexTransport() ? 'webklex' : 'native',
            'elapsed_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
        ];
    }

    public function testConnection(): array {
        $started = microtime(true);
        $folder = $this->getDefaultFolder();
        if($this->usesWebklexTransport()) {
            $result = $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder): array {
                return $transport->test($folder);
            });
            return $result + [
                'host' => trim((string) $this->accountSetting('host')),
                'port' => (int) $this->accountSetting('port'),
                'encryption' => (string) $this->accountSetting('encryption'),
                'certificate_validation' => (bool) $this->accountSetting('validateCertificate'),
                'preset' => (string) $this->accountSetting('preset'),
                'authentication' => $this->usesOAuthTransport() ? 'oauth' : 'password',
                'imap_transport' => 'webklex',
            ];
        }
        return $this->withMailbox($folder, function($connection) use ($started, $folder): array {
            $check = @imap_check($connection);
            $mailboxes = @imap_getmailboxes($connection, $this->serverPrefix(), $this->folderPattern());
            return [
                'ok' => true,
                'messages' => $check ? (int) $check->Nmsgs : (int) imap_num_msg($connection),
                'folders' => is_array($mailboxes) ? count($mailboxes) : 0,
                'mailbox' => $check ? (string) $check->Mailbox : $folder,
                'host' => trim((string) $this->accountSetting('host')),
                'port' => (int) $this->accountSetting('port'),
                'encryption' => (string) $this->accountSetting('encryption'),
                'certificate_validation' => (bool) $this->accountSetting('validateCertificate'),
                'preset' => (string) $this->accountSetting('preset'),
                'elapsed_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            ];
        });
    }

    protected function usesOAuthTransport(): bool {
        return (string) $this->accountSetting('authentication') === 'oauth';
    }

    protected function usesWebklexTransport(): bool {
        if($this->usesOAuthTransport()) return true;
        $selected = (string) $this->accountSetting('imapTransport');
        if($selected === 'webklex') return true;
        if($selected === 'native') return false;
        $preset = (string) $this->accountSetting('preset');
        $host = strtolower(trim((string) $this->accountSetting('host')));
        return in_array($preset, ['gmail', 'microsoft365', 'icloud', 'yahoo', 'fastmail', 'zoho'], true)
            || preg_match('/(?:^|\.)gmail\.com$/', $host) === 1
            || preg_match('/(?:^|\.)googlemail\.com$/', $host) === 1;
    }

    protected function withWebklexTransport(callable $callback) {
        $previousReporting = error_reporting();
        error_reporting($previousReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        $transport = null;
        try {
            $root = (string) ($this->wire()->config->paths->root ?? '');
            if(!MailboxWebklexTransport::load($root, $this->mailboxRuntimeRoot())) {
                throw new WireException('This account requires webklex/php-imap 5.3 or newer. Open Mailbox Runtime and install the locked packages into persistent site assets.');
            }
            $oauth = $this->usesOAuthTransport();
            $credentials = $oauth ? $this->credentials()->get() : $this->storedCredentials();
            if(!$credentials || trim((string) ($credentials['username'] ?? '')) === '') throw new WireException('Mailbox username is not configured.');
            $secret = $oauth ? $this->oauth()->accessToken($this->currentAccountId()) : (string) ($credentials['password'] ?? '');
            if($secret === '') throw new WireException('Mailbox password or OAuth token is not configured.');
            $transport = new MailboxWebklexTransport(
                $this->webklexSettings(),
                (string) $credentials['username'],
                $secret,
                $oauth ? 'oauth' : 'password'
            );
            return $callback($transport);
        } finally {
            if($transport instanceof MailboxWebklexTransport) $transport->close();
            error_reporting($previousReporting);
        }
    }

    protected function withIdleTransport(callable $callback) {
        return $this->withWebklexTransport($callback);
    }

    private function webklexSettings(): array {
        $settings = [];
        foreach($this->accountSettingNames() as $name) $settings[$name] = $this->accountSetting($name);
        return $settings;
    }

    protected function mailboxRuntimeRoot(): string {
        $assets = (string) ($this->wire()->config->paths->assets ?? '');
        if($assets === '') throw new WireException('ProcessWire assets path is unavailable for the Mailbox runtime.');
        return rtrim($assets, '/\\') . '/Mailbox/runtime';
    }

    protected function withMailbox(string $folder, callable $callback) {
        $connection = $this->openMailbox($folder);
        try {
            return $callback($connection);
        } finally {
            @imap_close($connection);
            if(function_exists('imap_errors')) @imap_errors();
        }
    }

    protected function folderUidValidity(string $folder): int {
        if($this->usesWebklexTransport()) {
            return $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder): int { return $transport->uidValidity($folder); });
        }
        if(!defined('SA_UIDVALIDITY')) return 0;
        return $this->withMailbox($folder, function($connection) use ($folder): int {
            $status = @imap_status($connection, $this->serverPrefix(false) . $folder, SA_UIDVALIDITY);
            return $status ? max(0, (int) ($status->uidvalidity ?? 0)) : 0;
        });
    }

    protected function openMailbox(string $folder, bool $readOnly = true) {
        $credentials = $this->storedCredentials();
        $this->assertAvailable($credentials);
        $this->validateFolderName($folder);

        if(defined('IMAP_OPENTIMEOUT')) @imap_timeout(IMAP_OPENTIMEOUT, $this->normalizedTimeout($this->accountSetting('openTimeout')));
        if(defined('IMAP_READTIMEOUT')) @imap_timeout(IMAP_READTIMEOUT, $this->normalizedTimeout($this->accountSetting('readTimeout')));
        if(defined('IMAP_WRITETIMEOUT')) @imap_timeout(IMAP_WRITETIMEOUT, $this->normalizedTimeout($this->accountSetting('writeTimeout')));
        if(defined('IMAP_CLOSETIMEOUT')) @imap_timeout(IMAP_CLOSETIMEOUT, $this->normalizedTimeout($this->accountSetting('closeTimeout')));

        $mailbox = $this->serverPrefix($readOnly) . $folder;
        $flags = $readOnly ? OP_READONLY : 0;
        if((int) $this->accountSetting('secureAuthentication') && defined('OP_SECURE')) $flags |= OP_SECURE;
        $options = [];
        if((string) $this->accountSetting('disableAuthenticator') !== '') {
            $options['DISABLE_AUTHENTICATOR'] = (string) $this->accountSetting('disableAuthenticator');
        }
        $connection = @imap_open(
            $mailbox,
            (string) $credentials['username'],
            (string) $credentials['password'],
            $flags,
            max(1, min(3, (int) $this->accountSetting('connectionRetries'))),
            $options
        );
        if($connection === false) {
            throw new WireException($this->imapError('Unable to connect to the IMAP server.'));
        }
        return $connection;
    }

    protected function serverPrefix(bool $readOnly = true): string {
        $host = $this->normalizedHost();

        $port = max(1, min(65535, (int) $this->accountSetting('port')));
        $encryption = in_array($this->accountSetting('encryption'), ['ssl', 'tls'], true) ? $this->accountSetting('encryption') : 'ssl';
        $flags = '/imap/norsh' . ($readOnly ? '/readonly' : '');
        if(!(int) $this->accountSetting('validateCertificate') && !$this->isLoopbackHost(trim((string) $this->accountSetting('host')))) {
            throw new WireException('TLS certificate validation may only be disabled for a loopback IMAP host.');
        }
        $flags .= '/' . $encryption;
        $flags .= (int) $this->accountSetting('validateCertificate') ? '/validate-cert' : '/novalidate-cert';
        if((int) $this->accountSetting('secureAuthentication')) $flags .= '/secure';

        return '{' . $host . ':' . $port . $flags . '}';
    }

    protected function normalizedHost(): string {
        $host = trim((string) $this->accountSetting('host'));
        if($host === '') throw new WireException('A valid IMAP host is required.');
        if(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return '[' . $host . ']';
        if(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $host;
        if(strlen($host) > 253 || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new WireException('The IMAP host must be a hostname or IP address without protocol or port.');
        }
        foreach(explode('.', $host) as $label) {
            if($label === '' || strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') {
                throw new WireException('The IMAP hostname is invalid.');
            }
        }
        return $host;
    }

    protected function isLoopbackHost(string $host): bool {
        $host = strtolower(trim($host, '[]'));
        if($host === 'localhost' || $host === '::1') return true;
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && strpos($host, '127.') === 0;
    }

    protected function assertAvailable(?array $credentials): void {
        if(!function_exists('imap_open')) throw new WireException('PHP IMAP extension is not installed or enabled.');
        if(!$credentials || trim((string) ($credentials['username'] ?? '')) === '') throw new WireException('IMAP username is not configured.');
        if((string) ($credentials['password'] ?? '') === '') throw new WireException('IMAP password is not configured.');
    }

    protected function folderPattern(): string {
        $pattern = trim((string) $this->accountSetting('folderPattern'));
        if($pattern === '' || preg_match('/[{}\r\n\0]/', $pattern)) throw new WireException('Invalid IMAP folder pattern.');
        return $pattern;
    }

    protected function normalizedTimeout($seconds): int {
        return max(1, min(300, (int) $seconds));
    }

    protected function validateFolderName(string $folder): void {
        if($folder === '' || preg_match('/[{}\r\n\0]/', $folder)) throw new WireException('Invalid IMAP folder name.');
    }

    protected function imapError(string $fallback): string {
        $error = function_exists('imap_last_error') ? imap_last_error() : false;
        return $error ? $fallback . ' ' . $error : $fallback;
    }

    protected function collectMessageParts($connection, int $messageNumber, $part, string $partNumber, array &$content): void {
        if(!empty($part->parts)) {
            foreach($part->parts as $index => $child) {
                $childNumber = $partNumber === '' ? (string) ($index + 1) : $partNumber . '.' . ($index + 1);
                $this->collectMessageParts($connection, $messageNumber, $child, $childNumber, $content);
            }
            return;
        }

        $parameters = $this->partParameters($part);
        $filename = $parameters['filename'] ?? ($parameters['name'] ?? '');
        $disposition = strtolower((string) ($part->disposition ?? ''));
        $isAttachment = $filename !== '' || $disposition === 'attachment';
        if($isAttachment) {
            $content['attachments'][] = [
                'name' => $this->decodeMimeHeader($filename ?: 'attachment'),
                'bytes' => max(0, (int) ($part->bytes ?? 0)),
                'type' => $this->mimeType($part),
                'part' => $partNumber === '' ? '0' : $partNumber,
            ];
            return;
        }

        if((int) ($part->type ?? -1) !== 0) return;
        $subtype = strtolower((string) ($part->subtype ?? 'plain'));
        if(!in_array($subtype, ['plain', 'html'], true)) return;

        $raw = $partNumber === ''
            ? @imap_body($connection, $messageNumber, FT_PEEK)
            : @imap_fetchbody($connection, $messageNumber, $partNumber, FT_PEEK);
        if(!is_string($raw)) return;

        $decoded = $this->decodeTransferEncoding($raw, (int) ($part->encoding ?? 0));
        $decoded = $this->toUtf8($decoded, (string) ($parameters['charset'] ?? ''));
        if($subtype === 'plain' && $content['plain'] === '') $content['plain'] = $decoded;
        if($subtype === 'html' && $content['html'] === '') $content['html'] = $decoded;
    }

    protected function partParameters($part): array {
        $result = [];
        foreach(['parameters', 'dparameters'] as $property) {
            if(empty($part->{$property})) continue;
            foreach($part->{$property} as $parameter) {
                $attribute = strtolower((string) ($parameter->attribute ?? ''));
                if($attribute !== '') $result[$attribute] = (string) ($parameter->value ?? '');
            }
        }
        return $result;
    }

    protected function decodeTransferEncoding(string $value, int $encoding): string {
        if($encoding === 3) {
            $decoded = base64_decode($value, true);
            return $decoded === false ? $value : $decoded;
        }
        if($encoding === 4) return quoted_printable_decode($value);
        return $value;
    }

    protected function mimeType($part): string {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type = $types[(int) ($part->type ?? 7)] ?? 'other';
        $subtype = strtolower((string) ($part->subtype ?? 'octet-stream'));
        return $type . '/' . $subtype;
    }

    protected function decodeMimeHeader(string $value): string {
        if($value === '' || !function_exists('imap_mime_header_decode')) return $value;
        $parts = @imap_mime_header_decode($value);
        if(!is_array($parts)) return $value;

        $decoded = '';
        foreach($parts as $part) {
            $charset = (string) ($part->charset ?? 'default');
            $text = (string) ($part->text ?? '');
            $decoded .= $this->toUtf8($text, $charset);
        }
        return $decoded;
    }

    protected function decodeFolderName(string $value): string {
        if(function_exists('mb_convert_encoding')) {
            $decoded = @mb_convert_encoding($value, 'UTF-8', 'UTF7-IMAP');
            if(is_string($decoded) && $decoded !== '') return $decoded;
        }
        return $value;
    }

    protected function toUtf8(string $value, string $charset): string {
        $charset = trim($charset);
        if($value === '' || $charset === '' || in_array(strtolower($charset), ['default', 'utf-8', 'us-ascii'], true)) return $value;
        if(function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
            if(is_string($converted)) return $converted;
        }
        if(function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $value);
            if(is_string($converted)) return $converted;
        }
        return $value;
    }

    protected function normalizeText(string $value): string {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[ \t]+\n/', "\n", $value);
        $value = preg_replace('/\n{4,}/', "\n\n\n", $value);
        return trim((string) $value);
    }

    protected function limitBody(string $value): string {
        $limit = max(16384, min(10485760, (int) $this->accountSetting('maxBodyBytes')));
        if(strlen($value) <= $limit) return $value;
        $value = function_exists('mb_strcut') ? mb_strcut($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
        return rtrim($value) . "\n\n[Message body truncated by Mailbox]";
    }

    protected function limitSource(string $value, string $label): string {
        $limit = max(16384, min(10485760, (int) $this->accountSetting('maxBodyBytes')));
        if(strlen($value) <= $limit) return $value;
        $value = function_exists('mb_strcut') ? mb_strcut($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
        return rtrim($value) . "\n\n[Mailbox truncated {$label}]";
    }
}

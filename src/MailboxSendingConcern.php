<?php namespace ProcessWire;

/** Explicitly enabled SMTP send, reply, and forward capabilities. */
trait MailboxSendingConcern {

    public function sendMessage(array $message, string $actor = 'backend'): array {
        $this->assertSendingEnabled();
        $normalized = $this->normalizeOutgoingMessage($message);
        try {
            $result = $this->smtpTransport()->send($normalized);
        } catch(\Throwable $error) {
            $this->auditSend('failed', $normalized, $actor, ['error_class' => get_class($error)]);
            if($error instanceof WireException) throw $error;
            throw new WireException('SMTP delivery failed.', 0, $error);
        }
        $mime = (string) ($result['_mime'] ?? '');
        unset($result['_mime']);
        $result['sent_copy'] = 'disabled';
        if((int) $this->accountSetting('saveSentCopies')) {
            try {
                $this->appendSentCopy($mime);
                $result['sent_copy'] = 'saved';
            } catch(\Throwable $error) {
                $result['sent_copy'] = 'failed';
                $this->auditSend('sent_copy_failed', $normalized, $actor, ['error_class' => get_class($error)]);
            }
        }
        $this->auditSend('sent', $normalized, $actor, ['message_id_hash' => hash('sha256', (string) $result['message_id'])]);
        return $result;
    }

    public function testSmtpConnection(): array {
        $this->assertSmtpConfigured();
        $started = microtime(true);
        $result = $this->smtpTransport()->test();
        return $result + [
            'host' => (string) $this->accountSetting('smtpHost'),
            'port' => (int) $this->accountSetting('smtpPort'),
            'encryption' => (string) $this->accountSetting('smtpEncryption'),
            'certificate_validation' => (bool) $this->accountSetting('smtpValidateCertificate'),
            'elapsed_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
        ];
    }

    public function replyMessage(string $folder, int $uid, string $body, bool $replyAll = false, string $actor = 'backend', array $attachments = []): array {
        $this->assertSendingEnabled();
        $original = $this->getMessage($folder, $uid);
        $to = $this->firstParsedAddress((string) $original['from']);
        if(!$to) throw new WireException('The original sender address is unavailable.');
        $cc = [];
        if($replyAll) {
            $cc = array_merge(
                MailboxSmtpTransport::parseAddresses((string) ($original['to'] ?? '')),
                MailboxSmtpTransport::parseAddresses((string) ($original['cc'] ?? ''))
            );
            $cc = $this->withoutAddresses($cc, [$to['email'], $this->outgoingFrom()['email']]);
        }
        return $this->sendMessage([
            'to' => [$to],
            'cc' => $cc,
            'subject' => $this->prefixedSubject((string) $original['subject'], 'Re:'),
            'body' => $body,
            'in_reply_to' => (string) ($original['message_id'] ?? ''),
            'attachments' => $attachments,
        ], $actor);
    }

    public function forwardMessage(string $folder, int $uid, array $to, string $body = '', string $actor = 'backend', array $attachments = []): array {
        $this->assertSendingEnabled();
        $original = $this->getMessage($folder, $uid);
        $quotedHeader = "\n\n---------- Forwarded message ----------\n"
            . 'From: ' . (string) $original['from'] . "\n"
            . 'Date: ' . ((int) $original['date'] > 0 ? gmdate('c', (int) $original['date']) : '') . "\n"
            . 'Subject: ' . (string) $original['subject'] . "\n"
            . 'To: ' . (string) $original['to'] . "\n\n";
        return $this->sendMessage([
            'to' => $to,
            'subject' => $this->prefixedSubject((string) $original['subject'], 'Fwd:'),
            'body' => $this->boundedForwardText(rtrim($body), $quotedHeader, (string) $original['body']),
            'attachments' => $attachments,
        ], $actor);
    }

    protected function outgoingSettings(): array {
        $settings = [];
        foreach(['smtpHost', 'smtpPort', 'smtpEncryption', 'smtpValidateCertificate', 'writeTimeout'] as $name) $settings[$name] = $this->accountSetting($name);
        if(filter_var($settings['smtpHost'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $settings['smtpHost'] = '[' . $settings['smtpHost'] . ']';
        return $settings;
    }

    protected function smtpTransport(): MailboxSmtpTransport {
        $root = (string) ($this->wire()->config->paths->root ?? '');
        if(!MailboxSmtpTransport::load($root, $this->mailboxRuntimeRoot())) throw new WireException('SMTP requires phpmailer/phpmailer 6.12 or newer. Install the locked Mailbox Runtime packages.');
        $credentials = $this->storedCredentials(false);
        if(!$credentials) throw new WireException('Mailbox credentials are not configured.');
        $oauth = (string) $this->accountSetting('authentication') === 'oauth';
        $secret = $oauth
            ? $this->oauth()->accessToken($this->currentAccountId())
            : $this->resolveCredentialPassword((string) $credentials['password']);
        return new MailboxSmtpTransport($this->outgoingSettings(), (string) $credentials['username'], $secret, $oauth);
    }

    protected function normalizeOutgoingMessage(array $message): array {
        if(isset($message['subject']) && !is_string($message['subject'])) throw new WireException('Subject must be a string.');
        if(isset($message['body']) && !is_string($message['body'])) throw new WireException('Plain-text body must be a string.');
        if(isset($message['in_reply_to']) && !is_string($message['in_reply_to'])) throw new WireException('In-Reply-To must be a string.');
        $subject = trim((string) ($message['subject'] ?? ''));
        $body = str_replace(["\r\n", "\r", "\x0B", "\x0C", "\xC2\x85", "\xE2\x80\xA8", "\xE2\x80\xA9"], "\n", (string) ($message['body'] ?? ''));
        if($subject === '' || strlen($subject) > 512 || preg_match('/[\r\n\0]/', $subject)) throw new WireException('Subject is required and must not contain control characters or exceed 512 bytes.');
        if($body === '' || strlen($body) > 1048576 || strpos($body, "\0") !== false) throw new WireException('Plain-text body is required and must not exceed 1 MiB.');
        $to = $this->normalizeAddressList($message['to'] ?? []);
        $cc = $this->normalizeAddressList($message['cc'] ?? []);
        $bcc = $this->normalizeAddressList($message['bcc'] ?? []);
        $replyTo = $this->normalizeAddressList($message['reply_to'] ?? []);
        $attachments = $this->normalizeOutgoingAttachments($message['attachments'] ?? []);
        if(!$to) throw new WireException('At least one To recipient is required.');
        if(count($to) + count($cc) + count($bcc) > 50) throw new WireException('A message may have at most 50 recipients.');
        $messageId = trim((string) ($message['in_reply_to'] ?? ''), " <>\t");
        if($messageId !== '' && (strlen($messageId) > 998 || !preg_match('/^[^\s<>@]+@[^\s<>@]+$/', $messageId))) throw new WireException('Invalid In-Reply-To message ID.');
        return [
            'from' => $this->outgoingFrom(),
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'reply_to' => $replyTo,
            'subject' => $subject,
            'body' => $body,
            'in_reply_to' => $messageId,
            'attachments' => $attachments,
        ];
    }

    protected function normalizeOutgoingAttachments($value): array {
        if(!is_array($value)) throw new WireException('Outgoing attachments must be an array.');
        if(count($value) > 20) throw new WireException('A message may have at most 20 attachments.');
        $result = [];
        $total = 0;
        foreach($value as $item) {
            if(!is_array($item)) throw new WireException('Invalid outgoing attachment.');
            $name = trim((string) ($item['name'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? 'application/octet-stream')));
            $hasRaw = isset($item['content']) && is_string($item['content']);
            $hasBase64 = isset($item['content_base64']) && is_string($item['content_base64']);
            if($hasRaw === $hasBase64) throw new WireException('Each outgoing attachment requires exactly one content or content_base64 value.');
            $content = $hasRaw ? (string) $item['content'] : base64_decode((string) $item['content_base64'], true);
            if(!is_string($content)) throw new WireException('Outgoing attachment base64 is invalid.');
            if($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name)) throw new WireException('Outgoing attachment name is invalid.');
            if(strlen($type) > 127 || !preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/', $type)) throw new WireException('Outgoing attachment MIME type is invalid.');
            $bytes = strlen($content);
            if($bytes < 1 || $bytes > 10485760) throw new WireException('Each outgoing attachment must be between 1 byte and 10 MiB.');
            $total += $bytes;
            if($total > 10485760) throw new WireException('Total outgoing attachment content must not exceed 10 MiB.');
            $result[] = ['name' => $name, 'type' => $type, 'bytes' => $bytes, 'content' => $content];
        }
        return $result;
    }

    protected function normalizeAddressList($value): array {
        if(is_string($value)) $value = [['email' => $value]];
        if(!is_array($value)) throw new WireException('Recipient lists must be arrays.');
        $result = [];
        foreach($value as $item) {
            if(is_string($item)) $item = ['email' => $item];
            if(!is_array($item)) throw new WireException('Invalid recipient.');
            $email = trim((string) ($item['email'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if(!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320 || preg_match('/[\r\n\0]/', $email)) throw new WireException('Invalid recipient email address.');
            if(strlen($name) > 190 || preg_match('/[\r\n\0]/', $name)) throw new WireException('Invalid recipient display name.');
            $key = strtolower($email);
            if(!isset($result[$key])) $result[$key] = ['email' => $email, 'name' => $name];
        }
        return array_values($result);
    }

    protected function boundedForwardText(string $note, string $header, string $original): string {
        $marker = "\n\n[Forwarded message body truncated by Mailbox]";
        $available = 1048576 - strlen($note) - strlen($header);
        if($available < 1) throw new WireException('Forward note and headers exceed the 1 MiB message limit.');
        if(strlen($original) <= $available) return $note . $header . $original;
        $available -= strlen($marker);
        if($available < 1) throw new WireException('Forward headers exceed the 1 MiB message limit.');
        $original = function_exists('mb_strcut') ? mb_strcut($original, 0, $available, 'UTF-8') : substr($original, 0, $available);
        return $note . $header . rtrim($original) . $marker;
    }

    protected function outgoingFrom(): array {
        $credentials = $this->storedCredentials(false);
        $fallback = $credentials ? trim((string) $credentials['username']) : '';
        $email = trim((string) $this->accountSetting('smtpFromAddress')) ?: $fallback;
        $name = trim((string) $this->accountSetting('smtpFromName'));
        $list = $this->normalizeAddressList([['email' => $email, 'name' => $name]]);
        return $list[0];
    }

    protected function appendSentCopy(string $mime): void {
        if($mime === '' || strlen($mime) > 20971520) throw new WireException('Sent message MIME is unavailable or too large to append.');
        $folder = trim((string) $this->accountSetting('sentFolder')) ?: 'Sent';
        $this->validateFolderName($folder);
        $this->assertSelectableFolder($folder);
        if($this->usesWebklexTransport()) {
            $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder, $mime): void { $transport->appendMessage($folder, $mime); });
            return;
        }
        $this->withWritableMailbox($folder, function($connection) use ($folder, $mime): void {
            if(!@imap_append($connection, $this->serverPrefix(false) . $folder, $mime, '\\Seen', gmdate('d-M-Y H:i:s O'))) throw new WireException($this->imapError('Unable to append the SMTP message to the Sent folder.'));
        });
    }

    private function firstParsedAddress(string $value): ?array {
        $root = (string) ($this->wire()->config->paths->root ?? '');
        if(!MailboxSmtpTransport::load($root, $this->mailboxRuntimeRoot())) throw new WireException('Reply parsing requires phpmailer/phpmailer 6.12 or newer.');
        $addresses = MailboxSmtpTransport::parseAddresses($value);
        return $addresses[0] ?? null;
    }

    private function withoutAddresses(array $addresses, array $excluded): array {
        $excluded = array_map('strtolower', $excluded);
        $result = [];
        foreach($addresses as $address) {
            if(!in_array(strtolower((string) $address['email']), $excluded, true)) $result[] = $address;
        }
        return $this->normalizeAddressList($result);
    }

    private function prefixedSubject(string $subject, string $prefix): string {
        $subject = trim($subject);
        if(stripos($subject, $prefix) === 0) return $subject;
        return $prefix . ' ' . ($subject !== '' ? $subject : '(no subject)');
    }

    private function assertSendingEnabled(): void {
        if(!(int) $this->enableMailSending) throw new WirePermissionException('Mailbox sending capability is disabled.');
        $this->assertSmtpConfigured();
    }

    private function assertSmtpConfigured(): void {
        $host = $this->validateSmtpHostValue((string) $this->accountSetting('smtpHost'));
        if(!in_array((string) $this->accountSetting('smtpEncryption'), ['ssl', 'tls'], true)) throw new WireException('SMTP must use implicit TLS or STARTTLS.');
        if(!(int) $this->accountSetting('smtpValidateCertificate') && !$this->isLoopbackHost($host)) throw new WireException('SMTP certificate validation may only be disabled for a loopback host.');
    }

    protected function validateSmtpHostValue(string $value, bool $allowEmpty = false): string {
        $host = trim($value, " \t\r\n[]");
        if($host === '' && $allowEmpty) return '';
        if($host === '' || strlen($host) > 253) throw new WireException('A valid SMTP hostname is required.');
        if(filter_var($host, FILTER_VALIDATE_IP)) return $host;
        if(!preg_match('/^[A-Za-z0-9.-]+$/', $host)) throw new WireException('A valid SMTP hostname is required.');
        foreach(explode('.', $host) as $label) {
            if($label === '' || strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') throw new WireException('A valid SMTP hostname is required.');
        }
        return $host;
    }

    private function auditSend(string $event, array $message, string $actor, array $metadata = []): void {
        $actor = preg_match('/^(user:\d+|cli:[A-Za-z0-9:._-]+|backend)$/', $actor) ? $actor : 'backend';
        $recipientHashes = [];
        foreach(array_merge($message['to'], $message['cc'], $message['bcc']) as $recipient) $recipientHashes[] = $this->auditAddressHash((string) $recipient['email']);
        $this->wire()->log->save('mailbox-actions', json_encode([
            'event' => 'smtp_' . $event,
            'account_id' => $this->currentAccountId(),
            'recipient_hashes' => $recipientHashes,
            'recipient_count' => count($recipientHashes),
            'actor' => $actor,
            'metadata' => $metadata,
            'time' => time(),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function auditAddressHash(string $email): string {
        $config = $this->wire()->config;
        $material = (string) (($config->mailboxAuditSecret ?? '') ?: (($config->mailboxSecret ?? '') ?: (($config->tableSalt ?? '') ?: ($config->userAuthSalt ?? ''))));
        if($material === '') throw new WireException('Mailbox audit hashing requires a stable ProcessWire or Mailbox secret.');
        $key = hash('sha256', 'MailboxAudit.v1|' . $material, true);
        return hash_hmac('sha256', strtolower(trim($email)), $key);
    }
}

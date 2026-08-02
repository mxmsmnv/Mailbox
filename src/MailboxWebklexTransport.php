<?php namespace ProcessWire;

/** OAuth-capable IMAP transport backed by webklex/php-imap >=5.3. */
final class MailboxWebklexTransport {

    private $client;
    private $maxMessageBytes;
    private $maxAttachmentBytes;

    public static function load(?string $siteRoot = null, ?string $runtimeRoot = null): bool {
        if(class_exists('Webklex\\PHPIMAP\\ClientManager')) return self::assertSupportedVersion();
        $candidates = [];
        if($runtimeRoot) $candidates[] = rtrim($runtimeRoot, '/\\') . '/vendor/autoload.php';
        $candidates[] = dirname(__DIR__) . '/vendor/autoload.php';
        if($siteRoot) $candidates[] = rtrim($siteRoot, '/\\') . '/vendor/autoload.php';
        foreach($candidates as $autoload) {
            if(is_file($autoload)) require_once $autoload;
            if(class_exists('Webklex\\PHPIMAP\\ClientManager')) return self::assertSupportedVersion();
        }
        return false;
    }

    private static function assertSupportedVersion(): bool {
        $installed = 'Composer\\InstalledVersions';
        if(!class_exists($installed) || !$installed::isInstalled('webklex/php-imap')) {
            throw new WireException('Unable to verify the installed webklex/php-imap version. Install version 5.3 or newer with Composer.');
        }
        $version = (string) ($installed::getVersion('webklex/php-imap') ?: '0');
        if(version_compare($version, '5.3.0.0', '<')) {
            throw new WireException('OAuth IMAP requires webklex/php-imap 5.3 or newer. Older releases contain a known security vulnerability.');
        }
        return true;
    }

    public function __construct(array $settings, string $username, string $secret, string $authentication = 'oauth') {
        if(!class_exists('Webklex\\PHPIMAP\\ClientManager')) {
            throw new WireException('This IMAP transport requires webklex/php-imap 5.3 or newer. Install it with Composer.');
        }
        $managerClass = 'Webklex\\PHPIMAP\\ClientManager';
        $manager = new $managerClass([]);
        $this->client = $manager->make([
            'host' => (string) $settings['host'],
            'port' => (int) $settings['port'],
            'encryption' => (string) $settings['encryption'],
            'validate_cert' => (bool) $settings['validateCertificate'],
            'protocol' => 'imap',
            'username' => $username,
            'password' => $secret,
            'authentication' => $authentication === 'oauth' ? 'oauth' : null,
            'timeout' => max(1, min(300, (int) ($settings['readTimeout'] ?? 30))),
        ]);
        $this->maxMessageBytes = max(16384, min(10485760, (int) ($settings['maxBodyBytes'] ?? 1048576)));
        $this->maxAttachmentBytes = max(65536, min(52428800, (int) ($settings['maxAttachmentBytes'] ?? 10485760)));
        $this->client->connect();
    }

    public function close(): void {
        if($this->client && method_exists($this->client, 'disconnect')) {
            try { $this->client->disconnect(); } catch(\Throwable $error) {}
        }
    }

    public function listFolders(bool $includeUnselectable = true): array {
        $result = [];
        foreach($this->client->getFolders(false) as $folder) {
            if(!empty($folder->no_select) && !$includeUnselectable) continue;
            $result[] = [
                'name' => (string) $folder->path,
                'label' => (string) $folder->full_name,
                'delimiter' => (string) $folder->delimiter,
                'selectable' => empty($folder->no_select),
                'attributes' => 0,
            ];
        }
        usort($result, static function(array $a, array $b): int {
            $aInbox = strcasecmp($a['name'], 'INBOX') === 0;
            $bInbox = strcasecmp($b['name'], 'INBOX') === 0;
            return $aInbox !== $bInbox ? ($aInbox ? -1 : 1) : strnatcasecmp($a['label'], $b['label']);
        });
        return $result;
    }

    public function listMessages(string $folderName, int $page, int $limit): array {
        $folder = $this->folder($folderName);
        $status = $folder->getStatus();
        $total = max(0, (int) ($status['exists'] ?? ($status['messages'] ?? 0)));
        $query = $folder->messages()->all()->leaveUnread()->fetchBody(false)->fetchOrderDesc()->limit($limit, $page);
        $messages = [];
        foreach($query->get() as $message) $messages[] = $this->summary($message);
        usort($messages, static function(array $a, array $b): int { return $b['uid'] <=> $a['uid']; });
        return [
            'folder' => $folderName,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / max(1, $limit))),
            'messages' => $messages,
        ];
    }

    public function getMessage(string $folderName, int $uid): array {
        $folder = $this->folder($folderName);
        $header = $folder->messages()->leaveUnread()->fetchBody(false)->getMessageByUid($uid);
        if((int) $header->getSize() > max($this->maxMessageBytes, $this->maxAttachmentBytes)) {
            throw new WireException('OAuth message exceeds the configured safe fetch limit.');
        }
        $message = $folder->messages()->leaveUnread()->fetchBody(true)->getMessageByUid($uid);
        $attachments = [];
        foreach($message->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => (string) ($attachment->getName() ?: 'attachment'),
                'type' => (string) ($attachment->getMimeType() ?: $attachment->getContentType() ?: 'application/octet-stream'),
                'bytes' => max(0, (int) $attachment->getSize()),
                'part' => (string) $attachment->getPartNumber(),
            ];
        }
        $summary = $this->summary($message);
        return $summary + [
            'to' => $this->addressText($message->getTo()),
            'cc' => $this->addressText($message->getCc()),
            'plain' => (string) $message->getTextBody(),
            'html' => (string) $message->getHTMLBody(),
            'attachments' => $attachments,
        ];
    }

    public function search(string $folderName, array $filters, int $limit): array {
        $folder = $this->folder($folderName);
        $query = $folder->messages()->leaveUnread()->fetchOrderDesc();
        $query->fetchBody(false);
        if(isset($filters['text'])) $query->whereText($filters['text']);
        if(isset($filters['from'])) $query->whereFrom($filters['from']);
        if(isset($filters['to'])) $query->whereTo($filters['to']);
        if(isset($filters['subject'])) $query->whereSubject($filters['subject']);
        if(isset($filters['since'])) $query->whereSince($filters['since']);
        if(isset($filters['before'])) $query->whereBefore($filters['before']);
        if(($filters['state'] ?? '') === 'seen') $query->whereSeen();
        if(($filters['state'] ?? '') === 'unseen') $query->whereUnseen();
        if(($filters['flagged'] ?? '') === 'yes') $query->where('FLAGGED');
        if(($filters['flagged'] ?? '') === 'no') $query->whereUnflagged();
        if(($filters['answered'] ?? '') === 'yes') $query->whereAnswered();
        if(($filters['answered'] ?? '') === 'no') $query->whereUnanswered();
        if(isset($filters['min_bytes'])) $query->where('LARGER', (int) $filters['min_bytes']);
        if(isset($filters['max_bytes'])) $query->where('SMALLER', (int) $filters['max_bytes']);
        $matched = $query->count();
        $messages = [];
        $candidateLimit = isset($filters['has_attachment']) ? min($limit, 250) : $limit;
        $attachmentBudget = $this->maxAttachmentBytes;
        $truncated = $matched > $candidateLimit;
        foreach($query->limit($candidateLimit, 1)->get() as $message) {
            if(isset($filters['has_attachment'])) {
                $size = (int) $message->getSize();
                if($size < 1) { $truncated = true; break; }
                if($size > $attachmentBudget) { $truncated = true; break; }
                $attachmentBudget -= $size;
                $full = $folder->messages()->leaveUnread()->fetchBody(true)->getMessageByUid((int) $message->getUid());
                $has = $full->hasAttachments();
                if(($filters['has_attachment'] === 'yes') !== $has) continue;
            }
            $messages[] = $this->summary($message) + ['folder' => $folderName];
        }
        return ['messages' => $messages, 'matched' => $matched, 'truncated' => $truncated];
    }

    public function getAttachment(string $folderName, int $uid, string $part): array {
        $folder = $this->folder($folderName);
        $header = $folder->messages()->leaveUnread()->fetchBody(false)->getMessageByUid($uid);
        if((int) $header->getSize() > $this->maxAttachmentBytes) throw new WireException('OAuth message exceeds the configured attachment fetch limit.');
        $message = $folder->messages()->leaveUnread()->fetchBody(true)->getMessageByUid($uid);
        foreach($message->getAttachments() as $attachment) {
            if((string) $attachment->getPartNumber() !== $part) continue;
            $content = (string) $attachment->getContent();
            if(strlen($content) > $this->maxAttachmentBytes) throw new WireException('Attachment exceeds the configured size limit.');
            return [
                'part' => $part,
                'name' => (string) ($attachment->getName() ?: 'attachment'),
                'type' => (string) ($attachment->getMimeType() ?: $attachment->getContentType() ?: 'application/octet-stream'),
                'bytes' => strlen($content),
                'content' => $content,
            ];
        }
        throw new WireException('The selected OAuth attachment is unavailable.');
    }

    public function test(string $defaultFolder): array {
        $started = microtime(true);
        $folders = $this->listFolders(true);
        $folder = $this->folder($defaultFolder);
        return [
            'ok' => true,
            'messages' => (int) $folder->messages()->all()->count(),
            'folders' => count($folders),
            'mailbox' => $defaultFolder,
            'elapsed_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
        ];
    }

    /** Block on IMAP IDLE; maxEvents=0 runs until the process is stopped. */
    public function idle(string $folderName, callable $callback, int $maxEvents = 0): int {
        $count = 0;
        try {
            $this->folder($folderName)->idle(function($message) use ($callback, $maxEvents, &$count): void {
                $count++;
                $callback($this->summary($message));
                if($maxEvents > 0 && $count >= $maxEvents) throw new MailboxIdleComplete();
            });
        } catch(MailboxIdleComplete $complete) {}
        return $count;
    }

    public function uidValidity(string $folderName): int {
        $status = $this->folder($folderName)->getStatus();
        return max(0, (int) ($status['uidvalidity'] ?? ($status['uid_validity'] ?? 0)));
    }

    public function setFlags(string $folderName, int $uid, array $flags, bool $enabled): array {
        $message = $this->message($folderName, $uid);
        $ok = $enabled ? $message->setFlag($flags) : $message->unsetFlag($flags);
        if(!$ok) throw new WireException('Unable to update OAuth message flags.');
        return ['uid' => $uid, 'flags' => array_values($flags), 'enabled' => $enabled];
    }

    public function move(string $folderName, int $uid, string $destination, bool $expunge): array {
        $moved = $this->message($folderName, $uid)->move($destination, $expunge);
        if(!$moved) throw new WireException('Unable to move the OAuth message.');
        return ['uid' => $uid, 'moved' => true, 'destination' => $destination, 'expunged' => $expunge];
    }

    public function delete(string $folderName, int $uid, bool $expunge): array {
        if(!$this->message($folderName, $uid)->delete($expunge)) throw new WireException('Unable to delete the OAuth message.');
        return ['uid' => $uid, 'deleted' => true, 'expunged' => $expunge];
    }

    public function appendMessage(string $folderName, string $mime): void {
        if($mime === '') throw new WireException('Sent message MIME is unavailable.');
        $this->folder($folderName)->appendMessage($mime, ['\\Seen'], gmdate('d-M-Y H:i:s O'));
    }

    private function folder(string $name) {
        $folder = $this->client->getFolderByPath($name, false, true);
        if(!$folder) throw new WireException('The selected IMAP folder is unavailable.');
        return $folder;
    }

    private function message(string $folderName, int $uid) {
        $message = $this->folder($folderName)->messages()->leaveUnread()->fetchBody(false)->getMessageByUid($uid);
        if(!$message) throw new WireException('The selected message is unavailable.');
        return $message;
    }

    private function summary($message): array {
        $flags = [];
        foreach($message->getFlags() as $flag) $flags[] = strtolower(trim((string) $flag, '\\'));
        $date = $message->getDate();
        $timestamp = 0;
        try {
            $value = $date && method_exists($date, 'toDate') ? $date->toDate() : null;
            if($value instanceof \DateTimeInterface) $timestamp = $value->getTimestamp();
        } catch(\Throwable $error) {}
        return [
            'uid' => (int) $message->getUid(),
            'message_id' => trim((string) $message->getMessageId(), '<>'),
            'subject' => $this->decodeHeader((string) $message->getSubject()) ?: '(No subject)',
            'from' => $this->addressText($message->getFrom()),
            'date' => $timestamp,
            'seen' => in_array('seen', $flags, true),
            'answered' => in_array('answered', $flags, true),
            'flagged' => in_array('flagged', $flags, true),
            'deleted' => in_array('deleted', $flags, true),
            'draft' => in_array('draft', $flags, true),
            'size' => max(0, (int) $message->getSize()),
        ];
    }

    private function addresses($attribute): array {
        $result = [];
        if(!$attribute) return $result;

        if(is_object($attribute) && method_exists($attribute, 'toArray')) {
            $items = $attribute->toArray();
        } elseif(is_array($attribute)) {
            $items = $attribute;
        } elseif($attribute instanceof \Traversable) {
            $items = iterator_to_array($attribute, false);
        } else {
            $items = [$attribute];
        }
        if(!is_array($items)) $items = [$items];
        if(array_key_exists('mail', $items) || array_key_exists('mailbox', $items) || array_key_exists('host', $items)) $items = [$items];

        $value = static function($address, string $name): string {
            if(is_array($address)) return trim((string) ($address[$name] ?? ''));
            if(is_object($address) && (isset($address->{$name}) || property_exists($address, $name))) return trim((string) $address->{$name});
            return '';
        };
        foreach($items as $address) {
            $mail = $value($address, 'mail');
            $mailbox = $value($address, 'mailbox');
            $host = $value($address, 'host');
            if($mail === '' && $mailbox !== '' && $host !== '') $mail = $mailbox . '@' . $host;
            $full = $value($address, 'full');
            if($mail === '' && preg_match('/<([^<>]+)>\s*$/', $full, $match)) $mail = trim($match[1]);
            if($mail === '' && filter_var($full, FILTER_VALIDATE_EMAIL)) $mail = $full;
            if($mail === '' && is_string($address)) $mail = trim($address);
            if($mail === '') continue;
            $result[] = ['name' => $this->decodeHeader($value($address, 'personal')), 'email' => $mail];
        }
        return $result;
    }

    private function addressText($attribute): string {
        $values = [];
        foreach($this->addresses($attribute) as $address) {
            $values[] = $address['name'] !== '' ? $address['name'] . ' <' . $address['email'] . '>' : $address['email'];
        }
        return implode(', ', $values);
    }

    private function decodeHeader(string $value): string {
        $value = trim($value);
        if($value === '') return '';
        if(function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if(is_string($decoded) && trim($decoded) !== '') return trim($decoded);
        }
        if(function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
            if(is_string($decoded) && trim($decoded) !== '') return trim($decoded);
        }
        return $value;
    }
}

final class MailboxIdleComplete extends \RuntimeException {}

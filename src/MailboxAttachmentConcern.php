<?php namespace ProcessWire;

/** Bounded attachment retrieval and text extraction. */
trait MailboxAttachmentConcern {

    public function getAttachment(string $folder, int $uid, string $part, string $actor = 'backend'): array {
        $attachment = $this->fetchAttachment($folder, $uid, $part);
        $this->auditAttachment('downloaded', $folder, $uid, $attachment, $actor);
        return $attachment;
    }

    protected function fetchAttachment(string $folder, int $uid, string $part): array {
        if($uid < 1) throw new WireException('Invalid message UID.');
        $this->validateFolderName($folder);
        $part = $this->normalizeAttachmentPart($part);
        if($this->usesWebklexTransport()) {
            return $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder, $uid, $part): array {
                return $this->normalizeAttachmentResult($transport->getAttachment($folder, $uid, $part));
            });
        }
        return $this->withMailbox($folder, function($connection) use ($uid, $part): array {
            $maximum = $this->maximumAttachmentBytes();
            $overviewRows = @imap_fetch_overview($connection, (string) $uid, FT_UID);
            $overview = is_array($overviewRows) && $overviewRows ? reset($overviewRows) : null;
            $wireBytes = max(0, (int) ($overview->size ?? 0));
            if($wireBytes > (int) ceil($maximum * 1.5)) throw new WireException('Message exceeds the configured attachment fetch limit.');
            $structure = @imap_fetchstructure($connection, $uid, FT_UID);
            if(!$structure) throw new WireException($this->imapError('Unable to read attachment structure.'));
            $mimePart = $this->findAttachmentPart($structure, $part);
            if(!$mimePart) throw new WireException('The selected attachment is unavailable.');
            $parameters = $this->partParameters($mimePart);
            $filename = (string) ($parameters['filename'] ?? ($parameters['name'] ?? ''));
            $disposition = strtolower((string) ($mimePart->disposition ?? ''));
            if($filename === '' && $disposition !== 'attachment') throw new WireException('The selected MIME part is not an attachment.');
            $declared = max(0, (int) ($mimePart->bytes ?? 0));
            if($declared > (int) ceil($maximum * 1.5)) throw new WireException('Attachment exceeds the configured size limit.');
            $raw = $part === '0'
                ? @imap_body($connection, $uid, FT_UID | FT_PEEK)
                : @imap_fetchbody($connection, $uid, $part, FT_UID | FT_PEEK);
            if(!is_string($raw)) throw new WireException($this->imapError('Unable to fetch attachment.'));
            $content = $this->decodeTransferEncoding($raw, (int) ($mimePart->encoding ?? 0));
            return $this->normalizeAttachmentResult([
                'part' => $part,
                'name' => $this->decodeMimeHeader($filename ?: 'attachment'),
                'type' => $this->mimeType($mimePart),
                'bytes' => strlen($content),
                'content' => $content,
            ]);
        });
    }

    public function readAttachmentText(string $folder, int $uid, string $part, string $actor = 'backend'): array {
        $attachment = $this->fetchAttachment($folder, $uid, $part);
        if(!$this->isTextAttachmentType($attachment['type'])) throw new WireException('Only text, JSON, XML, and CSV attachments may be read as text.');
        $limit = max(4096, min($this->maximumAttachmentBytes(), (int) $this->accountSetting('maxAgentAttachmentBytes')));
        if($attachment['bytes'] > $limit) throw new WireException('Attachment exceeds the configured agent text limit.');
        $text = (string) $attachment['content'];
        if(function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = @mb_detect_encoding($text, ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252', 'ISO-8859-1'], true);
            if(is_string($encoding) && strtoupper($encoding) !== 'UTF-8') $text = (string) @mb_convert_encoding($text, 'UTF-8', $encoding);
        }
        if(strpos($text, "\0") !== false) throw new WireException('Attachment content appears to be binary, despite its declared MIME type.');
        $text = $this->normalizeText($text);
        $links = $this->extractLinks($text, '', '');
        foreach($links as &$link) {
            $url = (string) ($link['url'] ?? '');
            if($url !== '') $text = str_replace($url, '[mailbox-attachment-link:' . $link['hash'] . ']', $text);
            unset($link['url'], $link['path']);
        }
        unset($link);
        $sha256 = hash('sha256', (string) $attachment['content']);
        unset($attachment['content']);
        $this->auditAttachment('text_read', $folder, $uid, $attachment, $actor);
        return $attachment + ['text' => $text, 'links' => $links, 'sha256' => $sha256];
    }

    protected function normalizeAttachmentPart(string $part): string {
        $part = trim($part);
        if(!preg_match('/^(?:0|[1-9][0-9]*(?:\.[1-9][0-9]*)*)$/', $part) || strlen($part) > 64) throw new WireException('Invalid attachment part identifier.');
        return $part;
    }

    protected function maximumAttachmentBytes(): int {
        return max(65536, min(52428800, (int) $this->accountSetting('maxAttachmentBytes')));
    }

    private function findAttachmentPart($part, string $target, string $prefix = '') {
        $current = $prefix === '' ? '0' : $prefix;
        if($current === $target) return $part;
        foreach((array) ($part->parts ?? []) as $index => $child) {
            $number = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);
            $found = $this->findAttachmentPart($child, $target, $number);
            if($found) return $found;
        }
        return null;
    }

    private function normalizeAttachmentResult(array $attachment): array {
        $content = (string) ($attachment['content'] ?? '');
        if(strlen($content) > $this->maximumAttachmentBytes()) throw new WireException('Attachment exceeds the configured size limit.');
        $name = str_replace('\\', '/', (string) ($attachment['name'] ?? 'attachment'));
        $name = basename($name);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '_', $name), " .\t");
        if($name === '') $name = 'attachment-' . (string) ($attachment['part'] ?? 'file');
        if(strlen($name) > 255) $name = function_exists('mb_strcut') ? mb_strcut($name, 0, 255, 'UTF-8') : substr($name, 0, 255);
        $type = strtolower(trim((string) ($attachment['type'] ?? 'application/octet-stream')));
        if(!preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $type)) $type = 'application/octet-stream';
        return [
            'part' => $this->normalizeAttachmentPart((string) ($attachment['part'] ?? '0')),
            'name' => $name,
            'type' => $type,
            'bytes' => strlen($content),
            'content' => $content,
        ];
    }

    private function isTextAttachmentType(string $type): bool {
        return strpos($type, 'text/') === 0 || in_array($type, [
            'application/json', 'application/ld+json', 'application/xml', 'application/xhtml+xml',
            'application/csv', 'application/yaml', 'application/x-yaml',
        ], true) || substr($type, -5) === '+json' || substr($type, -4) === '+xml';
    }

    private function auditAttachment(string $event, string $folder, int $uid, array $attachment, string $actor): void {
        $actor = preg_match('/^(user:\d+|cli:[A-Za-z0-9:._-]+|backend)$/', $actor) ? $actor : 'backend';
        $this->wire()->log->save('mailbox-actions', json_encode([
            'event' => 'attachment_' . $event,
            'account_id' => $this->currentAccountId(),
            'folder_hash' => hash('sha256', $folder),
            'uid' => $uid,
            'part' => (string) $attachment['part'],
            'bytes' => (int) $attachment['bytes'],
            'actor' => $actor,
            'time' => time(),
        ], JSON_UNESCAPED_SLASHES));
    }
}

<?php namespace ProcessWire;

/** Explicitly enabled IMAP flag, move, and delete capabilities. */
trait MailboxMutationConcern {

    /** Apply one bounded mutation to messages selected on a single admin list page. */
    public function bulkMessageAction(string $folder, array $uids, string $action, string $destination = '', string $actor = 'backend'): array {
        $this->assertMutationEnabled();
        $uids = $this->normalizeBulkUids($uids);
        $action = strtolower(trim($action));
        if(!in_array($action, ['read', 'unread', 'flag', 'unflag', 'move', 'delete'], true)) throw new WireException('Unsupported bulk message action.');
        if($action === 'move') {
            $this->validateFolderName($destination);
            $this->assertSelectableFolder($destination);
            if(hash_equals($folder, $destination)) throw new WireException('Source and destination folders must differ.');
        }

        if($this->usesWebklexTransport()) {
            $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder, $uids, $action, $destination): void {
                foreach($uids as $uid) {
                    if($action === 'read' || $action === 'unread') $transport->setFlags($folder, $uid, ['seen'], $action === 'read');
                    elseif($action === 'flag' || $action === 'unflag') $transport->setFlags($folder, $uid, ['flagged'], $action === 'flag');
                    elseif($action === 'move') $transport->move($folder, $uid, $destination, false);
                    else $transport->delete($folder, $uid, false);
                }
            });
        } else {
            $this->withWritableMailbox($folder, function($connection) use ($uids, $action, $destination): void {
                $sequence = implode(',', $uids);
                if($action === 'read' || $action === 'unread') {
                    $ok = $action === 'read'
                        ? @imap_setflag_full($connection, $sequence, '\\Seen', ST_UID)
                        : @imap_clearflag_full($connection, $sequence, '\\Seen', ST_UID);
                } elseif($action === 'flag' || $action === 'unflag') {
                    $ok = $action === 'flag'
                        ? @imap_setflag_full($connection, $sequence, '\\Flagged', ST_UID)
                        : @imap_clearflag_full($connection, $sequence, '\\Flagged', ST_UID);
                } elseif($action === 'move') {
                    $ok = @imap_mail_move($connection, $sequence, $destination, CP_UID);
                } else {
                    $ok = @imap_delete($connection, $sequence, FT_UID);
                }
                if(!$ok) throw new WireException($this->imapError('Unable to apply the bulk message action.'));
            });
        }

        $metadata = [
            'count' => count($uids),
            'uids_hash' => hash('sha256', implode(',', $uids)),
        ];
        if($action === 'move') $metadata['destination_hash'] = hash('sha256', $destination);
        $this->auditBulkMutation($action, $folder, $actor, $metadata);
        $this->clearMailboxViewCache();
        return ['action' => $action, 'count' => count($uids), 'destination' => $action === 'move' ? $destination : null, 'expunged' => false];
    }

    public function setMessageFlags(string $folder, int $uid, array $flags, bool $enabled, string $actor = 'backend'): array {
        $this->assertMutationEnabled();
        return $this->writeMessageFlags($folder, $uid, $flags, $enabled, $actor, 'flags');
    }

    /** Idempotent read receipt for a message visibly opened by an authenticated admin user. */
    public function markMessageReadOnOpen(string $folder, int $uid, string $actor): array {
        if(!preg_match('/^user:\d+$/', $actor)) throw new WirePermissionException('An authenticated admin user is required to mark an opened message read.');
        return $this->writeMessageFlags($folder, $uid, ['seen'], true, $actor, 'opened_seen');
    }

    private function writeMessageFlags(string $folder, int $uid, array $flags, bool $enabled, string $actor, string $auditAction): array {
        if($uid < 1) throw new WireException('Invalid message UID.');
        $normalized = $this->normalizeMutationFlags($flags);
        if(!$normalized) throw new WireException('At least one supported IMAP flag is required.');
        if($this->usesWebklexTransport()) {
            $result = $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder, $uid, $normalized, $enabled): array {
                return $transport->setFlags($folder, $uid, $normalized, $enabled);
            });
        } else {
            $result = $this->withWritableMailbox($folder, function($connection) use ($uid, $normalized, $enabled): array {
                $imapFlags = implode(' ', array_map(static function(string $flag): string { return '\\' . ucfirst($flag); }, $normalized));
                $ok = $enabled
                    ? @imap_setflag_full($connection, (string) $uid, $imapFlags, ST_UID)
                    : @imap_clearflag_full($connection, (string) $uid, $imapFlags, ST_UID);
                if(!$ok) throw new WireException($this->imapError('Unable to update message flags.'));
                return ['uid' => $uid, 'flags' => $normalized, 'enabled' => $enabled];
            });
        }
        $this->auditMutation($auditAction, $folder, $uid, $actor, ['flags' => $normalized, 'enabled' => $enabled]);
        $this->clearMailboxViewCache();
        return $result;
    }

    public function moveMessage(string $folder, int $uid, string $destination, bool $expunge = false, string $actor = 'backend'): array {
        $this->assertMutationEnabled();
        if($uid < 1) throw new WireException('Invalid message UID.');
        $this->validateFolderName($destination);
        $this->assertSelectableFolder($destination);
        if(hash_equals($folder, $destination)) throw new WireException('Source and destination folders must differ.');
        if($this->usesWebklexTransport()) {
            $result = $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder, $uid, $destination, $expunge): array {
                return $transport->move($folder, $uid, $destination, $expunge);
            });
        } else {
            $result = $this->withWritableMailbox($folder, function($connection) use ($uid, $destination, $expunge): array {
                if(!@imap_mail_move($connection, (string) $uid, $destination, CP_UID)) throw new WireException($this->imapError('Unable to move the message.'));
                if($expunge && !@imap_expunge($connection)) throw new WireException($this->imapError('Unable to expunge moved messages.'));
                return ['uid' => $uid, 'moved' => true, 'destination' => $destination, 'expunged' => $expunge];
            });
        }
        $this->auditMutation($expunge ? 'move_expunge' : 'move_mark', $folder, $uid, $actor, ['destination_hash' => hash('sha256', $destination)]);
        $this->clearMailboxViewCache();
        return $result;
    }

    public function deleteMessage(string $folder, int $uid, bool $expunge = false, string $actor = 'backend'): array {
        $this->assertMutationEnabled();
        if($uid < 1) throw new WireException('Invalid message UID.');
        if($this->usesWebklexTransport()) {
            $result = $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folder, $uid, $expunge): array {
                return $transport->delete($folder, $uid, $expunge);
            });
        } else {
            $result = $this->withWritableMailbox($folder, function($connection) use ($uid, $expunge): array {
                if(!@imap_delete($connection, (string) $uid, FT_UID)) throw new WireException($this->imapError('Unable to mark the message deleted.'));
                if($expunge && !@imap_expunge($connection)) throw new WireException($this->imapError('Unable to expunge deleted messages.'));
                return ['uid' => $uid, 'deleted' => true, 'expunged' => $expunge];
            });
        }
        $this->auditMutation($expunge ? 'delete_expunge' : 'delete_mark', $folder, $uid, $actor);
        $this->clearMailboxViewCache();
        return $result;
    }

    protected function withWritableMailbox(string $folder, callable $callback) {
        $connection = $this->openMailbox($folder, false);
        try { return $callback($connection); }
        finally {
            @imap_close($connection);
            if(function_exists('imap_errors')) @imap_errors();
        }
    }

    private function assertMutationEnabled(): void {
        if(!(int) $this->enableMailMutations) throw new WirePermissionException('Mailbox mutation capabilities are disabled.');
    }

    private function normalizeMutationFlags(array $flags): array {
        $allowed = ['seen', 'flagged', 'answered', 'draft'];
        $result = [];
        foreach($flags as $flag) {
            $flag = strtolower(trim((string) $flag, " \\"));
            if(in_array($flag, $allowed, true) && !in_array($flag, $result, true)) $result[] = $flag;
        }
        return $result;
    }

    private function normalizeBulkUids(array $uids): array {
        if(count($uids) > 100) throw new WireException('Bulk message actions are limited to 100 messages.');
        $result = [];
        foreach($uids as $uid) {
            $uid = filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if($uid !== false) $result[(int) $uid] = (int) $uid;
        }
        $result = array_values($result);
        if(!$result) throw new WireException('Select at least one message.');
        return $result;
    }

    private function assertSelectableFolder(string $name): void {
        foreach($this->listFolders() as $folder) {
            if($folder['name'] === $name && !empty($folder['selectable'])) return;
        }
        throw new WireException('Destination folder is unavailable or not selectable.');
    }

    private function auditMutation(string $action, string $folder, int $uid, string $actor, array $metadata = []): void {
        $actor = preg_match('/^(user:\d+|cli:[A-Za-z0-9:._-]+|backend)$/', $actor) ? $actor : 'backend';
        $this->wire()->log->save('mailbox-actions', json_encode([
            'event' => 'imap_' . $action,
            'account_id' => $this->currentAccountId(),
            'folder_hash' => hash('sha256', $folder),
            'uid' => $uid,
            'actor' => $actor,
            'metadata' => $metadata,
            'time' => time(),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function auditBulkMutation(string $action, string $folder, string $actor, array $metadata): void {
        $actor = preg_match('/^(user:\d+|cli:[A-Za-z0-9:._-]+|backend)$/', $actor) ? $actor : 'backend';
        $this->wire()->log->save('mailbox-actions', json_encode([
            'event' => 'imap_bulk_' . $action,
            'account_id' => $this->currentAccountId(),
            'folder_hash' => hash('sha256', $folder),
            'actor' => $actor,
            'metadata' => $metadata,
            'time' => time(),
        ], JSON_UNESCAPED_SLASHES));
    }
}

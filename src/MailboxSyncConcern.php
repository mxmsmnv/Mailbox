<?php namespace ProcessWire;

/** Idempotent metadata indexing, durable jobs, polling, and notification hooks. */
trait MailboxSyncConcern {

    public function indexStore(): MailboxIndexStore {
        if($this->indexService === null) $this->indexService = $this->wire(new MailboxIndexStore());
        return $this->indexService;
    }

    public function queueSync(?int $accountId = null): int {
        if(!(int) $this->enableBackgroundSync) throw new WireException('Mailbox background synchronization is disabled.');
        return $this->indexStore()->enqueue($accountId ?: $this->currentAccountId(), 'sync_account');
    }

    public function syncAccount(?int $accountId = null): array {
        if(!(int) $this->enableBackgroundSync) throw new WireException('Mailbox background synchronization is disabled.');
        $id = $accountId ?: $this->currentAccountId();
        return $this->withAccount($id, function() use ($id): array {
            $folders = [];
            foreach($this->listFolders() as $folder) {
                if(empty($folder['selectable'])) continue;
                $folders[] = (string) $folder['name'];
                if(count($folders) >= max(1, min(500, (int) $this->maxSyncFolders))) break;
            }
            $summary = ['account_id' => $id, 'folders' => 0, 'indexed' => 0, 'new' => 0, 'failed' => 0];
            foreach($folders as $folder) {
                try {
                    $result = $this->syncFolder($folder);
                    $summary['folders']++;
                    $summary['indexed'] += $result['indexed'];
                    $summary['new'] += $result['new'];
                } catch(\Throwable $error) {
                    $summary['failed']++;
                    $this->indexStore()->failState($id, $folder, $this->syncErrorCode($error));
                    $this->wire()->log->save('mailbox-actions', json_encode(['event' => 'sync_folder_failed', 'account_id' => $id, 'folder_hash' => $this->indexStore()->folderIdentifier($id, $folder), 'error_code' => $this->syncErrorCode($error), 'time' => time()], JSON_UNESCAPED_SLASHES));
                }
            }
            return $summary;
        });
    }

    public function syncFolder(string $folder): array {
        if(!(int) $this->enableBackgroundSync) throw new WireException('Mailbox background synchronization is disabled.');
        $this->validateFolderName($folder);
        $accountId = $this->currentAccountId();
        $state = $this->indexStore()->state($accountId, $folder);
        $uidValidity = $this->folderUidValidity($folder);
        if($state !== null && $uidValidity > 0 && (int) $state['uid_validity'] > 0 && $uidValidity !== (int) $state['uid_validity']) {
            $this->indexStore()->resetFolder($accountId, $folder);
            $state = null;
        }
        $initialSync = $state === null;
        $maximum = max(10, min(1000, (int) $this->maxSyncMessagesPerFolder));
        $indexed = 0;
        $new = 0;
        $lastUid = 0;
        $knownTotal = null;
        $seenUids = [];
        for($page = 1; $indexed < $maximum; $page++) {
            $limit = min(100, $maximum - $indexed);
            $batch = $this->listMessages($folder, $page, $limit);
            $knownTotal = (int) ($batch['total'] ?? 0);
            if(empty($batch['messages'])) break;
            foreach($batch['messages'] as $message) {
                $stored = $this->indexStore()->upsertMessage($accountId, $folder, $message);
                $indexed++;
                $lastUid = max($lastUid, (int) $message['uid']);
                $seenUids[] = (int) $message['uid'];
                if($stored['created'] && !$initialSync) {
                    $notification = $this->notificationPayload($folder, $message);
                    $notificationId = $this->indexStore()->notify($accountId, (int) $stored['id'], $notification);
                    if($notificationId !== null) {
                        $new++;
                        $this->dispatchIndexedNotification(['id' => $notificationId, 'account_id' => $accountId] + $notification);
                    }
                }
            }
            if($page >= (int) ($batch['pages'] ?? 1)) break;
        }
        $pruned = $knownTotal !== null ? $this->indexStore()->pruneFolder($accountId, $folder, $seenUids) : 0;
        $this->indexStore()->updateState($accountId, $folder, $lastUid, '', $uidValidity);
        return ['account_id' => $accountId, 'folder_hash' => $this->indexStore()->folderIdentifier($accountId, $folder), 'uid_validity' => $uidValidity, 'indexed' => $indexed, 'new' => $new, 'pruned' => $pruned, 'last_uid' => $lastUid];
    }

    public function processSyncQueue(int $limit = 10): array {
        if(!(int) $this->enableBackgroundSync) throw new WireException('Mailbox background synchronization is disabled.');
        $limit = max(1, min(100, $limit));
        $result = ['claimed' => 0, 'completed' => 0, 'failed' => 0];
        while($result['claimed'] < $limit && ($job = $this->indexStore()->claim(900))) {
            $result['claimed']++;
            try {
                if($job['type'] === 'sync_account') {
                    $sync = $this->syncAccount((int) $job['account_id']);
                    if($sync['failed'] > 0) throw new WireException('One or more folders failed synchronization.');
                } else if($job['type'] === 'webhook_notification') {
                    $this->deliverWebhookNotification((int) $job['account_id'], (array) $job['payload']);
                } else {
                    throw new WireException('Unsupported Mailbox job type.');
                }
                $this->indexStore()->finish($job, true);
                $result['completed']++;
            } catch(\Throwable $error) {
                $this->indexStore()->finish($job, false, $this->syncErrorCode($error));
                $result['failed']++;
            }
        }
        return $result;
    }

    public function cleanupSyncHistory(): array {
        return $this->indexStore()->cleanupHistory(max(1, min(3650, (int) $this->syncHistoryDays)));
    }

    /** Long-running CLI worker. It blocks until stopped or maxEvents is reached. */
    public function watchIdle(string $folder = 'INBOX', int $maxEvents = 0): array {
        if(!(int) $this->enableBackgroundSync) throw new WireException('Mailbox background synchronization is disabled.');
        $this->validateFolderName($folder);
        $seed = $this->syncFolder($folder);
        $accountId = $this->currentAccountId();
        $events = $this->withIdleTransport(function(MailboxWebklexTransport $transport) use ($folder, $accountId, $maxEvents): int {
            return $transport->idle($folder, function(array $message) use ($folder, $accountId): void {
                $stored = $this->indexStore()->upsertMessage($accountId, $folder, $message);
                if(!$stored['created']) return;
                $payload = $this->notificationPayload($folder, $message);
                $notificationId = $this->indexStore()->notify($accountId, (int) $stored['id'], $payload);
                if($notificationId !== null) $this->dispatchIndexedNotification(['id' => $notificationId, 'account_id' => $accountId] + $payload);
            }, max(0, min(1000000, $maxEvents)));
        });
        return ['account_id' => $accountId, 'folder_hash' => $this->indexStore()->folderIdentifier($accountId, $folder), 'seed' => $seed, 'events' => $events];
    }

    public function indexedMessages(int $page = 1, int $limit = 30, ?int $accountId = null): array {
        if(!(int) $this->enableBackgroundSync) throw new WireException('Mailbox background synchronization is disabled.');
        return $this->indexStore()->listMessages($accountId ?: $this->currentAccountId(), $page, $limit);
    }

    public function mailboxNotifications(int $limit = 50, bool $unreadOnly = true, ?int $accountId = null): array {
        if(!(int) $this->enableBackgroundSync) throw new WireException('Mailbox background synchronization is disabled.');
        return $this->indexStore()->notifications($accountId ?: $this->currentAccountId(), $limit, $unreadOnly);
    }

    public function markMailboxNotificationRead(int $id, ?int $accountId = null): bool {
        if(!(int) $this->enableBackgroundSync) throw new WireException('Mailbox background synchronization is disabled.');
        if($id < 1) throw new WireException('Invalid Mailbox notification identifier.');
        return $this->indexStore()->markNotificationRead($accountId ?: $this->currentAccountId(), $id);
    }

    public function hookBackgroundSync(HookEvent $event): void {
        if(!(int) $this->enableBackgroundSync) return;
        try {
            foreach($this->getAccounts() as $account) if(!empty($account['enabled'])) $this->queueSync((int) $account['id']);
            $this->processSyncQueue(max(1, min(20, (int) $this->syncJobsPerRun)));
            $this->cleanupSyncHistory();
        } catch(\Throwable $error) {
            $this->wire()->log->save('mailbox-actions', json_encode(['event' => 'background_sync_failed', 'error_code' => $this->syncErrorCode($error), 'time' => time()], JSON_UNESCAPED_SLASHES));
        }
    }

    /** Hookable in-process notification boundary. */
    public function ___messageIndexed(array $notification): void {}

    protected function registerBackgroundSyncHook(): void {
        if(!(int) $this->enableBackgroundSync || !$this->wire()->modules->isInstalled('LazyCron')) return;
        $allowed = ['everyMinute', 'every5Minutes', 'every15Minutes', 'every30Minutes', 'everyHour'];
        $interval = in_array((string) $this->syncInterval, $allowed, true) ? (string) $this->syncInterval : 'every5Minutes';
        $this->addHook('LazyCron::' . $interval, $this, 'hookBackgroundSync');
    }

    protected function clearIndexForKeyRotation(int $accountId): void {
        $this->indexStore()->clearAccount($accountId);
        $this->wire()->log->save('mailbox-actions', json_encode(['event' => 'encrypted_index_cleared_for_key_rotation', 'account_id' => $accountId, 'time' => time()], JSON_UNESCAPED_SLASHES));
    }

    private function notificationPayload(string $folder, array $message): array {
        return [
            'folder' => $folder,
            'uid' => (int) $message['uid'],
            'subject' => (string) ($message['subject'] ?? ''),
            'from' => (string) ($message['from'] ?? ''),
            'date' => (int) ($message['date'] ?? 0),
        ];
    }

    private function dispatchIndexedNotification(array $notification): void {
        if((int) $this->enableWebhookNotifications) {
            try {
                $accountId = (int) ($notification['account_id'] ?? 0);
                $notificationId = (int) ($notification['id'] ?? 0);
                $payload = [
                    'event' => 'new_message',
                    'event_id' => hash('sha256', 'MailboxWebhook.v1|' . $accountId . '|' . $notificationId),
                    'notification_id' => $notificationId,
                    'account_id' => $accountId,
                    'folder_id' => $this->indexStore()->folderIdentifier($accountId, (string) ($notification['folder'] ?? '')),
                    'uid' => (int) ($notification['uid'] ?? 0),
                    'message_date' => (int) ($notification['date'] ?? 0),
                ];
                $this->indexStore()->enqueue($accountId, 'webhook_notification', $payload);
            } catch(\Throwable $error) {
                $this->wire()->log->save('mailbox-actions', json_encode(['event' => 'webhook_enqueue_failed', 'account_id' => (int) ($notification['account_id'] ?? 0), 'notification_id' => (int) ($notification['id'] ?? 0), 'error_code' => $this->syncErrorCode($error), 'time' => time()], JSON_UNESCAPED_SLASHES));
            }
        }
        try {
            $this->messageIndexed($notification);
        } catch(\Throwable $error) {
            $this->wire()->log->save('mailbox-actions', json_encode(['event' => 'message_indexed_hook_failed', 'account_id' => (int) ($notification['account_id'] ?? 0), 'notification_id' => (int) ($notification['id'] ?? 0), 'error_code' => $this->syncErrorCode($error), 'time' => time()], JSON_UNESCAPED_SLASHES));
        }
    }

    private function deliverWebhookNotification(int $accountId, array $payload): array {
        if(!(int) $this->enableWebhookNotifications) return ['delivered' => false, 'disabled' => true];
        $secret = $this->webhookSecret($accountId);
        $client = new MailboxWebhookClient();
        $result = $client->deliver((string) $this->webhookUrl, $payload, $secret);
        $this->wire()->log->save('mailbox-actions', json_encode(['event' => 'webhook_delivered', 'account_id' => $accountId, 'notification_id' => (int) ($payload['notification_id'] ?? 0), 'status' => (int) ($result['status'] ?? 0), 'time' => time()], JSON_UNESCAPED_SLASHES));
        return $result;
    }

    private function webhookSecret(int $accountId): string {
        $config = $this->wire()->config;
        $provider = $config->mailboxWebhookSecretProvider ?? null;
        if(is_callable($provider)) $secret = (string) $provider($accountId);
        else {
            $secrets = $config->mailboxWebhookSecrets ?? [];
            $secret = is_array($secrets) && isset($secrets[$accountId]) ? (string) $secrets[$accountId] : (string) ($config->mailboxWebhookSecret ?? '');
        }
        if(strlen($secret) < 32 || strlen($secret) > 4096 || strpos($secret, "\0") !== false) throw new WireException('Configure a 32 to 4096 byte Mailbox webhook secret outside module settings.');
        return $secret;
    }

    private function syncErrorCode(\Throwable $error): string {
        $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', (new \ReflectionClass($error))->getShortName()));
        return substr((string) preg_replace('/[^a-z0-9_.-]/', '', $name), 0, 64) ?: 'sync_error';
    }
}

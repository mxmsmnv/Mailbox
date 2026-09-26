<?php namespace ProcessWire;

/** Encrypted local message index, sync state, jobs, and notification inbox. */
final class MailboxIndexStore extends Wire {

    public const INDEX_TABLE = 'mailbox_message_index';
    public const STATE_TABLE = 'mailbox_sync_state';
    public const JOB_TABLE = 'mailbox_jobs';
    public const NOTIFICATION_TABLE = 'mailbox_notifications';
    public const CACHE_TABLE = 'mailbox_view_cache';
    private const CONTEXT = 'MailboxIndex.v1';
    private $cacheTableReady = false;

    private function beginWriteTransaction($database): void {
        if($database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $database->exec('BEGIN IMMEDIATE');
            return;
        }
        $database->beginTransaction();
    }

    private function forUpdate($database): string {
        return $database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    public function ensureTables(): void {
        $database = $this->wire('database');
        $database->exec("CREATE TABLE IF NOT EXISTS `" . self::INDEX_TABLE . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `account_id` INT UNSIGNED NOT NULL,
            `folder_hash` CHAR(64) NOT NULL,
            `uid` BIGINT UNSIGNED NOT NULL,
            `payload_enc` MEDIUMTEXT NOT NULL,
            `message_date` DATETIME NULL,
            `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `modified` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `message_identity` (`account_id`, `folder_hash`, `uid`),
            KEY `message_order` (`account_id`, `message_date`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $database->exec("CREATE TABLE IF NOT EXISTS `" . self::STATE_TABLE . "` (
            `account_id` INT UNSIGNED NOT NULL,
            `folder_hash` CHAR(64) NOT NULL,
            `folder_enc` TEXT NOT NULL,
            `uid_validity` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `last_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `last_sync` DATETIME NULL,
            `last_error_code` VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (`account_id`, `folder_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $database->exec("CREATE TABLE IF NOT EXISTS `" . self::JOB_TABLE . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `account_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(32) NOT NULL,
            `payload_enc` TEXT NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `available_at` DATETIME NOT NULL,
            `lease_token` CHAR(64) NULL,
            `leased_until` DATETIME NULL,
            `error_code` VARCHAR(64) NOT NULL DEFAULT '',
            `dedupe_key` VARCHAR(96) NULL,
            `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `modified` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `job_dedupe` (`dedupe_key`),
            KEY `job_claim` (`status`, `available_at`, `leased_until`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $database->exec("CREATE TABLE IF NOT EXISTS `" . self::NOTIFICATION_TABLE . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `account_id` INT UNSIGNED NOT NULL,
            `index_id` BIGINT UNSIGNED NOT NULL,
            `event` VARCHAR(32) NOT NULL,
            `payload_enc` TEXT NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'unread',
            `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `read_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `notification_identity` (`index_id`, `event`),
            KEY `notification_list` (`account_id`, `status`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->ensureCacheTable();
    }

    public function getCache(int $accountId, string $key, int $maxAge = 86400): ?array {
        $this->ensureCacheTable();
        $maxAge = max(1, min(604800, $maxAge));
        $statement = $this->wire('database')->prepare("SELECT `payload_enc`, `cached_at` FROM `" . self::CACHE_TABLE . "` WHERE `account_id` = :account AND `cache_key` = :cache_key");
        $statement->execute([':account' => $accountId, ':cache_key' => $this->cacheKey($accountId, $key)]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if(!$row) return null;
        $cachedAt = strtotime((string) $row['cached_at'] . ' UTC');
        if($cachedAt === false || time() - $cachedAt > $maxAge) return null;
        return [
            'value' => $this->decode($accountId, (string) $row['payload_enc']),
            'cached_at' => $cachedAt,
            'age' => max(0, time() - $cachedAt),
        ];
    }

    public function putCache(int $accountId, string $key, array $value): array {
        $this->ensureCacheTable();
        $statement = $this->wire('database')->prepare("INSERT INTO `" . self::CACHE_TABLE . "` (`account_id`, `cache_key`, `payload_enc`, `cached_at`) VALUES (:account, :cache_key, :payload, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `payload_enc` = VALUES(`payload_enc`), `cached_at` = VALUES(`cached_at`)");
        $statement->execute([
            ':account' => $accountId,
            ':cache_key' => $this->cacheKey($accountId, $key),
            ':payload' => $this->encrypt($accountId, $this->json($value)),
        ]);
        return ['value' => $value, 'cached_at' => time(), 'age' => 0];
    }

    public function clearCache(int $accountId): void {
        $this->ensureCacheTable();
        $statement = $this->wire('database')->prepare("DELETE FROM `" . self::CACHE_TABLE . "` WHERE `account_id` = :account");
        $statement->execute([':account' => $accountId]);
    }

    public function folderIdentifier(int $accountId, string $folder): string {
        return $this->folderHash($accountId, $folder);
    }

    public function sensitiveFingerprint(int $accountId, string $context, string $value): string {
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $context)) throw new WireException('Invalid Mailbox fingerprint context.');
        return hash_hmac('sha256', $context . "\0" . $value, $this->secretKey($accountId, $this->activeKeyId()));
    }

    public function upsertMessage(int $accountId, string $folder, array $message): array {
        $uid = max(0, (int) ($message['uid'] ?? 0));
        if($accountId < 1 || $uid < 1) throw new WireException('Invalid indexed message identity.');
        $folderHash = $this->folderHash($accountId, $folder);
        $payload = $message + ['folder' => $folder];
        $encrypted = $this->encrypt($accountId, $this->json($payload));
        $date = !empty($message['date']) ? gmdate('Y-m-d H:i:s', (int) $message['date']) : null;
        $database = $this->wire('database');
        $parameters = [':account' => $accountId, ':folder_hash' => $folderHash, ':uid' => $uid, ':payload' => $encrypted, ':message_date' => $date];
        $insert = $database->prepare("INSERT IGNORE INTO `" . self::INDEX_TABLE . "` (`account_id`, `folder_hash`, `uid`, `payload_enc`, `message_date`) VALUES (:account, :folder_hash, :uid, :payload, :message_date)");
        $insert->execute($parameters);
        $created = $insert->rowCount() === 1;
        if(!$created) {
            $update = $database->prepare("UPDATE `" . self::INDEX_TABLE . "` SET `payload_enc` = :payload, `message_date` = :message_date, `modified` = UTC_TIMESTAMP() WHERE `account_id` = :account AND `folder_hash` = :folder_hash AND `uid` = :uid");
            $update->execute($parameters);
        }
        $find = $database->prepare("SELECT `id` FROM `" . self::INDEX_TABLE . "` WHERE `account_id` = :account AND `folder_hash` = :folder_hash AND `uid` = :uid");
        $find->execute([':account' => $accountId, ':folder_hash' => $folderHash, ':uid' => $uid]);
        $id = (int) $find->fetchColumn();
        if($id < 1) throw new WireException('Indexed message could not be read after upsert.');
        return ['id' => $id, 'created' => $created, 'message' => $payload];
    }

    public function listMessages(int $accountId, int $page = 1, int $limit = 30): array {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $count = $this->wire('database')->prepare("SELECT COUNT(*) FROM `" . self::INDEX_TABLE . "` WHERE `account_id` = :account");
        $count->execute([':account' => $accountId]);
        $total = (int) $count->fetchColumn();
        $query = $this->wire('database')->prepare("SELECT `id`, `payload_enc` FROM `" . self::INDEX_TABLE . "` WHERE `account_id` = :account ORDER BY `message_date` DESC, `id` DESC LIMIT :limit OFFSET :offset");
        $query->bindValue(':account', $accountId, \PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $limit, \PDO::PARAM_INT);
        $query->execute();
        $messages = [];
        foreach($query->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) $messages[] = ['index_id' => (int) $row['id']] + $this->decode($accountId, (string) $row['payload_enc']);
        return ['messages' => $messages, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    public function pruneFolder(int $accountId, string $folder, array $keepUids): int {
        $keep = array_values(array_unique(array_filter(array_map('intval', $keepUids), static function(int $uid): bool { return $uid > 0; })));
        $database = $this->wire('database');
        $parameters = [':account' => $accountId, ':folder_hash' => $this->folderHash($accountId, $folder)];
        $where = '`account_id` = :account AND `folder_hash` = :folder_hash';
        if($keep) {
            $tokens = [];
            foreach($keep as $index => $uid) { $token = ':uid' . $index; $tokens[] = $token; $parameters[$token] = $uid; }
            $where .= ' AND `uid` NOT IN (' . implode(',', $tokens) . ')';
        }
        $this->beginWriteTransaction($database);
        try {
            $ids = $database->prepare("SELECT `id` FROM `" . self::INDEX_TABLE . "` WHERE {$where}" . $this->forUpdate($database));
            $ids->execute($parameters);
            $remove = array_map('intval', $ids->fetchAll(\PDO::FETCH_COLUMN) ?: []);
            if($remove) {
                $idTokens = implode(',', array_fill(0, count($remove), '?'));
                $notifications = $database->prepare("DELETE FROM `" . self::NOTIFICATION_TABLE . "` WHERE `index_id` IN ({$idTokens})");
                $notifications->execute($remove);
                $messages = $database->prepare("DELETE FROM `" . self::INDEX_TABLE . "` WHERE `id` IN ({$idTokens})");
                $messages->execute($remove);
            }
            $database->commit();
            return count($remove);
        } catch(\Throwable $error) {
            if($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public function updateState(int $accountId, string $folder, int $lastUid, string $errorCode = '', int $uidValidity = 0): void {
        $errorCode = preg_match('/^[a-z0-9_.-]{0,64}$/', $errorCode) ? $errorCode : 'sync_error';
        $statement = $this->wire('database')->prepare("INSERT INTO `" . self::STATE_TABLE . "` (`account_id`, `folder_hash`, `folder_enc`, `uid_validity`, `last_uid`, `last_sync`, `last_error_code`) VALUES (:account, :folder_hash, :folder, :uid_validity, :last_uid, UTC_TIMESTAMP(), :error) ON DUPLICATE KEY UPDATE `folder_enc` = VALUES(`folder_enc`), `uid_validity` = VALUES(`uid_validity`), `last_uid` = VALUES(`last_uid`), `last_sync` = VALUES(`last_sync`), `last_error_code` = VALUES(`last_error_code`)");
        $statement->execute([':account' => $accountId, ':folder_hash' => $this->folderHash($accountId, $folder), ':folder' => $this->encrypt($accountId, $folder), ':uid_validity' => max(0, $uidValidity), ':last_uid' => max(0, $lastUid), ':error' => $errorCode]);
    }

    public function state(int $accountId, string $folder): ?array {
        $statement = $this->wire('database')->prepare("SELECT `uid_validity`, `last_uid`, `last_sync`, `last_error_code` FROM `" . self::STATE_TABLE . "` WHERE `account_id` = :account AND `folder_hash` = :folder_hash");
        $statement->execute([':account' => $accountId, ':folder_hash' => $this->folderHash($accountId, $folder)]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['uid_validity' => (int) $row['uid_validity'], 'last_uid' => (int) $row['last_uid'], 'last_sync' => (string) ($row['last_sync'] ?? ''), 'error_code' => (string) $row['last_error_code']] : null;
    }

    public function failState(int $accountId, string $folder, string $errorCode): void {
        $errorCode = preg_match('/^[a-z0-9_.-]{1,64}$/', $errorCode) ? $errorCode : 'sync_error';
        $statement = $this->wire('database')->prepare("INSERT INTO `" . self::STATE_TABLE . "` (`account_id`, `folder_hash`, `folder_enc`, `uid_validity`, `last_uid`, `last_sync`, `last_error_code`) VALUES (:account, :folder_hash, :folder, 0, 0, UTC_TIMESTAMP(), :error) ON DUPLICATE KEY UPDATE `folder_enc` = VALUES(`folder_enc`), `last_sync` = VALUES(`last_sync`), `last_error_code` = VALUES(`last_error_code`)");
        $statement->execute([':account' => $accountId, ':folder_hash' => $this->folderHash($accountId, $folder), ':folder' => $this->encrypt($accountId, $folder), ':error' => $errorCode]);
    }

    public function resetFolder(int $accountId, string $folder): void {
        $this->pruneFolder($accountId, $folder, []);
        $statement = $this->wire('database')->prepare("DELETE FROM `" . self::STATE_TABLE . "` WHERE `account_id` = :account AND `folder_hash` = :folder_hash");
        $statement->execute([':account' => $accountId, ':folder_hash' => $this->folderHash($accountId, $folder)]);
    }

    public function enqueue(int $accountId, string $type, array $payload = [], int $delaySeconds = 0): int {
        if($accountId < 1 || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $type)) throw new WireException('Invalid Mailbox job.');
        $dedupe = $type === 'sync_account' ? 'sync_account:' . $accountId : null;
        if($type === 'webhook_notification') {
            $notificationId = (int) ($payload['notification_id'] ?? 0);
            if($notificationId < 1) throw new WireException('Webhook notification job requires a valid notification ID.');
            $dedupe = 'webhook:' . $accountId . ':' . $notificationId;
        }
        $database = $this->wire('database');
        $statement = $database->prepare("INSERT IGNORE INTO `" . self::JOB_TABLE . "` (`account_id`, `type`, `payload_enc`, `available_at`, `dedupe_key`) VALUES (:account, :type, :payload, :available, :dedupe)");
        $statement->execute([':account' => $accountId, ':type' => $type, ':payload' => $this->encrypt($accountId, $this->json($payload)), ':available' => gmdate('Y-m-d H:i:s', time() + max(0, min(86400, $delaySeconds))), ':dedupe' => $dedupe]);
        if($statement->rowCount() === 1) return (int) $database->lastInsertId();
        if($dedupe === null) throw new WireException('Mailbox job could not be queued.');
        $find = $database->prepare("SELECT `id` FROM `" . self::JOB_TABLE . "` WHERE `dedupe_key` = :dedupe");
        $find->execute([':dedupe' => $dedupe]);
        $id = (int) $find->fetchColumn();
        if($id < 1) throw new WireException('Queued Mailbox job could not be read.');
        return $id;
    }

    public function claim(int $leaseSeconds = 120): ?array {
        $database = $this->wire('database');
        $this->beginWriteTransaction($database);
        try {
            $row = $database->query("SELECT `id`, `account_id`, `type`, `payload_enc`, `attempts`, `dedupe_key` FROM `" . self::JOB_TABLE . "` WHERE (`status` = 'queued' OR (`status` = 'running' AND `leased_until` < UTC_TIMESTAMP())) AND `available_at` <= UTC_TIMESTAMP() ORDER BY `id` LIMIT 1" . $this->forUpdate($database))->fetch(\PDO::FETCH_ASSOC);
            if(!$row) { $database->commit(); return null; }
            $token = bin2hex(random_bytes(32));
            $update = $database->prepare("UPDATE `" . self::JOB_TABLE . "` SET `status` = 'running', `attempts` = `attempts` + 1, `lease_token` = :token, `leased_until` = :leased, `error_code` = '', `modified` = UTC_TIMESTAMP() WHERE `id` = :id");
            $update->execute([':token' => $token, ':leased' => gmdate('Y-m-d H:i:s', time() + max(30, min(900, $leaseSeconds))), ':id' => (int) $row['id']]);
            $database->commit();
            return ['id' => (int) $row['id'], 'account_id' => (int) $row['account_id'], 'type' => (string) $row['type'], 'payload' => $this->decode((int) $row['account_id'], (string) $row['payload_enc']), 'attempts' => (int) $row['attempts'] + 1, 'lease_token' => $token, 'dedupe_key' => (string) ($row['dedupe_key'] ?? '')];
        } catch(\Throwable $error) {
            if($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public function finish(array $job, bool $success, string $errorCode = ''): void {
        $id = (int) ($job['id'] ?? 0);
        $token = (string) ($job['lease_token'] ?? '');
        if($id < 1 || !preg_match('/^[a-f0-9]{64}$/', $token)) throw new WireException('Invalid Mailbox job lease.');
        $attempts = max(1, (int) ($job['attempts'] ?? 1));
        $terminal = $success || $attempts >= 5;
        $status = $success ? 'done' : ($terminal ? 'failed' : 'queued');
        $delay = min(3600, 30 * (2 ** min(6, $attempts - 1)));
        $errorCode = preg_match('/^[a-z0-9_.-]{0,64}$/', $errorCode) ? $errorCode : 'job_error';
        $dedupe = $status === 'queued' ? (string) ($job['dedupe_key'] ?? '') : null;
        if($dedupe === '') $dedupe = null;
        $statement = $this->wire('database')->prepare("UPDATE `" . self::JOB_TABLE . "` SET `status` = :status, `available_at` = :available, `lease_token` = NULL, `leased_until` = NULL, `error_code` = :error, `dedupe_key` = :dedupe, `modified` = UTC_TIMESTAMP() WHERE `id` = :id AND `lease_token` = :token");
        $statement->execute([':status' => $status, ':available' => gmdate('Y-m-d H:i:s', time() + ($status === 'queued' ? $delay : 0)), ':error' => $success ? '' : $errorCode, ':dedupe' => $dedupe, ':id' => $id, ':token' => $token]);
        if($statement->rowCount() !== 1) throw new WireException('Mailbox job lease was lost.');
    }

    public function notify(int $accountId, int $indexId, array $payload): ?int {
        $statement = $this->wire('database')->prepare("INSERT IGNORE INTO `" . self::NOTIFICATION_TABLE . "` (`account_id`, `index_id`, `event`, `payload_enc`) VALUES (:account, :index_id, 'new_message', :payload)");
        $statement->execute([':account' => $accountId, ':index_id' => $indexId, ':payload' => $this->encrypt($accountId, $this->json($payload))]);
        return $statement->rowCount() === 1 ? (int) $this->wire('database')->lastInsertId() : null;
    }

    public function notifications(int $accountId, int $limit = 50, bool $unreadOnly = true): array {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT `id`, `event`, `payload_enc`, `status`, `created` FROM `" . self::NOTIFICATION_TABLE . "` WHERE `account_id` = :account" . ($unreadOnly ? " AND `status` = 'unread'" : '') . " ORDER BY `id` DESC LIMIT :limit";
        $query = $this->wire('database')->prepare($sql);
        $query->bindValue(':account', $accountId, \PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->execute();
        $result = [];
        foreach($query->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) $result[] = ['id' => (int) $row['id'], 'event' => (string) $row['event'], 'status' => (string) $row['status'], 'created' => (string) $row['created']] + $this->decode($accountId, (string) $row['payload_enc']);
        return $result;
    }

    public function markNotificationRead(int $accountId, int $id): bool {
        $statement = $this->wire('database')->prepare("UPDATE `" . self::NOTIFICATION_TABLE . "` SET `status` = 'read', `read_at` = UTC_TIMESTAMP() WHERE `id` = :id AND `account_id` = :account AND `status` = 'unread'");
        $statement->execute([':id' => $id, ':account' => $accountId]);
        return $statement->rowCount() === 1;
    }

    public function cleanupHistory(int $days): array {
        $cutoff = gmdate('Y-m-d H:i:s', time() - (max(1, min(3650, $days)) * 86400));
        $jobs = $this->wire('database')->prepare("DELETE FROM `" . self::JOB_TABLE . "` WHERE `status` IN ('done', 'failed') AND `modified` < :cutoff");
        $jobs->execute([':cutoff' => $cutoff]);
        $notifications = $this->wire('database')->prepare("DELETE FROM `" . self::NOTIFICATION_TABLE . "` WHERE `status` = 'read' AND `read_at` < :cutoff");
        $notifications->execute([':cutoff' => $cutoff]);
        return ['jobs' => $jobs->rowCount(), 'notifications' => $notifications->rowCount()];
    }

    public function clearAccount(int $accountId): void {
        $database = $this->wire('database');
        $database->beginTransaction();
        try {
            foreach([self::NOTIFICATION_TABLE, self::JOB_TABLE, self::STATE_TABLE, self::INDEX_TABLE, self::CACHE_TABLE] as $table) {
                $statement = $database->prepare("DELETE FROM `{$table}` WHERE `account_id` = :account");
                $statement->execute([':account' => $accountId]);
            }
            $database->commit();
        } catch(\Throwable $error) {
            if($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public function dropTables(): void {
        foreach([self::NOTIFICATION_TABLE, self::JOB_TABLE, self::STATE_TABLE, self::INDEX_TABLE, self::CACHE_TABLE] as $table) $this->wire('database')->exec("DROP TABLE IF EXISTS `{$table}`");
    }

    private function ensureCacheTable(): void {
        if($this->cacheTableReady) return;
        $this->wire('database')->exec("CREATE TABLE IF NOT EXISTS `" . self::CACHE_TABLE . "` (
            `account_id` INT UNSIGNED NOT NULL,
            `cache_key` CHAR(64) NOT NULL,
            `payload_enc` MEDIUMTEXT NOT NULL,
            `cached_at` DATETIME NOT NULL,
            PRIMARY KEY (`account_id`, `cache_key`),
            KEY `cache_age` (`cached_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->cacheTableReady = true;
    }

    private function cacheKey(int $accountId, string $key): string {
        if($accountId < 1 || $key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $key)) throw new WireException('Invalid Mailbox cache key.');
        return hash_hmac('sha256', 'MailboxViewCache.v1|' . $key, $this->secretKey($accountId, $this->activeKeyId()));
    }

    private function json(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($json)) throw new WireException('Unable to encode Mailbox index data.');
        return $json;
    }

    private function decode(int $accountId, string $stored): array {
        $json = $this->decrypt($accountId, $stored);
        $value = json_decode($json, true);
        if(!is_array($value)) throw new WireException('Mailbox index data is corrupted.');
        return $value;
    }

    private function encrypt(int $accountId, string $plain): string {
        $keyId = $this->activeKeyId();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->secretKey($accountId, $keyId));
        return 'v1:' . $keyId . ':' . $accountId . ':' . base64_encode($nonce . $cipher);
    }

    private function decrypt(int $accountId, string $stored): string {
        if(!preg_match('/^v1:([A-Za-z0-9_.-]{1,64}):([1-9][0-9]*):(.+)$/', $stored, $matches) || (int) $matches[2] !== $accountId) throw new WireException('Invalid Mailbox index encryption envelope.');
        $raw = base64_decode($matches[3], true);
        if(!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new WireException('Invalid Mailbox index ciphertext.');
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->secretKey($accountId, $matches[1]));
        if($plain === false) throw new WireException('Mailbox index authentication failed.');
        return $plain;
    }

    private function activeKeyId(): string {
        $keyId = trim((string) ($this->wire('config')->mailboxActiveKey ?? 'default')) ?: 'default';
        if(!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $keyId)) throw new WireException('Invalid Mailbox active key identifier.');
        return $keyId;
    }

    private function folderHash(int $accountId, string $folder): string {
        return hash_hmac('sha256', $folder, $this->secretKey($accountId, $this->activeKeyId()));
    }

    private function secretKey(int $accountId, string $keyId): string {
        if(!function_exists('sodium_crypto_secretbox')) throw new WireException('The PHP sodium extension is required for the Mailbox index.');
        $config = $this->wire('config');
        $base = '';
        $provider = $config->mailboxKeyProvider ?? null;
        if(is_callable($provider)) $base = (string) $provider($keyId);
        $keys = $config->mailboxKeys ?? [];
        if($base === '' && is_array($keys) && isset($keys[$keyId])) $base = (string) $keys[$keyId];
        if($base === '' && $keyId === 'default') $base = trim((string) (($config->mailboxSecret ?? '') ?: (($config->tableSalt ?? '') ?: ($config->userAuthSalt ?? ''))));
        if($base === '') throw new WireException('Mailbox index key is unavailable.');
        return sodium_crypto_generichash(self::CONTEXT . '|' . $keyId . '|account:' . $accountId . '|' . $base, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}

<?php namespace ProcessWire;

/** Non-secret account registry. Secrets remain in MailboxCredentials. */
final class MailboxAccounts extends Wire {

    public const TABLE = 'mailbox_accounts';
    public const MAX_ACCOUNTS = 3;

    public function ensureTable(): void {
        $this->wire('database')->exec(
            "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uuid` CHAR(36) NOT NULL,
                `label` VARCHAR(190) NOT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `settings_json` LONGTEXT NOT NULL,
                `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `modified` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uuid` (`uuid`),
                KEY `default_account` (`is_default`, `enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function ensureDefault(array $settings): int {
        $this->ensureTable();
        $existing = $this->find(1);
        if($existing) return 1;
        if(count($this->list()) >= self::MAX_ACCOUNTS) throw new WireException('Cannot restore the primary mailbox profile because the three-account limit is already reached.');
        $statement = $this->wire('database')->prepare(
            "INSERT INTO `" . self::TABLE . "` (`id`, `uuid`, `label`, `is_default`, `enabled`, `settings_json`)
             VALUES (1, :uuid, :label, 1, 1, :settings)"
        );
        $statement->execute([
            ':uuid' => $this->uuid(),
            ':label' => 'Primary mailbox',
            ':settings' => $this->encodeSettings($settings),
        ]);
        return 1;
    }

    public function create(string $label, array $settings, bool $default = false): array {
        $this->ensureTable();
        $label = $this->label($label);
        $database = $this->wire('database');
        $database->beginTransaction();
        try {
            $lock = $database->prepare("SELECT `id` FROM `" . self::TABLE . "` ORDER BY `id` FOR UPDATE");
            $lock->execute();
            if(count($lock->fetchAll(\PDO::FETCH_COLUMN) ?: []) >= self::MAX_ACCOUNTS) throw new WireException('Mailbox supports at most three accounts.');
            $statement = $database->prepare(
                "INSERT INTO `" . self::TABLE . "` (`uuid`, `label`, `is_default`, `enabled`, `settings_json`)
                 VALUES (:uuid, :label, 0, 1, :settings)"
            );
            $statement->execute([':uuid' => $this->uuid(), ':label' => $label, ':settings' => $this->encodeSettings($settings)]);
            $id = (int) $database->lastInsertId();
            $database->commit();
        } catch(\Throwable $error) {
            if($database->inTransaction()) $database->rollBack();
            throw $error;
        }
        if($default) $this->setDefault($id);
        return $this->require($id);
    }

    public function update(int $id, string $label, array $settings, bool $enabled = true): array {
        $current = $this->require($id);
        if($current['is_default'] && !$enabled) throw new WireException('The default mailbox account cannot be disabled.');
        $statement = $this->wire('database')->prepare(
            "UPDATE `" . self::TABLE . "` SET `label` = :label, `enabled` = :enabled, `settings_json` = :settings WHERE `id` = :id"
        );
        $statement->execute([
            ':id' => $id,
            ':label' => $this->label($label),
            ':enabled' => $enabled ? 1 : 0,
            ':settings' => $this->encodeSettings($settings),
        ]);
        return $this->require($id);
    }

    public function list(): array {
        try {
            $statement = $this->wire('database')->prepare(
                "SELECT `id`, `uuid`, `label`, `is_default`, `enabled`, `settings_json`, `created`, `modified`
                 FROM `" . self::TABLE . "` ORDER BY `is_default` DESC, `label`, `id`"
            );
            $statement->execute();
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch(\Throwable $error) {
            if($this->missingTable($error)) return [];
            throw $error;
        }
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function find(int $id): ?array {
        try {
            $statement = $this->wire('database')->prepare(
                "SELECT `id`, `uuid`, `label`, `is_default`, `enabled`, `settings_json`, `created`, `modified`
                 FROM `" . self::TABLE . "` WHERE `id` = :id"
            );
            $statement->execute([':id' => $id]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
        } catch(\Throwable $error) {
            if($this->missingTable($error)) return null;
            throw $error;
        }
        return $row ? $this->hydrate($row) : null;
    }

    public function require(int $id): array {
        $account = $id > 0 ? $this->find($id) : null;
        if(!$account) throw new WireException('Mailbox account was not found.');
        return $account;
    }

    public function setDefault(int $id): void {
        $this->require($id);
        $database = $this->wire('database');
        $database->beginTransaction();
        try {
            $database->exec("UPDATE `" . self::TABLE . "` SET `is_default` = 0");
            $statement = $database->prepare("UPDATE `" . self::TABLE . "` SET `is_default` = 1, `enabled` = 1 WHERE `id` = :id");
            $statement->execute([':id' => $id]);
            $database->commit();
        } catch(\Throwable $error) {
            if($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public function delete(int $id): void {
        $account = $this->require($id);
        if($id === 1) throw new WireException('The primary mailbox profile is managed by module settings and cannot be deleted.');
        if($account['is_default']) throw new WireException('Select another default account before deleting this account.');
        $statement = $this->wire('database')->prepare("DELETE FROM `" . self::TABLE . "` WHERE `id` = :id");
        $statement->execute([':id' => $id]);
    }

    public function dropTable(): void {
        $this->wire('database')->exec("DROP TABLE IF EXISTS `" . self::TABLE . "`");
    }

    private function hydrate(array $row): array {
        $settings = json_decode((string) $row['settings_json'], true);
        if(!is_array($settings)) throw new WireException('Mailbox account settings are corrupted.');
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'label' => (string) $row['label'],
            'is_default' => (bool) $row['is_default'],
            'enabled' => (bool) $row['enabled'],
            'settings' => $settings,
            'created' => (string) ($row['created'] ?? ''),
            'modified' => (string) ($row['modified'] ?? ''),
        ];
    }

    private function encodeSettings(array $settings): string {
        $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if(!is_string($json)) throw new WireException('Unable to encode Mailbox account settings.');
        return $json;
    }

    private function label(string $label): string {
        $label = trim($label);
        if($label === '' || strlen($label) > 190) throw new WireException('Mailbox account label is required and must not exceed 190 bytes.');
        return $label;
    }

    private function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function missingTable(\Throwable $error): bool {
        $message = strtolower($error->getMessage());
        return strpos($message, self::TABLE) !== false
            && (strpos($message, "doesn't exist") !== false || strpos($message, 'no such table') !== false);
    }
}

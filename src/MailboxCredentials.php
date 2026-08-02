<?php namespace ProcessWire;

/**
 * Encrypted, single-account credential storage for Mailbox.
 */
final class MailboxCredentials extends Wire {

    public const TABLE = 'mailbox_credentials';
    public const CONTEXT = 'MailboxCredentials.v1';
    public const ENVELOPE_VERSION = 'v3';
    private $accountId;

    public function __construct(int $accountId = 1) {
        if($accountId < 1) throw new WireException('Invalid Mailbox account identifier.');
        $this->accountId = $accountId;
    }

    public function ensureTable(): void {
        $this->assertCryptoAvailable();
        $this->wire('database')->exec(
            "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `id` INT UNSIGNED NOT NULL,
                `username_enc` TEXT NOT NULL,
                `password_enc` TEXT NOT NULL,
                `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `modified` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function upgradeAccountIdColumn(): void {
        $database = $this->wire('database');
        if($database instanceof \PDO) $database->exec("ALTER TABLE `" . self::TABLE . "` MODIFY `id` INT UNSIGNED NOT NULL");
    }

    public function dropTable(): void {
        $this->wire('database')->exec("DROP TABLE IF EXISTS `" . self::TABLE . "`");
    }

    public function save(string $username, string $password): void {
        $username = trim($username);
        if($username === '' || $password === '') throw new WireException('Both IMAP username and password are required.');
        if(strlen($username) > 320 || preg_match('/[\x00-\x1F\x7F]/', $username)) throw new WireException('IMAP username is invalid or too long.');
        if(strlen($password) > 32768 || strpos($password, "\0") !== false) throw new WireException('IMAP credential secret is invalid or too long.');
        $this->ensureTable();

        $usernameEncrypted = $this->encrypt($username);
        $passwordEncrypted = $this->encrypt($password);
        if(!hash_equals($username, $this->decrypt($usernameEncrypted)) || !hash_equals($password, $this->decrypt($passwordEncrypted))) {
            throw new WireException('Mailbox credential encryption verification failed.');
        }

        $statement = $this->wire('database')->prepare(
            "INSERT INTO `" . self::TABLE . "` (`id`, `username_enc`, `password_enc`)
             VALUES (:id, :username, :password)
             ON DUPLICATE KEY UPDATE `username_enc` = VALUES(`username_enc`), `password_enc` = VALUES(`password_enc`)"
        );
        $statement->execute([':id' => $this->accountId, ':username' => $usernameEncrypted, ':password' => $passwordEncrypted]);

        $stored = $this->get();
        if(!$stored || !hash_equals($username, $stored['username']) || !hash_equals($password, $stored['password'])) {
            throw new WireException('Mailbox credential storage verification failed.');
        }
    }

    /**
     * Return decrypted credentials, null when no row exists, and throw when
     * ciphertext or the config.php derivation secret is invalid.
     */
    public function get(): ?array {
        try {
            $statement = $this->wire('database')->prepare(
                "SELECT `username_enc`, `password_enc` FROM `" . self::TABLE . "` WHERE `id` = :id"
            );
            $statement->execute([':id' => $this->accountId]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
        } catch(\Throwable $error) {
            if($this->isMissingTableError($error)) return null;
            throw new WireException('Unable to read encrypted Mailbox credentials.', 0, $error);
        }
        if(!$row) return null;

        $username = $this->decrypt((string) $row['username_enc']);
        $password = $this->decrypt((string) $row['password_enc']);
        if($username === '' || $password === '') {
            throw new WireException('Mailbox credentials cannot be decrypted. Check the config.php Mailbox secret.');
        }
        return ['username' => $username, 'password' => $password];
    }

    public function delete(): void {
        try {
            $statement = $this->wire('database')->prepare("DELETE FROM `" . self::TABLE . "` WHERE `id` = :id");
            $statement->execute([':id' => $this->accountId]);
        } catch(\Throwable $error) {
            if(!$this->isMissingTableError($error)) throw $error;
        }
    }

    /** Re-encrypt the verified row with the currently active key. */
    public function rotate(): array {
        $credentials = $this->get();
        if(!$credentials) throw new WireException('Mailbox credentials are not configured.');
        $before = $this->storedKeyId();
        $this->save((string) $credentials['username'], (string) $credentials['password']);
        $after = $this->storedKeyId();
        return ['rotated' => true, 'from_key' => $before, 'to_key' => $after];
    }

    /** Return only non-secret envelope metadata. */
    public function storedKeyId(): string {
        try {
            $statement = $this->wire('database')->prepare(
                "SELECT `username_enc` FROM `" . self::TABLE . "` WHERE `id` = :id"
            );
            $statement->execute([':id' => $this->accountId]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
        } catch(\Throwable $error) {
            if($this->isMissingTableError($error)) return 'none';
            throw $error;
        }
        if(!$row) return 'none';
        return $this->envelopeKeyId((string) $row['username_enc']);
    }

    protected function encrypt(string $plain): string {
        $this->assertCryptoAvailable();
        $keyId = $this->activeKeyId();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plain, $nonce, $this->secretKey($keyId, true));
        return self::ENVELOPE_VERSION . ':' . $keyId . ':' . $this->accountId . ':' . base64_encode($nonce . $ciphertext);
    }

    protected function decrypt(string $stored): string {
        $this->assertCryptoAvailable();
        if(strpos($stored, 'v1:') === 0) {
            $keyId = 'legacy';
            $payload = substr($stored, 3);
            $accountBound = false;
        } else if(preg_match('/^v2:([A-Za-z0-9_.-]{1,64}):(.+)$/', $stored, $matches)) {
            $keyId = $matches[1];
            $payload = $matches[2];
            $accountBound = false;
        } else if(preg_match('/^v3:([A-Za-z0-9_.-]{1,64}):([1-9][0-9]*):(.+)$/', $stored, $matches)) {
            $keyId = $matches[1];
            if((int) $matches[2] !== $this->accountId) throw new WireException('Mailbox credential belongs to another account.');
            $payload = $matches[3];
            $accountBound = true;
        } else {
            throw new WireException('Unsupported Mailbox credential encryption format.');
        }
        $raw = base64_decode($payload, true);
        if($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new WireException('Invalid Mailbox credential ciphertext.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->secretKey($keyId, $accountBound));
        if($plain === false) throw new WireException('Mailbox credential authentication failed.');
        return $plain;
    }

    protected function activeKeyId(): string {
        $keyId = trim((string) ($this->wire('config')->mailboxActiveKey ?? 'default'));
        if($keyId === '') $keyId = 'default';
        if(!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $keyId)) throw new WireException('Invalid Mailbox active key identifier.');
        return $keyId;
    }

    protected function envelopeKeyId(string $stored): string {
        if(strpos($stored, 'v1:') === 0) return 'legacy';
        if(preg_match('/^v[23]:([A-Za-z0-9_.-]{1,64}):/', $stored, $matches)) return $matches[1];
        return 'unknown';
    }

    protected function secretKey(string $keyId, bool $accountBound = false): string {
        $config = $this->wire('config');
        $base = '';
        $provider = $config->mailboxKeyProvider ?? null;
        if(is_callable($provider) && $keyId !== 'legacy') $base = (string) $provider($keyId);
        $keys = $config->mailboxKeys ?? [];
        if($base === '' && is_array($keys) && isset($keys[$keyId])) $base = (string) $keys[$keyId];
        if($base === '' && in_array($keyId, ['default', 'legacy'], true)) {
            $base = trim((string) (($config->mailboxSecret ?? '') ?: (($config->tableSalt ?? '') ?: ($config->userAuthSalt ?? ''))));
        }
        if($base === '') throw new WireException('Set $config->mailboxSecret in config.php before storing Mailbox credentials.');
        if($keyId === 'legacy') {
            $material = self::CONTEXT . '|' . $base;
        } else {
            $material = self::CONTEXT . '|' . $keyId . '|' . ($accountBound ? 'account:' . $this->accountId . '|' : '') . $base;
        }
        return sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    protected function assertCryptoAvailable(): void {
        if(!function_exists('sodium_crypto_secretbox') || !defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')) {
            throw new WireException('The PHP sodium extension is required for encrypted Mailbox credentials.');
        }
    }

    protected function isMissingTableError(\Throwable $error): bool {
        $message = strtolower($error->getMessage());
        return strpos($message, 'mailbox_credentials') !== false
            && (strpos($message, "doesn't exist") !== false || strpos($message, 'no such table') !== false);
    }
}

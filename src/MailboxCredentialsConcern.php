<?php namespace ProcessWire;

/** Credential lifecycle and encrypted-storage boundary for Mailbox. */
trait MailboxCredentialsConcern {
    /**
     * Move legacy plaintext config credentials to authenticated encryption.
     * The config copy is removed only after the stored row decrypts correctly.
     */
    protected function migrateCredentialsToTable(): bool {
        $legacyUsername = trim((string) $this->username);
        $legacyPassword = (string) $this->password;
        $stored = $this->credentials(1)->get();

        if(!$stored && $legacyUsername !== '' && $legacyPassword !== '') {
            $this->credentials(1)->save($legacyUsername, $legacyPassword);
            $stored = $this->credentials(1)->get();
        }
        if(!$stored && $legacyUsername === '' && $legacyPassword === '') return false;
        if(!$stored) {
            $this->clearLegacyCredentialsFromConfig();
            $this->wire()->log->save('mailbox-actions', 'Removed incomplete legacy IMAP credentials from module config; no usable credential row was created.');
            return false;
        }

        if($this->clearLegacyCredentialsFromConfig()) {
            $this->wire()->log->save('mailbox-actions', 'Migrated IMAP credentials from module config to encrypted storage.');
        }
        return true;
    }

    protected function clearLegacyCredentialsFromConfig(): bool {
        $configData = $this->wire()->modules->getModuleConfigData($this);
        if(!array_key_exists('username', $configData) && !array_key_exists('password', $configData)) return false;
        unset($configData['username'], $configData['password'], $configData['clearPassword']);
        $this->savingSanitizedConfig = true;
        try {
            $this->wire()->modules->saveModuleConfigData($this, $configData);
        } finally {
            $this->savingSanitizedConfig = false;
        }
        $this->set('username', '');
        $this->set('password', '');
        return true;
    }

    /**
     * Non-secret status for settings and health surfaces.
     */
    public function credentialStatus(?int $accountId = null): array {
        $id = $accountId ?: $this->currentAccountId();
        $credentials = $this->credentials($id)->get();
        $legacy = !$credentials && $id === 1 ? $this->legacyCredentials() : null;
        return [
            'configured' => $credentials !== null || $legacy !== null,
            'password_source' => $credentials && strpos($credentials['password'], 'env:') === 0
                ? 'environment'
                : ($credentials && strpos($credentials['password'], 'oauth:v1:') === 0
                    ? 'oauth_tokens'
                    : ($credentials ? 'encrypted_table' : ($legacy ? 'legacy_config' : 'none'))),
            'encryption_key' => $credentials ? $this->credentials($id)->storedKeyId() : 'none',
        ];
    }

    /** Explicit, verified online re-encryption for CLI/operations tooling. */
    public function rotateCredentialEncryption(?int $accountId = null): array {
        $id = $accountId ?: $this->currentAccountId();
        if(!$this->credentials($id)->get()) throw new WireException('Mailbox credentials are not configured.');
        $this->clearIndexForKeyRotation($id);
        $result = $this->credentials($id)->rotate();
        $this->wire()->log->save('mailbox-actions', json_encode([
            'event' => 'credentials_rotated',
            'from_key' => $result['from_key'],
            'to_key' => $result['to_key'],
            'account_id' => $id,
            'time' => time(),
        ], JSON_UNESCAPED_SLASHES));
        return $result;
    }

    protected function credentials(?int $accountId = null): MailboxCredentials {
        $id = $accountId ?: $this->currentAccountId();
        if(!isset($this->credentialStores[$id])) $this->credentialStores[$id] = $this->wire(new MailboxCredentials($id));
        return $this->credentialStores[$id];
    }

    /**
     * Table-first lookup with a legacy-config fallback used only during upgrade.
     */
    protected function storedCredentials(bool $resolveEnvironment = true): ?array {
        $id = $this->currentAccountId();
        $credentials = $this->credentials($id)->get();
        if(!$credentials && $id === 1) $credentials = $this->legacyCredentials();
        if(!$credentials) return null;
        if($resolveEnvironment) $credentials['password'] = $this->resolveCredentialPassword($credentials['password']);
        return $credentials;
    }

    protected function legacyCredentials(): ?array {
        $username = trim((string) $this->username);
        $password = (string) $this->password;
        if($username === '' || $password === '') return null;
        return ['username' => $username, 'password' => $password];
    }

    protected function resolveCredentialPassword(string $password): string {
        if(strpos($password, 'env:') !== 0) return $password;
        $name = substr($password, 4);
        if(!preg_match('/^[A-Z_][A-Z0-9_]{1,127}$/', $name)) throw new WireException('Invalid Mailbox credential environment reference.');
        $value = getenv($name);
        if($value === false || $value === '') throw new WireException('Mailbox credential environment variable is not set.');
        if(strlen((string) $value) > 32768 || strpos((string) $value, "\0") !== false) throw new WireException('Mailbox credential environment value is invalid or too long.');
        return (string) $value;
    }

    /**
     * Provider templates. Credentials are deliberately never part of a preset.
     */
}

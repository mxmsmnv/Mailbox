<?php namespace ProcessWire;

/** Multi-account registry, migration, and scoped execution. */
trait MailboxAccountsConcern {

    protected function accountRegistry(): MailboxAccounts {
        if($this->accountStore === null) $this->accountStore = $this->wire(new MailboxAccounts());
        return $this->accountStore;
    }

    protected function ensureAccountRegistry(): void {
        $this->accountRegistry()->ensureDefault($this->moduleAccountSettings());
    }

    public function getAccounts(): array {
        $this->ensureAccountRegistry();
        $result = [];
        foreach($this->accountRegistry()->list() as $account) {
            $status = $this->credentialStatus((int) $account['id']);
            $account['credentials'] = $status;
            $result[] = $account;
        }
        return $result;
    }

    public function getAccountLimit(): int {
        return MailboxAccounts::MAX_ACCOUNTS;
    }

    public function getAccount(int $id): array {
        $this->ensureAccountRegistry();
        $account = $this->accountRegistry()->require($id);
        $account['credentials'] = $this->credentialStatus($id);
        return $account;
    }

    public function createAccount(string $label, array $settings, string $username, string $password, bool $default = false): array {
        $normalized = $this->normalizeAccountSettings($settings);
        if($normalized['host'] === '') throw new WireException('A valid IMAP hostname is required.');
        $account = $this->accountRegistry()->create($label, $normalized, false);
        try {
            $this->credentials((int) $account['id'])->save($username, $password);
        } catch(\Throwable $error) {
            $this->accountRegistry()->delete((int) $account['id']);
            throw $error;
        }
        if($default) {
            $this->accountRegistry()->setDefault((int) $account['id']);
            $account = $this->accountRegistry()->require((int) $account['id']);
            $this->accountCache = [];
        }
        $account['credentials'] = $this->credentialStatus((int) $account['id']);
        return $account;
    }

    /** Create an OAuth profile before its one-time authorization flow stores tokens. */
    public function createOAuthAccount(string $label, array $settings, string $username, bool $default = false): array {
        $normalized = $this->normalizeAccountSettings(array_merge($settings, ['authentication' => 'oauth']));
        if($normalized['host'] === '') throw new WireException('A valid IMAP hostname is required.');
        if($normalized['authentication'] !== 'oauth' || $normalized['oauthProvider'] === '' || $normalized['oauthClientId'] === '') {
            throw new WireException('OAuth provider, client ID, and mailbox username are required.');
        }
        $account = $this->accountRegistry()->create($label, $normalized, false);
        try {
            $this->prepareOAuthIdentity((int) $account['id'], $username, (string) $normalized['oauthProvider']);
        } catch(\Throwable $error) {
            $this->accountRegistry()->delete((int) $account['id']);
            throw $error;
        }
        if($default) {
            $this->accountRegistry()->setDefault((int) $account['id']);
            $account = $this->accountRegistry()->require((int) $account['id']);
            $this->accountCache = [];
        }
        $account['credentials'] = $this->credentialStatus((int) $account['id']);
        return $account;
    }

    public function updateAccount(int $id, string $label, array $settings, ?string $username = null, ?string $password = null, bool $enabled = true): array {
        if(($username === null) xor ($password === null)) throw new WireException('Provide both username and password when replacing account credentials.');
        $account = $this->accountRegistry()->update($id, $label, $this->normalizeAccountSettings($settings), $enabled);
        if($username !== null && $password !== null) $this->credentials($id)->save($username, $password);
        $this->clearMailboxViewCache($id);
        $this->accountCache = [];
        $account['credentials'] = $this->credentialStatus($id);
        return $account;
    }

    public function deleteAccount(int $id): void {
        $account = $this->accountRegistry()->require($id);
        if($account['is_default']) throw new WireException('Select another default account before deleting this account.');
        $this->indexStore()->clearAccount($id);
        $this->credentials($id)->delete();
        $this->accountRegistry()->delete($id);
        unset($this->credentialStores[$id], $this->accountCache[$id]);
    }

    public function setDefaultAccount(int $id): void {
        $this->accountRegistry()->setDefault($id);
        $this->accountCache = [];
    }

    /** Execute an existing facade call against one account and restore context. */
    public function withAccount(int $id, callable $operation) {
        $account = $this->accountRegistry()->require($id);
        if(!$account['enabled']) throw new WireException('Mailbox account is disabled.');
        $previous = $this->activeAccountId;
        $this->activeAccountId = $id;
        try {
            return $operation($this, $account);
        } finally {
            $this->activeAccountId = $previous;
        }
    }

    protected function currentAccountId(): int {
        if($this->activeAccountId !== null) return (int) $this->activeAccountId;
        if(!method_exists($this, 'wire')) return 1;
        foreach($this->accountRegistry()->list() as $account) {
            if($account['is_default']) return (int) $account['id'];
        }
        return 1;
    }

    protected function accountSetting(string $name) {
        if(!method_exists($this, 'wire')) return $this->{$name};
        $id = $this->currentAccountId();
        if(!isset($this->accountCache[$id])) $this->accountCache[$id] = $this->accountRegistry()->find($id);
        $account = $this->accountCache[$id];
        if($account && array_key_exists($name, $account['settings'])) return $account['settings'][$name];
        return $this->{$name};
    }

    protected function syncPrimaryAccountSettings(?array $settings = null): void {
        $this->ensureAccountRegistry();
        $account = $this->accountRegistry()->require(1);
        $settings = $settings === null ? $this->moduleAccountSettings() : $this->normalizeAccountSettings($settings);
        $this->accountRegistry()->update(1, (string) $account['label'], $settings, (bool) $account['enabled']);
        $this->clearMailboxViewCache(1);
        $this->accountCache = [];
    }

    protected function moduleAccountSettings(): array {
        $settings = [];
        foreach($this->accountSettingNames() as $name) $settings[$name] = $this->{$name};
        return $this->normalizeAccountSettings($settings);
    }

    protected function accountSettingNames(): array {
        return [
            'preset', 'host', 'port', 'encryption', 'imapTransport', 'validateCertificate', 'secureAuthentication',
            'disableAuthenticator', 'connectionRetries', 'openTimeout', 'readTimeout', 'writeTimeout',
            'closeTimeout', 'defaultFolder', 'folderPattern', 'showUnselectableFolders',
            'messagesPerPage', 'maxBodyBytes', 'authentication', 'oauthProvider', 'oauthClientId',
            'oauthTenant',
            'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpValidateCertificate', 'smtpFromAddress', 'smtpFromName',
            'saveSentCopies', 'sentFolder',
            'maxAttachmentBytes', 'maxAgentAttachmentBytes', 'maxSearchResults', 'maxSearchFolders',
        ];
    }

    protected function normalizeAccountSettings(array $data): array {
        $preset = (string) ($data['preset'] ?? 'custom');
        if(!isset($this->getPresets()[$preset])) $preset = 'custom';
        $host = trim((string) ($data['host'] ?? ''));
        if($host !== '' && preg_match('/[{}\s\0]/', $host)) throw new WireException('A valid IMAP hostname is required.');
        $encryption = in_array(($data['encryption'] ?? ''), ['ssl', 'tls'], true) ? $data['encryption'] : 'ssl';
        $validate = empty($data['validateCertificate']) ? 0 : 1;
        if(!$validate && !$this->isLoopbackHost($host)) $validate = 1;
        $folder = trim((string) ($data['defaultFolder'] ?? 'INBOX'));
        $pattern = trim((string) ($data['folderPattern'] ?? '*'));
        if($folder === '' || preg_match('/[{}\r\n\0]/', $folder)) $folder = 'INBOX';
        if($pattern === '' || preg_match('/[{}\r\n\0]/', $pattern)) $pattern = '*';
        $oauthClientId = trim((string) ($data['oauthClientId'] ?? ''));
        $oauthTenant = trim((string) ($data['oauthTenant'] ?? 'common')) ?: 'common';
        $smtpHost = $this->validateSmtpHostValue((string) ($data['smtpHost'] ?? ''), true);
        $smtpValidate = empty($data['smtpValidateCertificate']) ? 0 : 1;
        if(!$smtpValidate && !$this->isLoopbackHost($smtpHost)) $smtpValidate = 1;
        $smtpFromAddress = trim((string) ($data['smtpFromAddress'] ?? ''));
        $smtpFromName = trim((string) ($data['smtpFromName'] ?? ''));
        if($smtpFromAddress !== '' && (!filter_var($smtpFromAddress, FILTER_VALIDATE_EMAIL) || strlen($smtpFromAddress) > 320)) throw new WireException('Invalid SMTP From address.');
        if(strlen($smtpFromName) > 190 || preg_match('/[\r\n\0]/', $smtpFromName)) throw new WireException('Invalid SMTP From name.');
        $sentFolder = trim((string) ($data['sentFolder'] ?? 'Sent')) ?: 'Sent';
        if(strlen($sentFolder) > 1024 || preg_match('/[{}\r\n\0]/', $sentFolder)) throw new WireException('Invalid Sent folder name.');
        if(strlen($oauthClientId) > 255 || preg_match('/[\x00-\x20]/', $oauthClientId)) throw new WireException('Invalid OAuth client ID.');
        if(strlen($oauthTenant) > 253 || !preg_match('/^(common|organizations|consumers|[a-f0-9-]{36}|[A-Za-z0-9.-]{1,253})$/', $oauthTenant)) throw new WireException('Invalid Microsoft OAuth tenant.');
        $maxAttachmentBytes = max(65536, min(52428800, (int) ($data['maxAttachmentBytes'] ?? 10485760)));
        return [
            'preset' => $preset,
            'host' => $host,
            'port' => max(1, min(65535, (int) ($data['port'] ?? 993))),
            'encryption' => $encryption,
            'imapTransport' => in_array(($data['imapTransport'] ?? 'auto'), ['auto', 'native', 'webklex'], true) ? $data['imapTransport'] : 'auto',
            'validateCertificate' => $validate,
            'secureAuthentication' => empty($data['secureAuthentication']) ? 0 : 1,
            'disableAuthenticator' => ($data['disableAuthenticator'] ?? '') === 'GSSAPI' ? 'GSSAPI' : '',
            'connectionRetries' => max(1, min(3, (int) ($data['connectionRetries'] ?? 1))),
            'openTimeout' => $this->normalizedTimeout($data['openTimeout'] ?? 15),
            'readTimeout' => $this->normalizedTimeout($data['readTimeout'] ?? 30),
            'writeTimeout' => $this->normalizedTimeout($data['writeTimeout'] ?? 30),
            'closeTimeout' => $this->normalizedTimeout($data['closeTimeout'] ?? 5),
            'defaultFolder' => $folder,
            'folderPattern' => $pattern,
            'showUnselectableFolders' => empty($data['showUnselectableFolders']) ? 0 : 1,
            'messagesPerPage' => max(10, min(100, (int) ($data['messagesPerPage'] ?? 100))),
            'maxBodyBytes' => max(16384, min(10485760, (int) ($data['maxBodyBytes'] ?? 1048576))),
            'authentication' => ($data['authentication'] ?? 'password') === 'oauth' ? 'oauth' : 'password',
            'oauthProvider' => in_array(($data['oauthProvider'] ?? ''), ['google', 'microsoft'], true) ? $data['oauthProvider'] : '',
            'oauthClientId' => $oauthClientId,
            'oauthTenant' => $oauthTenant,
            'smtpHost' => $smtpHost,
            'smtpPort' => max(1, min(65535, (int) ($data['smtpPort'] ?? 587))),
            'smtpEncryption' => ($data['smtpEncryption'] ?? 'tls') === 'ssl' ? 'ssl' : 'tls',
            'smtpValidateCertificate' => $smtpValidate,
            'smtpFromAddress' => $smtpFromAddress,
            'smtpFromName' => $smtpFromName,
            'saveSentCopies' => empty($data['saveSentCopies']) ? 0 : 1,
            'sentFolder' => $sentFolder,
            'maxAttachmentBytes' => $maxAttachmentBytes,
            'maxAgentAttachmentBytes' => min($maxAttachmentBytes, max(4096, min(5242880, (int) ($data['maxAgentAttachmentBytes'] ?? 524288)))),
            'maxSearchResults' => max(100, min(10000, (int) ($data['maxSearchResults'] ?? 1000))),
            'maxSearchFolders' => max(1, min(500, (int) ($data['maxSearchFolders'] ?? 100))),
        ];
    }
}

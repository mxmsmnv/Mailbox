<?php namespace ProcessWire;

/** Encrypted, account-scoped cache for the responsive admin mailbox view. */
trait MailboxCacheConcern {

    public function getCachedFolders(int $maxAge = 86400): ?array {
        return $this->indexStore()->getCache($this->currentAccountId(), 'folders', $maxAge);
    }

    public function cacheFolders(array $folders): array {
        return $this->indexStore()->putCache($this->currentAccountId(), 'folders', ['folders' => $folders]);
    }

    public function getCachedMessages(string $folder, int $page = 1, ?int $limit = null, int $maxAge = 86400): ?array {
        $this->validateFolderName($folder);
        $page = max(1, $page);
        $limit = max(1, min(100, $limit === null ? (int) $this->accountSetting('messagesPerPage') : $limit));
        return $this->indexStore()->getCache($this->currentAccountId(), $this->messageListCacheKey($folder, $page, $limit), $maxAge);
    }

    public function cacheMessages(string $folder, int $page, int $limit, array $result): array {
        $this->validateFolderName($folder);
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        return $this->indexStore()->putCache($this->currentAccountId(), $this->messageListCacheKey($folder, $page, $limit), ['result' => $result]);
    }

    public function getCachedMessage(string $folder, int $uid, int $maxAge = 86400): ?array {
        $this->validateFolderName($folder);
        if($uid < 1) throw new WireException('Invalid message UID.');
        return $this->indexStore()->getCache($this->currentAccountId(), 'message|' . $folder . '|' . $uid, $maxAge);
    }

    public function cacheMessage(string $folder, int $uid, array $message): array {
        $this->validateFolderName($folder);
        if($uid < 1) throw new WireException('Invalid message UID.');
        return $this->indexStore()->putCache($this->currentAccountId(), 'message|' . $folder . '|' . $uid, ['message' => $message]);
    }

    public function clearMailboxViewCache(?int $accountId = null): void {
        $this->indexStore()->clearCache($accountId ?: $this->currentAccountId());
    }

    private function messageListCacheKey(string $folder, int $page, int $limit): string {
        return 'messages|' . $folder . '|page:' . $page . '|limit:' . $limit;
    }
}

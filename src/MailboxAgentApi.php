<?php namespace ProcessWire;

/**
 * Session-user API. Controllers remain responsible for CSRF on mutations.
 */
final class MailboxAgentApi {

    /** @var Mailbox */
    private $mailbox;
    /** @var User */
    private $actor;
    /** @var int|null */
    private $accountId;

    public function __construct(Mailbox $mailbox, User $actor, ?int $accountId = null) {
        $this->mailbox = $mailbox;
        $this->actor = $actor;
        $this->accountId = $accountId;
    }

    public function canRead(): bool {
        return (bool) $this->mailbox->enableAgentApi
            && $this->actor->isLoggedin()
            && $this->actor->hasPermission(Mailbox::apiPermission);
    }

    public function canWrite(): bool {
        return $this->canRead() && (bool) $this->mailbox->enableMailMutations && $this->actor->hasPermission(Mailbox::writePermission);
    }

    public function canSend(): bool {
        return $this->canRead() && (bool) $this->mailbox->enableMailSending && $this->actor->hasPermission(Mailbox::sendPermission);
    }

    public function canReadAttachments(): bool {
        return $this->canRead() && $this->actor->hasPermission(Mailbox::attachmentPermission);
    }

    public function canUseSquad(): bool {
        if(!$this->canRead() || !(bool) $this->mailbox->enableSquadIntegration) return false;
        $status = $this->mailbox->squadStatus();
        return !empty($status['compatible']);
    }

    public function folders(): array {
        $this->assertCanRead();
        return $this->run(function(Mailbox $mailbox): array { return $mailbox->listFolders(); });
    }

    public function accounts(): array {
        $this->assertCanRead();
        $result = [];
        foreach($this->mailbox->getAccounts() as $account) {
            $result[] = [
                'id' => (int) $account['id'],
                'uuid' => (string) $account['uuid'],
                'label' => (string) $account['label'],
                'is_default' => (bool) $account['is_default'],
                'enabled' => (bool) $account['enabled'],
                'authentication' => (string) ($account['settings']['authentication'] ?? 'password'),
                'provider' => (string) ($account['settings']['oauthProvider'] ?? ''),
                'configured' => (bool) ($account['credentials']['configured'] ?? false),
            ];
        }
        return $result;
    }

    public function discover(string $email): array {
        $this->assertCanRead();
        return $this->mailbox->discoverMailboxSettings($email);
    }

    public function messages(string $folder = 'INBOX', int $page = 1, int $limit = 30): array {
        $this->assertCanRead();
        return $this->run(function(Mailbox $mailbox) use ($folder, $page, $limit): array { return $mailbox->listMessages($folder, $page, $limit); });
    }

    public function message(string $folder, int $uid): array {
        $this->assertCanRead();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid): array { return $mailbox->getAgentMessage($folder, $uid); });
    }

    public function search(array $filters = [], ?string $folder = null, int $page = 1, int $limit = 30): array {
        $this->assertCanRead();
        return $this->run(function(Mailbox $mailbox) use ($filters, $folder, $page, $limit): array {
            return $mailbox->searchMessages($filters, $folder, $page, $limit);
        });
    }

    public function indexedMessages(int $page = 1, int $limit = 30): array {
        $this->assertCanRead();
        return $this->run(function(Mailbox $mailbox) use ($page, $limit): array { return $mailbox->indexedMessages($page, $limit); });
    }

    public function notifications(int $limit = 50, bool $unreadOnly = true): array {
        $this->assertCanRead();
        return $this->run(function(Mailbox $mailbox) use ($limit, $unreadOnly): array { return $mailbox->mailboxNotifications($limit, $unreadOnly); });
    }

    public function markNotificationRead(int $id): bool {
        $this->assertCanRead();
        return $this->run(function(Mailbox $mailbox) use ($id): bool { return $mailbox->markMailboxNotificationRead($id); });
    }

    public function attachment(string $folder, int $uid, string $part): array {
        $this->assertCanReadAttachments();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid, $part): array { return $mailbox->getAttachment($folder, $uid, $part, $this->actorLabel()); });
    }

    public function attachmentText(string $folder, int $uid, string $part): array {
        $this->assertCanReadAttachments();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid, $part): array { return $mailbox->readAttachmentText($folder, $uid, $part, $this->actorLabel()); });
    }

    public function links(string $folder, int $uid): array {
        return $this->message($folder, $uid)['links'];
    }

    public function analyzeWithSquad(string $folder, int $uid, string $instruction, array $options = []): array {
        $this->assertCanSquad();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid, $instruction, $options): array {
            return $mailbox->analyzeMessageWithSquad($folder, $uid, $instruction, $options, $this->actorLabel());
        });
    }

    public function runSquadAgent(string $instruction, array $options = []): array {
        $this->assertCanSquad();
        return $this->run(function(Mailbox $mailbox) use ($instruction, $options): array {
            return $mailbox->runSquadAgent($instruction, $options, $this->actorLabel());
        });
    }

    public function setFlags(string $folder, int $uid, array $flags, bool $enabled): array {
        $this->assertCanWrite();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid, $flags, $enabled): array {
            return $mailbox->setMessageFlags($folder, $uid, $flags, $enabled, $this->actorLabel());
        });
    }

    public function move(string $folder, int $uid, string $destination, bool $expunge = false): array {
        $this->assertCanWrite();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid, $destination, $expunge): array {
            return $mailbox->moveMessage($folder, $uid, $destination, $expunge, $this->actorLabel());
        });
    }

    public function delete(string $folder, int $uid, bool $expunge = false): array {
        $this->assertCanWrite();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid, $expunge): array {
            return $mailbox->deleteMessage($folder, $uid, $expunge, $this->actorLabel());
        });
    }

    public function send(array $message): array {
        $this->assertCanSend();
        return $this->run(function(Mailbox $mailbox) use ($message): array { return $mailbox->sendMessage($message, $this->actorLabel()); });
    }

    public function testSmtp(): array {
        $this->assertCanSend();
        return $this->run(function(Mailbox $mailbox): array { return $mailbox->testSmtpConnection(); });
    }

    public function reply(string $folder, int $uid, string $body, bool $replyAll = false, array $attachments = []): array {
        $this->assertCanSend();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid, $body, $replyAll, $attachments): array {
            return $mailbox->replyMessage($folder, $uid, $body, $replyAll, $this->actorLabel(), $attachments);
        });
    }

    public function forward(string $folder, int $uid, array $to, string $body = '', array $attachments = []): array {
        $this->assertCanSend();
        return $this->run(function(Mailbox $mailbox) use ($folder, $uid, $to, $body, $attachments): array {
            return $mailbox->forwardMessage($folder, $uid, $to, $body, $this->actorLabel(), $attachments);
        });
    }

    public function proposeConfirmation(string $folder, int $uid, string $urlHash, array $workflow = []): array {
        $this->assertCanRead();
        $proposal = $this->run(function(Mailbox $mailbox) use ($folder, $uid, $urlHash, $workflow): array {
            return $mailbox->createConfirmationProposal($folder, $uid, $urlHash, $this->actorLabel(), $workflow);
        });
        return $this->redactProposal($proposal);
    }

    public function proposals(): array {
        $this->assertCanConfirm();
        return array_map([$this, 'redactProposal'], $this->mailbox->listConfirmationProposals());
    }

    public function approveConfirmation(string $id): array {
        $this->assertCanConfirm();
        return $this->redactProposal($this->mailbox->approveConfirmationProposal($id, $this->actorLabel()));
    }

    public function rejectConfirmation(string $id): array {
        $this->assertCanConfirm();
        return $this->redactProposal($this->mailbox->rejectConfirmationProposal($id, $this->actorLabel()));
    }

    public function executeConfirmation(string $id): array {
        $this->assertCanConfirm();
        $result = $this->mailbox->executeConfirmationProposal($id, $this->actorLabel());
        if(isset($result['proposal']) && is_array($result['proposal'])) $result['proposal'] = $this->redactProposal($result['proposal']);
        return $result;
    }

    private function assertCanRead(): void {
        if(!$this->canRead()) throw new WirePermissionException('Mailbox agent API access denied.');
    }

    private function assertCanConfirm(): void {
        $this->assertCanRead();
        if(!$this->actor->hasPermission(Mailbox::confirmationPermission)) {
            throw new WirePermissionException('Mailbox confirmation permission required.');
        }
    }

    private function assertCanWrite(): void {
        if(!$this->canWrite()) throw new WirePermissionException('Mailbox write permission and enabled mutation capability are required.');
    }

    private function assertCanSend(): void {
        if(!$this->canSend()) throw new WirePermissionException('Mailbox send permission and enabled SMTP capability are required.');
    }

    private function assertCanReadAttachments(): void {
        if(!$this->canReadAttachments()) throw new WirePermissionException('Mailbox attachment permission is required.');
    }

    private function assertCanSquad(): void {
        if(!$this->canUseSquad()) throw new WirePermissionException('Mailbox Squad integration access denied.');
    }

    private function actorLabel(): string {
        return 'user:' . (int) $this->actor->id;
    }

    private function redactProposal(array $proposal): array {
        if(isset($proposal['workflow']) && is_array($proposal['workflow'])) unset($proposal['workflow']['code_fingerprint']);
        return $proposal;
    }

    private function run(callable $operation) {
        if($this->accountId === null) return $operation($this->mailbox);
        return $this->mailbox->withAccount($this->accountId, $operation);
    }
}

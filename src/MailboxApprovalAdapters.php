<?php namespace ProcessWire;

interface MailboxApprovalAdapter {
    public function name(): string;
    public function publish(array $proposal): array;
}

final class MailboxVerkAdapter extends Wire implements MailboxApprovalAdapter {
    public function name(): string { return 'verk'; }

    public function publish(array $proposal): array {
        $modules = $this->wire()->modules;
        if(!$modules->isInstalled('Verk')) throw new WireException('Verk is not installed.');
        $verk = $modules->get('Verk');
        if(!is_object($verk) || !method_exists($verk, 'createExternalApproval')) throw new WireException('Verk 1.6.0 or newer is required.');
        $actor = $this->actorId($proposal);
        return $verk->createExternalApproval('mailbox', (string) $proposal['id'], $this->metadata($proposal), $actor);
    }

    private function actorId(array $proposal): int {
        if(!preg_match('/^user:(\d+)$/', (string) ($proposal['requested_by'] ?? ''), $match)) throw new WireException('Verk approvals require a logged-in ProcessWire requester.');
        $id = (int) $match[1];
        if(!$this->wire()->user->isLoggedin() || (int) $this->wire()->user->id !== $id) throw new WireException('Verk approval requester mismatch.');
        return $id;
    }

    private function metadata(array $proposal): array {
        $workflow = is_array($proposal['workflow'] ?? null) ? $proposal['workflow'] : [];
        return ['host' => (string) $proposal['host'], 'folder' => (string) $proposal['folder'], 'uid' => (int) $proposal['uid'], 'account_id' => (int) $proposal['account_id'], 'workflow' => ['mode' => (string) ($workflow['mode'] ?? 'get'), 'max_steps' => (int) ($workflow['max_steps'] ?? 1), 'code_required' => ($workflow['mode'] ?? '') === 'code_form']];
    }
}

final class MailboxKontorAdapter extends Wire implements MailboxApprovalAdapter {
    private $organizationUid;

    public function __construct(string $organizationUid) { $this->organizationUid = trim($organizationUid); }
    public function name(): string { return 'kontor'; }

    public function publish(array $proposal): array {
        if(!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $this->organizationUid)) throw new WireException('A valid Kontor organization UID is required.');
        $modules = $this->wire()->modules;
        if(!$modules->isInstalled('KontorAI')) throw new WireException('Kontor AI is not installed.');
        $kontor = $modules->get('KontorAI');
        if(!is_object($kontor) || !method_exists($kontor, 'submitExternalApproval')) throw new WireException('Kontor AI external approval API is unavailable.');
        $actor = $this->actorId($proposal);
        $pending = $kontor->submitExternalApproval('mailbox', (string) $proposal['id'], $this->organizationUid, [
            'host' => (string) $proposal['host'],
            'folder' => (string) $proposal['folder'],
            'uid' => (int) $proposal['uid'],
            'account_id' => (int) $proposal['account_id'],
            'workflow' => ['mode' => (string) ($proposal['workflow']['mode'] ?? 'get'), 'max_steps' => (int) ($proposal['workflow']['max_steps'] ?? 1), 'code_required' => ($proposal['workflow']['mode'] ?? '') === 'code_form'],
        ], $actor);
        return ['provider' => 'kontor', 'external_id' => (string) $proposal['id'], 'pending_uid' => $pending->uid->toString(), 'status' => $pending->status];
    }

    private function actorId(array $proposal): int {
        if(!preg_match('/^user:(\d+)$/', (string) ($proposal['requested_by'] ?? ''), $match)) throw new WireException('Kontor approvals require a logged-in ProcessWire requester.');
        $id = (int) $match[1];
        if(!$this->wire()->user->isLoggedin() || (int) $this->wire()->user->id !== $id) throw new WireException('Kontor approval requester mismatch.');
        return $id;
    }
}

<?php namespace ProcessWire;

/** Opt-in Squad AI analysis and bounded mailbox-agent bridge. */
trait MailboxSquadConcern {

    protected function setSquadDefaults(): void {
        $this->set('enableSquadIntegration', 0);
        $this->set('squadProviderModel', '');
        $this->set('squadMaxTokens', 800);
        $this->set('squadTemperature', '0.1');
        $this->set('squadTimeout', 30);
        $this->set('squadMaxSteps', 4);
    }

    public function squadStatus(): array {
        return $this->squadAdapter('backend')->status() + ['enabled' => (bool) $this->enableSquadIntegration];
    }

    public function squadModelOptions(): array {
        return $this->squadAdapter('backend')->modelOptions();
    }

    public function analyzeMessageWithSquad(string $folder, int $uid, string $instruction, array $options = [], string $actor = 'backend'): array {
        $this->assertSquadEnabled();
        $actor = $this->normalizedActor($actor);
        $result = $this->squadAdapter($actor)->analyze($folder, $uid, $instruction, $options);
        $this->auditSquad('squad_analyze', $folder, $uid, $actor, $result);
        return $result;
    }

    public function runSquadAgent(string $instruction, array $options = [], string $actor = 'backend'): array {
        $this->assertSquadEnabled();
        $actor = $this->normalizedActor($actor);
        $result = $this->squadAdapter($actor)->run($instruction, $options);
        $this->auditSquad('squad_agent', '', 0, $actor, $result);
        return $result;
    }

    private function squadAdapter(string $actor): MailboxSquadAdapter {
        return $this->wire(new MailboxSquadAdapter($this, $actor));
    }

    private function assertSquadEnabled(): void {
        if(!(int) $this->enableSquadIntegration) throw new WirePermissionException('Squad integration is disabled.');
    }

    private function auditSquad(string $event, string $folder, int $uid, string $actor, array $result): void {
        $this->wire()->log->save('mailbox-actions', json_encode([
            'event' => $event,
            'account_id' => $this->currentAccountId(),
            'folder_hash' => $folder !== '' ? hash('sha256', $folder) : '',
            'uid' => $uid,
            'actor' => $actor,
            'success' => !empty($result['success']),
            'provider' => (string) ($result['provider'] ?? ''),
            'model' => (string) ($result['model'] ?? ''),
            'steps' => (int) ($result['steps'] ?? 0),
            'time' => time(),
        ], JSON_UNESCAPED_SLASHES));
    }
}

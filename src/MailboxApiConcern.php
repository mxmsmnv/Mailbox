<?php namespace ProcessWire;

/** Public API transports and OAuth facade kept out of the module lifecycle shell. */
trait MailboxApiConcern {

    public function init() {
        if((int) $this->enableAgentApi && (int) $this->enableRestApi) $this->addHook('/mailbox-api/v1/{resource}/', $this, 'handleRestRequest');
    }

    public function handleRestRequest(HookEvent $event): string {
        return $this->rest()->handle((string) $event->arguments('resource'));
    }

    public function rest(): MailboxRestApi {
        if($this->restService === null) $this->restService = $this->wire(new MailboxRestApi($this));
        return $this->restService;
    }

    public function oauth(): MailboxOAuth {
        if($this->oauthService === null) $this->oauthService = $this->wire(new MailboxOAuth($this));
        return $this->oauthService;
    }

    public function beginOAuth(int $accountId, string $redirectUri): string {
        return $this->oauth()->authorizationUrl($accountId, $redirectUri);
    }

    public function completeOAuth(string $state, string $code, string $redirectUri): array {
        return $this->oauth()->complete($state, $code, $redirectUri);
    }

    public function disconnectOAuth(int $accountId): void {
        $this->oauth()->disconnect($accountId);
    }

    public function prepareOAuthIdentity(int $accountId, string $username, string $provider): void {
        $this->oauth()->prepareIdentity($accountId, $username, $provider);
    }
}

<?php namespace ProcessWire;

/** Credential-free mail service discovery facade. */
trait MailboxDiscoveryConcern {
    public function discoverMailboxSettings(string $email): array {
        return (new MailboxDiscovery($this->getPresets()))->discover($email);
    }
}

<?php namespace ProcessWire;

/** Agent facade and controlled link-confirmation workflow. */
trait MailboxConfirmationConcern {
    protected function registerConfiguredApprovalAdapters(): void {
        $selection = (string) $this->approvalIntegration;
        if(in_array($selection, ['verk', 'both'], true)) {
            $adapter = $this->wire(new MailboxVerkAdapter());
            $this->registerApprovalProvider($adapter->name(), function(array $proposal) use ($adapter): void { $adapter->publish($proposal); });
        }
        if(in_array($selection, ['kontor', 'both'], true)) {
            $adapter = $this->wire(new MailboxKontorAdapter((string) $this->kontorOrganizationUid));
            $this->registerApprovalProvider($adapter->name(), function(array $proposal) use ($adapter): void { $adapter->publish($proposal); });
        }
    }

    public function api(?User $actor = null, ?int $accountId = null): MailboxAgentApi {
        return new MailboxAgentApi($this, $actor ?: $this->wire()->user, $accountId);
    }

    /**
     * Stable, dependency-free capability manifest for Verk/Kontor/Squad adapters.
     */
    public function capabilities(): array {
        $channels = ['php_api' => (bool) $this->enableAgentApi, 'rest' => (bool) $this->enableAgentApi && (bool) $this->enableRestApi, 'cli' => (bool) $this->enableCli];
        $readEnabled = $channels['php_api'] || $channels['rest'] || $channels['cli'];
        return [
            'provider' => 'Mailbox',
            'version' => '1.0.0',
            'channels' => $channels,
            'capabilities' => [
                ['name' => 'mailbox.read', 'version' => '1.0.0', 'enabled' => $readEnabled],
                ['name' => 'mailbox.accounts', 'version' => '1.0.0', 'enabled' => $readEnabled],
                ['name' => 'mailbox.settings.discover', 'version' => '1.0.0', 'enabled' => $readEnabled],
                ['name' => 'mailbox.links.extract', 'version' => '1.0.0', 'enabled' => $readEnabled],
                ['name' => 'mailbox.links.confirm', 'version' => '1.0.0', 'enabled' => $readEnabled && (bool) $this->enableLinkConfirmations],
                ['name' => 'mailbox.links.confirm.form', 'version' => '1.0.0', 'enabled' => $readEnabled && (bool) $this->enableLinkConfirmations && (bool) $this->enableAdvancedConfirmations],
                ['name' => 'mailbox.messages.mutate', 'version' => '1.0.0', 'enabled' => ($channels['php_api'] || $channels['cli']) && (bool) $this->enableMailMutations],
                ['name' => 'mailbox.messages.send', 'version' => '1.0.0', 'enabled' => ($channels['php_api'] || $channels['cli']) && (bool) $this->enableMailSending],
                ['name' => 'mailbox.messages.search', 'version' => '1.0.0', 'enabled' => $readEnabled],
                ['name' => 'mailbox.ai.squad', 'version' => '1.0.0', 'enabled' => $readEnabled && (bool) $this->enableSquadIntegration],
                ['name' => 'mailbox.attachments.read', 'version' => '1.0.0', 'enabled' => $readEnabled],
                ['name' => 'mailbox.index.read', 'version' => '1.0.0', 'enabled' => $readEnabled && (bool) $this->enableBackgroundSync],
                ['name' => 'mailbox.notifications.read', 'version' => '1.0.0', 'enabled' => $readEnabled && (bool) $this->enableBackgroundSync],
                ['name' => 'mailbox.messages.indexed-hook', 'version' => '1.0.0', 'enabled' => (bool) $this->enableBackgroundSync],
                ['name' => 'mailbox.notifications.webhook', 'version' => '1.0.0', 'enabled' => (bool) $this->enableBackgroundSync && (bool) $this->enableWebhookNotifications],
            ],
            'approval_integration' => (string) $this->approvalIntegration,
        ];
    }

    /**
     * Register a proposal sink. Integrations may create a Verk task or a
     * Kontor approval item without Mailbox depending on either module.
     */
    public function registerApprovalProvider(string $name, callable $provider): void {
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/i', $name)) {
            throw new WireException('Invalid approval provider name.');
        }
        $this->approvalProviders[$name] = $provider;
    }

    public function createConfirmationProposal(string $folder, int $uid, string $urlHash, string $actor, array $workflow = []): array {
        $this->assertConfirmationFeatureEnabled();
        $actor = $this->normalizedActor($actor);
        $link = $this->findMessageLink($folder, $uid, $urlHash);
        if(empty($link['confirmation_candidate'])) {
            throw new WireException('The selected link is not classified as a confirmation candidate.');
        }
        $this->assertAllowedConfirmationUrl($link['url']);
        $workflow = $this->normalizeConfirmationWorkflow($workflow, $folder, $uid);
        $proposal = $this->approvalStore()->create($folder, $uid, $link, $actor, $this->currentAccountId(), $workflow);
        foreach($this->approvalProviders as $providerName => $provider) {
            try {
                $provider($proposal, $this);
            } catch(\Throwable $error) {
                $this->wire()->log->save('mailbox-actions', 'Approval provider ' . $providerName . ' failed (' . get_class($error) . ').');
            }
        }
        $this->auditAction('proposed', $proposal, $actor);
        return $this->publicConfirmationProposal($proposal);
    }

    public function approveConfirmationProposal(string $id, string $actor): array {
        $this->assertConfirmationFeatureEnabled();
        $actor = $this->normalizedActor($actor);
        $proposal = $this->approvalStore()->approve($id, $actor);
        $this->auditAction('approved', $proposal, $actor);
        return $this->publicConfirmationProposal($proposal);
    }

    public function rejectConfirmationProposal(string $id, string $actor): array {
        $this->assertConfirmationFeatureEnabled();
        $actor = $this->normalizedActor($actor);
        $proposal = $this->approvalStore()->reject($id, $actor);
        $this->auditAction('rejected', $proposal, $actor);
        return $this->publicConfirmationProposal($proposal);
    }

    public function executeConfirmationProposal(string $id, string $actor): array {
        $this->assertConfirmationFeatureEnabled();
        $actor = $this->normalizedActor($actor);
        $store = $this->approvalStore();
        $proposal = $store->claimExecution($id, $actor);
        try {
            $accountId = max(1, (int) ($proposal['account_id'] ?? 1));
            $result = $this->withAccount($accountId, function() use ($proposal): array {
                $link = $this->findMessageLink((string) $proposal['folder'], (int) $proposal['uid'], (string) $proposal['url_hash']);
                $this->assertAllowedConfirmationUrl($link['url']);
                $client = new MailboxConfirmationClient(
                    $this->allowedConfirmationHostList(),
                    max(1, min(30, (int) $this->confirmationTimeout)),
                    max(4096, min(1048576, (int) $this->maxConfirmationResponseBytes))
                );
                $workflow = is_array($proposal['workflow'] ?? null) ? $proposal['workflow'] : ['mode' => 'get', 'max_steps' => 1];
                $code = null;
                if(($workflow['mode'] ?? 'get') === 'code_form') {
                    $code = $this->findMessageConfirmationCode((string) $proposal['folder'], (int) $proposal['uid']);
                    $fingerprint = $this->indexStore()->sensitiveFingerprint($this->currentAccountId(), 'confirmation-code', $code);
                    if(empty($workflow['code_fingerprint']) || !hash_equals((string) $workflow['code_fingerprint'], $fingerprint)) throw new WireException('The confirmation code no longer matches the approved proposal.');
                }
                return $client->execute($link['url'], $workflow, $code);
            });
            $proposal = $store->markExecuted($id, $actor, $result);
            $this->auditAction('executed', $proposal, $actor);
            return ['proposal' => $this->publicConfirmationProposal($proposal), 'result' => $result];
        } catch(\Throwable $error) {
            $proposal = $store->markExecutionFailed($id, $actor, $error->getMessage());
            $this->auditAction('failed', $proposal, $actor);
            throw $error;
        }
    }

    public function listConfirmationProposals(): array {
        return array_map([$this, 'publicConfirmationProposal'], $this->approvalStore()->all());
    }

    protected function publicConfirmationProposal(array $proposal): array {
        if(isset($proposal['workflow']) && is_array($proposal['workflow'])) unset($proposal['workflow']['code_fingerprint']);
        return $proposal;
    }

    /**
     * Verify the extension, configuration, credentials, selected folder, and folder listing.
     */

    protected function htmlToText(string $html): string {
        $html = preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', '', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|tr|h[1-6])>#i', "\n", $html);
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Extract normalized HTTP(S) links without exposing raw message HTML.
     */
    protected function extractLinks(string $plain, string $html, string $subject = ''): array {
        $found = [];
        if($html !== '' && class_exists('DOMDocument')) {
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            if(@$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                foreach($document->getElementsByTagName('a') as $anchor) {
                    $url = html_entity_decode(trim((string) $anchor->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $label = $this->normalizeText((string) $anchor->textContent);
                    $this->addExtractedLink($found, $url, $label);
                }
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if(preg_match_all('~https?://[^\s<>"\']+~iu', $plain, $matches)) {
            foreach($matches[0] as $url) {
                $url = rtrim($url, ".,;:!?)]}'\"");
                $this->addExtractedLink($found, $url, '');
            }
        }
        if(count($found) === 1 && $this->isConfirmationText($subject)) {
            $key = array_key_first($found);
            $found[$key]['confirmation_candidate'] = true;
        }
        return array_values($found);
    }

    protected function addExtractedLink(array &$found, string $url, string $label): void {
        if(strlen($url) > 4096 || !filter_var($url, FILTER_VALIDATE_URL)) return;
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if(!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) return;
        $hash = hash('sha256', $url);
        if(isset($found[$hash])) return;
        $candidate = $this->isConfirmationText($label . ' ' . ($parts['path'] ?? ''));
        $found[$hash] = [
            'hash' => $hash,
            'url' => $url,
            'host' => $host,
            'path' => (string) ($parts['path'] ?? '/'),
            'label' => $label,
            'scheme' => $scheme,
            'confirmation_candidate' => $candidate,
        ];
    }

    protected function isConfirmationText(string $value): bool {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return (bool) preg_match('/\b(confirm|confirmation|verify|verification|activate|activation|approve|accept|подтверд|активир|верифиц)\w*/u', $value);
    }

    protected function findMessageLink(string $folder, int $uid, string $urlHash): array {
        if(!preg_match('/^[a-f0-9]{64}$/', $urlHash)) throw new WireException('Invalid link hash.');
        $message = $this->getMessage($folder, $uid);
        foreach($message['links'] as $link) {
            if(hash_equals((string) $link['hash'], $urlHash)) return $link;
        }
        throw new WireException('The confirmation link is no longer present in the message.');
    }

    protected function normalizeConfirmationWorkflow(array $workflow, string $folder, int $uid): array {
        foreach(array_keys($workflow) as $key) if(!in_array($key, ['mode', 'max_steps', 'code_field'], true)) throw new WireException('Unsupported confirmation workflow option.');
        if(isset($workflow['mode']) && !is_string($workflow['mode'])) throw new WireException('Confirmation workflow mode must be a string.');
        if(isset($workflow['max_steps']) && !is_int($workflow['max_steps']) && !(is_string($workflow['max_steps']) && ctype_digit($workflow['max_steps']))) throw new WireException('Confirmation workflow step count must be an integer.');
        if(isset($workflow['code_field']) && !is_string($workflow['code_field'])) throw new WireException('Confirmation code field must be a string.');
        $mode = strtolower((string) ($workflow['mode'] ?? 'get'));
        if(!in_array($mode, ['get', 'form', 'code_form'], true)) throw new WireException('Invalid confirmation workflow mode.');
        if($mode !== 'get' && !(int) $this->enableAdvancedConfirmations) throw new WireException('Advanced confirmation forms are disabled.');
        $result = ['mode' => $mode, 'max_steps' => $mode === 'get' ? 1 : max(1, min(3, (int) ($workflow['max_steps'] ?? 1)))];
        if($mode === 'code_form') {
            $field = trim((string) ($workflow['code_field'] ?? ''));
            if($field !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $field)) throw new WireException('Invalid confirmation code field name.');
            $code = $this->findMessageConfirmationCode($folder, $uid);
            $result['code_field'] = $field;
            $result['code_fingerprint'] = $this->indexStore()->sensitiveFingerprint($this->currentAccountId(), 'confirmation-code', $code);
        }
        return $result;
    }

    protected function findMessageConfirmationCode(string $folder, int $uid): string {
        $message = $this->getMessage($folder, $uid);
        $text = (string) ($message['subject'] ?? '') . "\n" . (string) ($message['body'] ?? '');
        $codes = [];
        if(preg_match_all('/(?:verification\s+code|confirmation\s+code|security\s+code|one[- ]time(?:\s+code)?|otp|pin|code|код(?:\s+подтверждения)?)[^\p{L}\p{N}]{0,24}([0-9]{4,10}|[A-Z0-9]{4,10}|[A-Z0-9]{2,5}(?:[ -][A-Z0-9]{2,5}){1,2})\b/iu', $text, $matches)) {
            foreach($matches[1] as $candidate) {
                $code = (string) preg_replace('/[ -]+/', '', (string) $candidate);
                if(strlen($code) < 4 || strlen($code) > 10 || !preg_match('/^[A-Za-z0-9]+$/', $code) || !preg_match('/[0-9]/', $code)) continue;
                $codes[$code] = $code;
            }
        }
        if(count($codes) !== 1) throw new WireException('The message must contain exactly one unambiguous confirmation code.');
        return (string) reset($codes);
    }

    protected function approvalStore(): MailboxApprovalStore {
        $directory = rtrim((string) $this->wire()->config->paths->assets, '/\\') . '/Mailbox';
        return new MailboxApprovalStore($directory . '/confirmation-proposals.json');
    }

    protected function assertConfirmationFeatureEnabled(): void {
        if(!(int) $this->enableLinkConfirmations) throw new WireException('Mailbox link confirmations are disabled.');
        if(!$this->allowedConfirmationHostList()) throw new WireException('No confirmation hosts are allowlisted.');
    }

    protected function allowedConfirmationHostList(): array {
        $values = preg_split('/[\s,]+/', strtolower((string) $this->allowedConfirmationHosts), -1, PREG_SPLIT_NO_EMPTY);
        $hosts = [];
        foreach($values ?: [] as $host) {
            $host = rtrim(trim($host), '.');
            $hostname = strpos($host, '*.') === 0 ? substr($host, 2) : $host;
            if(filter_var($hostname, FILTER_VALIDATE_IP)) continue;
            if(!preg_match('/^(\*\.)?[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host)) continue;
            $valid = true;
            foreach(explode('.', $hostname) as $label) {
                if($label === '' || strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') $valid = false;
            }
            if($valid) $hosts[$host] = $host;
        }
        return array_values($hosts);
    }

    protected function assertAllowedConfirmationUrl(string $url): void {
        MailboxConfirmationClient::assertUrlAllowed($url, $this->allowedConfirmationHostList());
    }

    protected function auditAction(string $event, array $proposal, string $actor): void {
        $this->wire()->log->save('mailbox-actions', json_encode([
            'event' => $event,
            'proposal_id' => $proposal['id'] ?? '',
            'folder_hash' => hash('sha256', (string) ($proposal['folder'] ?? '')),
            'uid' => (int) ($proposal['uid'] ?? 0),
            'account_id' => (int) ($proposal['account_id'] ?? 1),
            'url_hash' => $proposal['url_hash'] ?? '',
            'host' => $proposal['host'] ?? '',
            'actor' => $actor,
            'time' => time(),
        ], JSON_UNESCAPED_SLASHES));
    }

    protected function normalizedActor(string $actor): string {
        $actor = trim($actor);
        if($actor !== '' && strlen($actor) <= 100 && preg_match('/^[a-z0-9:_.@-]+$/i', $actor)) return $actor;
        return 'integration:' . substr(hash('sha256', $actor), 0, 16);
    }
}

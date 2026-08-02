<?php namespace ProcessWire;

/** Guided, credential-free mailbox settings discovery admin page. */
trait ProcessMailboxDiscoveryConcern {

    public function ___executeDiscover(): string {
        $this->configureSectionChrome($this->_('Discover mail settings'));
        $email = trim((string) $this->wire()->input->post->email);
        $resultMarkup = '';
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
            try {
                $resultMarkup = $this->renderDiscoveryResult($this->mailbox->discoverMailboxSettings($email));
            } catch(\Throwable $error) {
                $resultMarkup = "<div class='uk-alert-danger Mailbox-discovery-error' uk-alert><h3 class='uk-h4'>" . $this->_('Discovery could not be completed') . "</h3><p>" . $this->e($error->getMessage()) . "</p><p class='uk-text-small'>" . $this->_('Check the address and try again. No mailbox settings were changed.') . "</p></div>";
            }
        }
        return "<div class='ProcessMailbox pw-module-workspace'>" . $this->renderTabs('discover') . $this->renderDiscoveryIntro() . $this->renderDiscoveryForm($email) . $resultMarkup . '</div>';
    }

    protected function renderDiscoveryIntro(): string {
        return "<section class='Mailbox-discovery-hero'><div class='Mailbox-discovery-hero-icon'><span uk-icon='icon:search;ratio:1.35'></span></div><div><span class='Mailbox-eyebrow'>" . $this->_('Credential-free helper') . "</span><h2 class='uk-h2 uk-margin-small-top uk-margin-small-bottom'>" . $this->_('Find the likely mail server settings for an address') . "</h2><p class='uk-text-lead uk-margin-remove'>" . $this->_('Enter only the mailbox email address. Mailbox checks known provider presets, secure DNS service records, and the provider’s HTTPS autoconfiguration. It returns candidates for review—it does not connect to the mailbox, request a password, or save anything.') . "</p></div></section>
        <div class='Mailbox-discovery-assurances'><div><span uk-icon='icon:lock;ratio:.9'></span><strong>" . $this->_('No password') . "</strong><span>" . $this->_('Credentials are never requested or used.') . "</span></div><div><span uk-icon='icon:file-edit;ratio:.9'></span><strong>" . $this->_('No automatic changes') . "</strong><span>" . $this->_('You review and save settings manually.') . "</span></div><div><span uk-icon='icon:check;ratio:.9'></span><strong>" . $this->_('Tests stay separate') . "</strong><span>" . $this->_('IMAP and SMTP are tested only after saving.') . "</span></div></div>
        <section class='uk-card uk-card-default uk-card-small uk-card-body Mailbox-discovery-guide'><h3 class='uk-h4 uk-margin-remove-bottom'>" . $this->_('How to use this page') . "</h3><ol class='Mailbox-discovery-steps'><li><span>1</span><div><strong>" . $this->_('Enter the mailbox address') . "</strong><small>" . $this->_('For example, support@example.com. Do not enter a password.') . "</small></div></li><li><span>2</span><div><strong>" . $this->_('Review the candidates') . "</strong><small>" . $this->_('Compare the suggested hosts and ports with your provider or hosting panel.') . "</small></div></li><li><span>3</span><div><strong>" . $this->_('Copy settings into an account') . "</strong><small>" . $this->_('Open Accounts for a new mailbox or Settings for the primary mailbox.') . "</small></div></li><li><span>4</span><div><strong>" . $this->_('Run both connection tests') . "</strong><small>" . $this->_('A candidate is not verified until IMAP and SMTP authentication succeed.') . "</small></div></li></ol></section>";
    }

    protected function renderDiscoveryForm(string $email): string {
        return "<section class='uk-card uk-card-default uk-card-body Mailbox-form-panel Mailbox-discovery-form'><div><span class='Mailbox-eyebrow'>" . $this->_('Start discovery') . "</span><h3 class='uk-h3 uk-margin-small-top uk-margin-remove-bottom'>" . $this->_('Which mailbox do you want to configure?') . "</h3><p class='uk-text-muted uk-margin-small-top'>" . $this->_('The address identifies the provider and suggested username. The provider’s autoconfig host may receive this email address during HTTPS discovery.') . "</p></div><form method='post'>" . $this->wire()->session->CSRF->renderInput() . "<label class='uk-form-label' for='MailboxDiscoverEmail'>" . $this->_('Mailbox email address') . "</label><div class='Mailbox-discovery-input'><span class='uk-form-icon' uk-icon='mail'></span><input class='uk-input' id='MailboxDiscoverEmail' type='email' name='email' maxlength='320' autocomplete='email' inputmode='email' placeholder='name@example.com' required value='" . $this->e($email) . "'><button class='uk-button uk-button-primary' type='submit'><span uk-icon='search' class='uk-margin-small-right'></span>" . $this->_('Find settings') . "</button></div><p class='uk-text-meta uk-margin-small-top'><span uk-icon='icon:info;ratio:.72'></span> " . $this->_('Discovery usually takes a few seconds. It performs no login attempt.') . "</p></form></section>";
    }

    protected function renderDiscoveryResult(array $result): string {
        $candidates = (array) ($result['candidates'] ?? []);
        if(!$candidates) {
            return "<section class='uk-card uk-card-default uk-card-body Mailbox-discovery-empty'><span uk-icon='icon:warning;ratio:1.2'></span><div><h3 class='uk-h3 uk-margin-remove'>" . $this->_('No safe automatic settings were found') . "</h3><p class='uk-text-muted uk-margin-small-top'>" . $this->_('This does not mean the mailbox is unavailable. Choose a preset or copy the IMAP/SMTP values from the provider or hosting control panel.') . "</p>" . $this->renderDiscoveryActions() . "</div></section>";
        }
        $cards = '';
        foreach($candidates as $index => $candidate) $cards .= $this->renderDiscoveryCandidate((array) $candidate, (int) $index + 1);
        return "<section class='Mailbox-discovery-results'><header><div><span class='Mailbox-eyebrow'>" . $this->_('Review required') . "</span><h2 class='uk-h2 uk-margin-small-top uk-margin-remove-bottom'>" . sprintf($this->_('%d configuration candidate(s) found'), count($candidates)) . "</h2><p class='uk-text-muted uk-margin-small-top'>" . $this->_('Nothing has been saved or tested. Prefer a high-confidence provider result, but always compare it with official provider information.') . "</p></div>" . $this->renderDiscoveryActions() . "</header><div class='Mailbox-discovery-candidates'>{$cards}</div></section>";
    }

    protected function renderDiscoveryCandidate(array $candidate, int $index): string {
        $settings = (array) ($candidate['settings'] ?? []);
        $source = $this->discoverySourceLabel((string) ($candidate['source'] ?? ''));
        $confidence = strtolower((string) ($candidate['confidence'] ?? 'medium')) === 'high' ? $this->_('High confidence') : $this->_('Needs verification');
        $confidenceClass = strtolower((string) ($candidate['confidence'] ?? '')) === 'high' ? 'is-high' : 'is-medium';
        $username = (string) ($candidate['username'] ?? '');
        $authentication = (string) ($settings['authentication'] ?? 'password') === 'oauth' ? 'OAuth 2.0' : $this->_('Password / app password');
        $imapSecurity = (string) ($settings['encryption'] ?? '') === 'ssl' ? 'TLS/SSL' : 'STARTTLS';
        $smtpSecurity = (string) ($settings['smtpEncryption'] ?? '') === 'ssl' ? 'TLS/SSL' : 'STARTTLS';
        $smtpHost = (string) ($settings['smtpHost'] ?? '');
        $copy = [
            'Username: ' . $username,
            'Authentication: ' . $authentication,
            'IMAP host: ' . (string) ($settings['host'] ?? ''),
            'IMAP port: ' . (int) ($settings['port'] ?? 0),
            'IMAP security: ' . $imapSecurity,
        ];
        if($smtpHost !== '') $copy = array_merge($copy, ['SMTP host: ' . $smtpHost, 'SMTP port: ' . (int) ($settings['smtpPort'] ?? 0), 'SMTP security: ' . $smtpSecurity]);
        $copyId = 'MailboxDiscoveryCopy' . $index;
        $warnings = '';
        foreach((array) ($candidate['warnings'] ?? []) as $warning) $warnings .= '<li>' . $this->e((string) $warning) . '</li>';
        $warningMarkup = $warnings !== '' ? "<div class='Mailbox-discovery-warning'><span uk-icon='warning'></span><ul>{$warnings}</ul></div>" : '';
        $smtpMarkup = $smtpHost !== '' ? "<div class='Mailbox-endpoint'><span class='Mailbox-endpoint-icon' uk-icon='push'></span><div><small>SMTP · " . $this->_('Outgoing mail') . "</small><strong>" . $this->e($smtpHost) . ':' . (int) ($settings['smtpPort'] ?? 0) . "</strong><span>{$smtpSecurity}</span></div></div>" : "<div class='Mailbox-endpoint is-missing'><span class='Mailbox-endpoint-icon' uk-icon='minus-circle'></span><div><small>SMTP · " . $this->_('Outgoing mail') . "</small><strong>" . $this->_('Not discovered') . "</strong><span>" . $this->_('Enter it manually before the SMTP test.') . "</span></div></div>";
        $json = json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return "<article class='uk-card uk-card-default Mailbox-discovery-candidate'><header><div><span class='Mailbox-candidate-number'>{$index}</span><div><h3 class='uk-h3 uk-margin-remove'>{$source}</h3><p class='uk-text-meta uk-margin-remove-top'>" . $this->_('Suggested username') . ': ' . $this->e($username) . "</p></div></div><span class='Mailbox-confidence {$confidenceClass}'>{$confidence}</span></header><div class='Mailbox-candidate-body'><div class='Mailbox-endpoints'><div class='Mailbox-endpoint'><span class='Mailbox-endpoint-icon' uk-icon='download'></span><div><small>IMAP · " . $this->_('Incoming mail') . "</small><strong>" . $this->e((string) ($settings['host'] ?? '')) . ':' . (int) ($settings['port'] ?? 0) . "</strong><span>{$imapSecurity}</span></div></div>{$smtpMarkup}</div><dl class='Mailbox-candidate-meta'><div><dt>" . $this->_('Authentication') . "</dt><dd>{$authentication}</dd></div><div><dt>" . $this->_('Certificate validation') . "</dt><dd>" . $this->_('Required') . "</dd></div><div><dt>" . $this->_('Default folder') . "</dt><dd>" . $this->e((string) ($settings['defaultFolder'] ?? 'INBOX')) . "</dd></div></dl>{$warningMarkup}<div class='Mailbox-candidate-actions'><button class='uk-button uk-button-primary uk-button-small' type='button' data-mailbox-copy-target='{$copyId}' data-label-default='" . $this->e($this->_('Copy settings')) . "' data-label-copied='" . $this->e($this->_('Copied')) . "'><span uk-icon='copy' class='uk-margin-small-right'></span>" . $this->_('Copy settings') . "</button><details><summary>" . $this->_('Technical details') . "</summary><pre>" . $this->e((string) $json) . "</pre></details></div><pre id='{$copyId}' class='Mailbox-copy-source' aria-hidden='true'>" . $this->e(implode("\n", $copy)) . "</pre></div></article>";
    }

    protected function renderDiscoveryActions(): string {
        $base = rtrim((string) $this->wire()->page->url, '/');
        $settings = $this->settingsUrl();
        return "<div class='Mailbox-discovery-actions'><a class='uk-button uk-button-default uk-button-small' href='" . $this->e($base . '/accounts/') . "'><span uk-icon='list' class='uk-margin-small-right'></span>" . $this->_('Open accounts') . "</a><a class='uk-button uk-button-text uk-button-small' href='" . $this->e($settings) . "'>" . $this->_('Primary mailbox settings') . "</a></div>";
    }

    protected function discoverySourceLabel(string $source): string {
        $labels = ['built-in-preset' => $this->_('Known provider preset'), 'dns-srv' => $this->_('DNS service records'), 'provider-autoconfig' => $this->_('Provider HTTPS autoconfig'), 'provider-well-known' => $this->_('Domain HTTPS autoconfig')];
        return $labels[$source] ?? $this->_('Configuration candidate');
    }
}

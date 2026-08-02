<?php namespace ProcessWire;

/** Configuration normalization, credential persistence hooks, and admin fields. */
trait MailboxConfigConcern {
    protected function setDisplayDefaults(): void {
        $this->set('defaultFolder', 'INBOX');
        $this->set('folderPattern', '*');
        $this->set('showUnselectableFolders', 1);
        $this->set('messagesPerPage', 100);
        $this->set('maxBodyBytes', 1048576);
        $this->set('allowRemoteMessageImages', 0);
        $this->set('allowExternalMessageLinks', 0);
    }

    public function normalizeConfigBeforeSave(HookEvent $event): void {
        $module = $event->arguments(0);
        if($module !== $this && $module !== $this->className() && $module !== 'Mailbox') return;
        if($this->savingSanitizedConfig) return;

        $data = $event->arguments(1);
        if(!is_array($data)) return;

        $presets = $this->getPresets();
        $data['preset'] = isset($presets[$data['preset'] ?? '']) ? (string) $data['preset'] : 'custom';
        $data['host'] = trim((string) ($data['host'] ?? $this->host));
        $data['port'] = max(1, min(65535, (int) ($data['port'] ?? $this->port)));
        $data['encryption'] = in_array(($data['encryption'] ?? ''), ['ssl', 'tls'], true) ? $data['encryption'] : 'ssl';
        $data['imapTransport'] = in_array(($data['imapTransport'] ?? 'auto'), ['auto', 'native', 'webklex'], true) ? $data['imapTransport'] : 'auto';
        $data['validateCertificate'] = empty($data['validateCertificate']) ? 0 : 1;
        if(!$data['validateCertificate'] && !$this->isLoopbackHost($data['host'])) $data['validateCertificate'] = 1;
        $data['secureAuthentication'] = empty($data['secureAuthentication']) ? 0 : 1;
        $data['disableAuthenticator'] = in_array(($data['disableAuthenticator'] ?? ''), ['', 'GSSAPI'], true) ? $data['disableAuthenticator'] : '';
        $data['connectionRetries'] = max(1, min(3, (int) ($data['connectionRetries'] ?? $this->connectionRetries)));
        foreach(['openTimeout', 'readTimeout', 'writeTimeout', 'closeTimeout'] as $timeoutName) {
            $data[$timeoutName] = $this->normalizedTimeout($data[$timeoutName] ?? $this->{$timeoutName});
        }
        $data['defaultFolder'] = trim((string) ($data['defaultFolder'] ?? $this->defaultFolder));
        if($data['defaultFolder'] === '' || preg_match('/[{}\r\n\0]/', $data['defaultFolder'])) $data['defaultFolder'] = 'INBOX';
        $data['folderPattern'] = trim((string) ($data['folderPattern'] ?? $this->folderPattern));
        if($data['folderPattern'] === '' || preg_match('/[{}\r\n\0]/', $data['folderPattern'])) $data['folderPattern'] = '*';
        $data['showUnselectableFolders'] = empty($data['showUnselectableFolders']) ? 0 : 1;
        $data['allowRemoteMessageImages'] = empty($data['allowRemoteMessageImages']) ? 0 : 1;
        $data['allowExternalMessageLinks'] = empty($data['allowExternalMessageLinks']) ? 0 : 1;
        $data['messagesPerPage'] = max(10, min(100, (int) ($data['messagesPerPage'] ?? $this->messagesPerPage)));
        $data['maxBodyBytes'] = max(16384, min(10485760, (int) ($data['maxBodyBytes'] ?? $this->maxBodyBytes)));
        $data['maxAttachmentBytes'] = max(65536, min(52428800, (int) ($data['maxAttachmentBytes'] ?? $this->maxAttachmentBytes)));
        $data['maxAgentAttachmentBytes'] = max(4096, min(5242880, (int) ($data['maxAgentAttachmentBytes'] ?? $this->maxAgentAttachmentBytes)));
        $data['maxAgentAttachmentBytes'] = min($data['maxAttachmentBytes'], $data['maxAgentAttachmentBytes']);
        $data['maxSearchResults'] = max(100, min(10000, (int) ($data['maxSearchResults'] ?? $this->maxSearchResults)));
        $data['maxSearchFolders'] = max(1, min(500, (int) ($data['maxSearchFolders'] ?? $this->maxSearchFolders)));
        $data['maxSyncFolders'] = max(1, min(500, (int) ($data['maxSyncFolders'] ?? $this->maxSyncFolders)));
        $data['maxSyncMessagesPerFolder'] = max(10, min(1000, (int) ($data['maxSyncMessagesPerFolder'] ?? $this->maxSyncMessagesPerFolder)));
        $data['syncJobsPerRun'] = max(1, min(20, (int) ($data['syncJobsPerRun'] ?? $this->syncJobsPerRun)));
        $data['syncHistoryDays'] = max(1, min(3650, (int) ($data['syncHistoryDays'] ?? $this->syncHistoryDays)));
        $syncIntervals = ['everyMinute', 'every5Minutes', 'every15Minutes', 'every30Minutes', 'everyHour'];
        $data['syncInterval'] = in_array(($data['syncInterval'] ?? ''), $syncIntervals, true) ? $data['syncInterval'] : 'every5Minutes';
        $data['authentication'] = ($data['authentication'] ?? 'password') === 'oauth' ? 'oauth' : 'password';
        $data['oauthProvider'] = in_array(($data['oauthProvider'] ?? ''), ['google', 'microsoft'], true) ? $data['oauthProvider'] : '';
        $data['oauthClientId'] = trim((string) ($data['oauthClientId'] ?? ''));
        $data['oauthTenant'] = trim((string) ($data['oauthTenant'] ?? 'common')) ?: 'common';
        $oauthUsername = trim((string) ($data['oauthUsername'] ?? ''));
        if(strlen($data['oauthClientId']) > 255 || preg_match('/[\x00-\x20]/', $data['oauthClientId'])) throw new WireException('Invalid OAuth client ID.');
        if(strlen($oauthUsername) > 320 || preg_match('/[\x00-\x20]/', $oauthUsername)) throw new WireException('Invalid OAuth mailbox username.');
        if(strlen($data['oauthTenant']) > 253 || !preg_match('/^(common|organizations|consumers|[a-f0-9-]{36}|[A-Za-z0-9.-]{1,253})$/', $data['oauthTenant'])) throw new WireException('Invalid Microsoft OAuth tenant.');
        $data['smtpHost'] = $this->validateSmtpHostValue((string) ($data['smtpHost'] ?? ''), true);
        $data['smtpPort'] = max(1, min(65535, (int) ($data['smtpPort'] ?? 587)));
        $data['smtpEncryption'] = ($data['smtpEncryption'] ?? 'tls') === 'ssl' ? 'ssl' : 'tls';
        $data['smtpValidateCertificate'] = empty($data['smtpValidateCertificate']) ? 0 : 1;
        if(!$data['smtpValidateCertificate'] && !$this->isLoopbackHost($data['smtpHost'])) $data['smtpValidateCertificate'] = 1;
        $data['smtpFromAddress'] = trim((string) ($data['smtpFromAddress'] ?? ''));
        $data['smtpFromName'] = trim((string) ($data['smtpFromName'] ?? ''));
        if($data['smtpFromAddress'] !== '' && (!filter_var($data['smtpFromAddress'], FILTER_VALIDATE_EMAIL) || strlen($data['smtpFromAddress']) > 320)) throw new WireException('Invalid SMTP From address.');
        if(strlen($data['smtpFromName']) > 190 || preg_match('/[\r\n\0]/', $data['smtpFromName'])) throw new WireException('Invalid SMTP From name.');
        $data['saveSentCopies'] = empty($data['saveSentCopies']) ? 0 : 1;
        $data['sentFolder'] = trim((string) ($data['sentFolder'] ?? 'Sent')) ?: 'Sent';
        if(strlen($data['sentFolder']) > 1024 || preg_match('/[{}\r\n\0]/', $data['sentFolder'])) throw new WireException('Invalid Sent folder name.');
        foreach(['enableAgentApi', 'enableCli', 'enableRestApi', 'enableLinkConfirmations', 'enableAdvancedConfirmations', 'enableSquadIntegration', 'enableMailMutations', 'enableMailSending', 'enableBackgroundSync', 'enableWebhookNotifications'] as $toggle) {
            $data[$toggle] = empty($data[$toggle]) ? 0 : 1;
        }
        $data['squadProviderModel'] = trim((string) ($data['squadProviderModel'] ?? $this->squadProviderModel));
        if($data['squadProviderModel'] !== '' && !preg_match('/^[A-Za-z0-9._:\/-]{1,128}\|[A-Za-z0-9._:\/-]{1,128}$/', $data['squadProviderModel'])) throw new WireException('Invalid Squad provider and model selection.');
        foreach(['squadMaxTokens' => [64, 4000], 'squadTimeout' => [1, 60], 'squadMaxSteps' => [1, 6]] as $name => $bounds) {
            $value = $data[$name] ?? $this->{$name};
            if(!is_numeric($value)) throw new WireException('Invalid Squad numeric setting: ' . $name . '.');
            $data[$name] = max($bounds[0], min($bounds[1], (int) $value));
        }
        $temperature = $data['squadTemperature'] ?? $this->squadTemperature;
        if(!is_numeric($temperature)) throw new WireException('Invalid Squad numeric setting: squadTemperature.');
        $data['squadTemperature'] = (string) max(0.0, min(1.0, (float) $temperature));
        $data['webhookUrl'] = trim((string) ($data['webhookUrl'] ?? ''));
        if($data['webhookUrl'] !== '') {
            if(strlen($data['webhookUrl']) > 2048 || preg_match('/[\x00-\x20\x7f]/', $data['webhookUrl'])) throw new WireException('Webhook URL contains invalid characters.');
            $webhook = parse_url($data['webhookUrl']);
            $webhookHost = is_array($webhook) ? strtolower((string) ($webhook['host'] ?? '')) : '';
            if(!is_array($webhook) || strtolower((string) ($webhook['scheme'] ?? '')) !== 'https' || $webhookHost === '' || filter_var($webhookHost, FILTER_VALIDATE_IP) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $webhookHost) || isset($webhook['user']) || isset($webhook['pass']) || isset($webhook['fragment']) || (isset($webhook['port']) && (int) $webhook['port'] !== 443)) throw new WireException('Webhook URL must be an absolute public-hostname HTTPS URL on port 443.');
        }
        if($data['enableWebhookNotifications'] && (!$data['enableBackgroundSync'] || $data['webhookUrl'] === '')) throw new WireException('Webhook notifications require background synchronization and an HTTPS endpoint.');
        if(!$data['enableLinkConfirmations']) $data['enableAdvancedConfirmations'] = 0;
        $data['allowedConfirmationHosts'] = implode("\n", array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($data['allowedConfirmationHosts'] ?? ''))))));
        $data['confirmationTimeout'] = max(1, min(30, (int) ($data['confirmationTimeout'] ?? $this->confirmationTimeout)));
        $data['maxConfirmationResponseBytes'] = max(4096, min(1048576, (int) ($data['maxConfirmationResponseBytes'] ?? $this->maxConfirmationResponseBytes)));
        $data['approvalIntegration'] = in_array(($data['approvalIntegration'] ?? 'none'), ['none', 'verk', 'kontor', 'both'], true) ? $data['approvalIntegration'] : 'none';
        $data['kontorOrganizationUid'] = trim((string) ($data['kontorOrganizationUid'] ?? ''));
        if($data['kontorOrganizationUid'] !== '' && !preg_match('/^[A-Za-z0-9_-]{10,64}$/', $data['kontorOrganizationUid'])) throw new WireException('Invalid Kontor organization UID.');
        if($data['approvalIntegration'] !== 'none' && (!$data['enableAgentApi'] || !$data['enableLinkConfirmations'])) throw new WireException('External approvals require both the PHP agent API and confirmation workflow to be enabled.');
        if(in_array($data['approvalIntegration'], ['kontor', 'both'], true) && $data['kontorOrganizationUid'] === '') throw new WireException('Kontor approvals require an explicit organization UID.');
        if($data['enableSquadIntegration']) {
            if(!$this->wire()->modules->isInstalled('Squad')) throw new WireException('Squad must be installed before enabling the integration.');
            $squad = $this->wire()->modules->get('Squad');
            if(!is_object($squad) || !method_exists($squad, 'ask') || !method_exists($squad, 'run')) throw new WireException('The installed Squad module must expose ask() and run().');
            if(!$data['enableAgentApi'] && !$data['enableCli']) throw new WireException('Squad integration requires the PHP agent API or local CLI.');
            if($data['squadProviderModel'] !== '' && !isset($this->squadModelOptions()[$data['squadProviderModel']])) throw new WireException('The selected Squad provider or model is not active.');
        }

        $tableCredentials = $this->credentials(1)->get();
        $existingCredentials = $tableCredentials ?: $this->legacyCredentials();
        $this->pendingCredentialUpdate = null;
        if(!empty($data['clearPassword'])) {
            $this->pendingCredentialUpdate = ['action' => 'delete'];
        } else if($data['authentication'] === 'oauth' && $oauthUsername !== '') {
            if($data['oauthProvider'] === '') throw new WireException('Select an OAuth provider before saving the mailbox username.');
            $this->pendingCredentialUpdate = ['action' => 'oauth_identity', 'username' => $oauthUsername, 'provider' => $data['oauthProvider']];
        } else {
            $username = trim((string) ($data['username'] ?? ''));
            $submittedPassword = (string) ($data['password'] ?? '');
            if(($username === '') xor ($submittedPassword === '')) {
                throw new WireException('Enter both IMAP username and password to replace saved credentials.');
            }
            if($username !== '' && $submittedPassword !== '') {
                $this->pendingCredentialUpdate = ['action' => 'save', 'username' => $username, 'password' => $submittedPassword];
            }
        }
        unset($data['username'], $data['password'], $data['oauthUsername'], $data['clearPassword']);
        $this->pendingPrimaryAccountSettings = $this->normalizeAccountSettings($data);
        $event->arguments(1, $data);
    }

    /**
     * Apply credential changes only after module configuration saved
     * successfully, so a failed config write cannot delete working credentials.
     */
    public function persistCredentialsAfterConfigSave(HookEvent $event): void {
        $module = $event->arguments(0);
        if($module !== $this && $module !== $this->className() && $module !== 'Mailbox') return;
        if($this->savingSanitizedConfig) return;
        $update = $this->pendingCredentialUpdate;
        $this->pendingCredentialUpdate = null;
        $settings = $this->pendingPrimaryAccountSettings;
        $this->pendingPrimaryAccountSettings = null;
        if($update) {
            if($update['action'] === 'delete') {
                $this->credentials(1)->delete();
            } else if($update['action'] === 'oauth_identity') {
                $this->prepareOAuthIdentity(1, (string) $update['username'], (string) $update['provider']);
            } else {
                $this->credentials(1)->save((string) $update['username'], (string) $update['password']);
            }
        }
        $this->syncPrimaryAccountSettings($settings);
    }

    public function ___getModuleConfigInputfields(InputfieldWrapper $inputfields) {
        $modules = $this->wire()->modules;
        $presets = $this->getPresets();
        $moduleUrl = $this->wire()->config->urls('Mailbox');
        $assetVersion = (string) max((int) @filemtime(dirname(__DIR__) . '/css/mailbox.config.css'), (int) @filemtime(dirname(__DIR__) . '/js/mailbox.config.js'), (int) self::getModuleInfo()['version']);
        $this->wire()->config->styles->add($moduleUrl . 'css/mailbox.config.css?v=' . $assetVersion);
        $this->wire()->config->scripts->add($moduleUrl . 'js/mailbox.config.js?v=' . $assetVersion);
        $credentialStatus = $this->credentialStatus(1);
        $credentialsReady = !empty($credentialStatus['configured']);
        $authenticationLabel = (string) $this->authentication === 'oauth' ? 'OAuth2 / XOAUTH2' : $this->_('Password / app password');
        $mailboxUrl = $this->wire()->config->urls->admin . 'setup/mailbox/';
        $accountsUrl = $mailboxUrl . 'accounts/';
        $discoverUrl = $mailboxUrl . 'discover/';
        $dependenciesUrl = $mailboxUrl . 'dependencies/';
        $entities = function(string $value): string { return $this->wire()->sanitizer->entities($value); };

        $notice = $modules->get('InputfieldMarkup');
        $notice->attr('name', 'mailboxOverview');
        $notice->skipLabel = Inputfield::skipLabelBlank;
        $notice->collapsed = Inputfield::collapsedNever;
        $notice->value = '<section class="MailboxConfig-hero"><div class="MailboxConfig-hero-copy"><span class="MailboxConfig-eyebrow">' . $this->_('Primary mailbox · Account 1') . '</span><h2>' . $this->_('Mailbox configuration') . '</h2><p>' . $this->_('Connect the primary inbox, choose its authentication method, and enable only the integration capabilities this site needs. Additional mailboxes are managed separately under Accounts.') . '</p><div class="MailboxConfig-actions"><a class="uk-button uk-button-primary" href="' . $entities($mailboxUrl) . '"><span uk-icon="mail"></span>' . $this->_('Open mailbox') . '</a><a class="uk-button uk-button-default" href="' . $entities($accountsUrl) . '"><span uk-icon="list"></span>' . $this->_('Manage accounts') . '</a><a class="uk-button uk-button-default" href="' . $entities($dependenciesUrl) . '"><span uk-icon="download"></span>' . $this->_('Runtime packages') . '</a><a class="uk-button uk-button-text" href="' . $entities($discoverUrl) . '">' . $this->_('Discover settings') . '</a></div></div><div class="MailboxConfig-summary"><div><small>' . $this->_('Incoming server') . '</small><strong>' . $entities((string) $this->host) . ':' . (int) $this->port . '</strong><span>' . strtoupper($entities((string) $this->encryption)) . '</span></div><div><small>' . $this->_('Authentication') . '</small><strong>' . $entities($authenticationLabel) . '</strong><span class="' . ($credentialsReady ? 'is-ready' : 'is-warning') . '">' . ($credentialsReady ? $this->_('Credentials protected') : $this->_('Credentials required')) . '</span></div><div><small>' . $this->_('Message actions') . '</small><strong>' . ((bool) $this->enableMailSending ? $this->_('Sending enabled') : $this->_('Sending disabled')) . '</strong><span>' . ((bool) $this->enableMailMutations ? $this->_('Management enabled') : $this->_('Management disabled')) . '</span></div></div></section><nav class="MailboxConfig-nav" aria-label="' . $entities($this->_('Configuration sections')) . '"><a href="#Inputfield_mailboxPreset">' . $this->_('Provider') . '</a><a href="#Inputfield_mailboxImap">IMAP</a><a href="#Inputfield_mailboxAuth">' . $this->_('Sign-in') . '</a><a href="#Inputfield_mailboxDisplay">' . $this->_('Display') . '</a><a href="#Inputfield_mailboxSync">' . $this->_('Synchronization') . '</a><a href="#Inputfield_mailboxActions">' . $this->_('Message actions') . '</a><a href="#Inputfield_mailboxAiModel">' . $this->_('AI model') . '</a><a href="#Inputfield_mailboxIntegrations">API &amp; CLI</a></nav><div class="MailboxConfig-security"><span uk-icon="lock"></span><div><strong>' . $this->_('Secrets stay outside normal module settings') . '</strong><p>' . $this->_('Mailbox usernames, passwords, and OAuth tokens are encrypted in the dedicated credentials table and are never rendered back into this form. OAuth client secrets belong only in config.php, Vault, or KMS.') . '</p></div></div>';
        $inputfields->add($notice);

        $presetFieldset = $modules->get('InputfieldFieldset');
        $presetFieldset->attr('name', 'mailboxPreset');
        $presetFieldset->label = $this->_('Service preset');
        $presetFieldset->description = $this->_('Start with an editable provider template, then review every endpoint before saving. Presets never change credentials.');
        $presetFieldset->icon = 'magic';
        $presetFieldset->collapsed = (string) $this->host === '' ? Inputfield::collapsedNo : Inputfield::collapsedYes;

        $preset = $modules->get('InputfieldSelect');
        $preset->attr('name', 'preset');
        $preset->label = $this->_('Mail service or server');
        foreach($presets as $key => $definition) {
            $label = $definition['group'] . ' — ' . $definition['label'];
            if(empty($definition['supported'])) $label .= ' — ' . $this->_('unsupported');
            $preset->addOption($key, $label);
        }
        $preset->attr('value', isset($presets[(string) $this->preset]) ? (string) $this->preset : 'custom');
        $presetFieldset->add($preset);

        $presetHelp = $modules->get('InputfieldMarkup');
        $presetHelp->label = $this->_('Apply preset');
        $currentPreset = $presets[(string) $this->preset] ?? $presets['custom'];
        $presetJson = json_encode($presets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $presetHelp->value = '<p id="MailboxPresetNote">' . $this->wire()->sanitizer->entities($currentPreset['note']) . '</p>' .
            '<button type="button" id="MailboxApplyPreset" class="uk-button uk-button-default">' . $this->_('Apply preset values') . '</button>' .
            '<p><small>' . $this->_('Applying a preset changes server fields in this form but never changes the username or password. Review the values, then save the module configuration.') . '</small></p>' .
            '<script>(function(){var presets=' . $presetJson . ';var select=document.getElementById("Inputfield_preset");var button=document.getElementById("MailboxApplyPreset");var note=document.getElementById("MailboxPresetNote");if(!select||!button)return;function showNote(){var p=presets[select.value]||presets.custom;if(note)note.textContent=p.note||"";}select.addEventListener("change",showNote);button.addEventListener("click",function(){var p=presets[select.value]||presets.custom;["host","port","encryption","imapTransport","validateCertificate","secureAuthentication","disableAuthenticator","defaultFolder","folderPattern","authentication","oauthProvider","smtpHost","smtpPort","smtpEncryption","smtpValidateCertificate"].forEach(function(key){var el=document.getElementById("Inputfield_"+key);if(!el||typeof p[key]==="undefined")return;if(el.type==="checkbox")el.checked=!!Number(p[key]);else el.value=p[key];if(window.jQuery)window.jQuery(el).trigger("change");});showNote();});showNote();})();</script>';
        $presetFieldset->add($presetHelp);
        $inputfields->add($presetFieldset);

        $connection = $modules->get('InputfieldFieldset');
        $connection->attr('name', 'mailboxImap');
        $connection->label = $this->_('IMAP connection');
        $connection->description = $this->_('Incoming-mail endpoint used to list folders, search, and read messages.');
        $connection->icon = 'download';

        $host = $modules->get('InputfieldText');
        $host->attr('name', 'host');
        $host->label = $this->_('IMAP host');
        $host->description = $this->_('Hostname only, for example imap.example.com.');
        $host->attr('value', (string) $this->host);
        $host->required = true;
        $host->columnWidth = 70;
        $connection->add($host);

        $port = $modules->get('InputfieldInteger');
        $port->attr('name', 'port');
        $port->label = $this->_('Port');
        $port->attr('value', (int) $this->port);
        $port->columnWidth = 30;
        $connection->add($port);

        $encryption = $modules->get('InputfieldSelect');
        $encryption->attr('name', 'encryption');
        $encryption->label = $this->_('Encryption');
        $encryption->addOptions(['ssl' => $this->_('Implicit TLS / SSL'), 'tls' => $this->_('STARTTLS')]);
        $encryption->attr('value', (string) $this->encryption);
        $encryption->columnWidth = 25;
        $connection->add($encryption);

        $imapTransport = $modules->get('InputfieldSelect');
        $imapTransport->attr('name', 'imapTransport');
        $imapTransport->label = $this->_('IMAP engine');
        $imapTransport->description = $this->_('Auto uses Webklex for hosted services such as Gmail and native PHP IMAP for self-hosted servers. Runtime packages live under site/assets/Mailbox and survive module upgrades.');
        $imapTransport->addOptions(['auto' => $this->_('Auto (recommended)'), 'webklex' => 'Webklex', 'native' => $this->_('Native PHP IMAP')]);
        $imapTransport->attr('value', (string) $this->imapTransport);
        $imapTransport->columnWidth = 25;
        $connection->add($imapTransport);

        $certificate = $modules->get('InputfieldCheckbox');
        $certificate->attr('name', 'validateCertificate');
        $certificate->label = $this->_('Validate TLS certificate');
        $certificate->description = $this->_('Required for every non-loopback host. Disable only for a local bridge on localhost/127.0.0.1/::1.');
        $certificate->attr('checked', (bool) $this->validateCertificate);
        $certificate->columnWidth = 25;
        $connection->add($certificate);

        $secureAuth = $modules->get('InputfieldCheckbox');
        $secureAuth->attr('name', 'secureAuthentication');
        $secureAuth->label = $this->_('Require secure authentication');
        $secureAuth->description = $this->_('Uses the IMAP /secure and OP_SECURE options. Enable only when required by server policy.');
        $secureAuth->attr('checked', (bool) $this->secureAuthentication);
        $secureAuth->columnWidth = 25;
        $connection->add($secureAuth);

        $inputfields->add($connection);

        $smtp = $modules->get('InputfieldFieldset');
        $smtp->attr('name', 'mailboxSmtp');
        $smtp->label = $this->_('SMTP sending');
        $smtp->description = $this->_('SMTP is TLS-only and reuses this account’s encrypted password or OAuth tokens. Microsoft OAuth accounts must reconnect after enabling sending to grant SMTP.Send.');
        $smtp->icon = 'upload';
        $smtp->collapsed = Inputfield::collapsedYes;

        $smtpHost = $modules->get('InputfieldText');
        $smtpHost->attr('name', 'smtpHost');
        $smtpHost->label = $this->_('SMTP host');
        $smtpHost->attr('value', (string) $this->smtpHost);
        $smtpHost->columnWidth = 50;
        $smtp->add($smtpHost);

        $smtpPort = $modules->get('InputfieldInteger');
        $smtpPort->attr('name', 'smtpPort');
        $smtpPort->label = $this->_('SMTP port');
        $smtpPort->attr('value', (int) $this->smtpPort);
        $smtpPort->columnWidth = 25;
        $smtp->add($smtpPort);

        $smtpEncryption = $modules->get('InputfieldSelect');
        $smtpEncryption->attr('name', 'smtpEncryption');
        $smtpEncryption->label = $this->_('SMTP encryption');
        $smtpEncryption->addOptions(['tls' => $this->_('STARTTLS'), 'ssl' => $this->_('Implicit TLS / SSL')]);
        $smtpEncryption->attr('value', (string) $this->smtpEncryption);
        $smtpEncryption->columnWidth = 25;
        $smtp->add($smtpEncryption);

        $smtpCertificate = $modules->get('InputfieldCheckbox');
        $smtpCertificate->attr('name', 'smtpValidateCertificate');
        $smtpCertificate->label = $this->_('Validate SMTP TLS certificate');
        $smtpCertificate->description = $this->_('Required for every non-loopback host. Disable only for a local bridge.');
        $smtpCertificate->attr('checked', (bool) $this->smtpValidateCertificate);
        $smtp->add($smtpCertificate);

        $smtpFrom = $modules->get('InputfieldEmail');
        $smtpFrom->attr('name', 'smtpFromAddress');
        $smtpFrom->label = $this->_('From address override');
        $smtpFrom->description = $this->_('Leave empty to use the encrypted mailbox username. The SMTP server may reject unauthorized aliases.');
        $smtpFrom->attr('value', (string) $this->smtpFromAddress);
        $smtpFrom->columnWidth = 50;
        $smtp->add($smtpFrom);

        $smtpName = $modules->get('InputfieldText');
        $smtpName->attr('name', 'smtpFromName');
        $smtpName->label = $this->_('From display name');
        $smtpName->attr('value', (string) $this->smtpFromName);
        $smtpName->columnWidth = 50;
        $smtp->add($smtpName);

        $saveSent = $modules->get('InputfieldCheckbox');
        $saveSent->attr('name', 'saveSentCopies');
        $saveSent->label = $this->_('Append a copy to an IMAP Sent folder');
        $saveSent->description = $this->_('Disabled by default because some providers already save SMTP submissions. A failed append never retries or hides a successful SMTP delivery.');
        $saveSent->attr('checked', (bool) $this->saveSentCopies);
        $smtp->add($saveSent);

        $sentFolder = $modules->get('InputfieldText');
        $sentFolder->attr('name', 'sentFolder');
        $sentFolder->label = $this->_('Sent folder');
        $sentFolder->attr('value', (string) $this->sentFolder);
        $sentFolder->showIf = 'saveSentCopies=1';
        $smtp->add($sentFolder);
        $inputfields->add($smtp);

        $authentication = $modules->get('InputfieldFieldset');
        $authentication->attr('name', 'mailboxAuth');
        $authentication->label = $this->_('Authentication');
        $authentication->description = $this->_('Choose password/app-password or OAuth2. Provider secrets are never stored in this form.');
        $authentication->icon = 'key';

        $authMethod = $modules->get('InputfieldSelect');
        $authMethod->attr('name', 'authentication');
        $authMethod->label = $this->_('Authentication method');
        $authMethod->addOptions(['password' => $this->_('Password / app password'), 'oauth' => 'OAuth 2.0 / XOAUTH2']);
        $authMethod->attr('value', (string) $this->authentication);
        $authentication->add($authMethod);

        $oauthProvider = $modules->get('InputfieldSelect');
        $oauthProvider->attr('name', 'oauthProvider');
        $oauthProvider->label = $this->_('OAuth provider');
        $oauthProvider->addOptions(['' => $this->_('Select provider'), 'google' => 'Google / Gmail', 'microsoft' => 'Microsoft 365 / Outlook.com']);
        $oauthProvider->attr('value', (string) $this->oauthProvider);
        $oauthProvider->showIf = 'authentication=oauth';
        $authentication->add($oauthProvider);

        $oauthClientId = $modules->get('InputfieldText');
        $oauthClientId->attr('name', 'oauthClientId');
        $oauthClientId->label = $this->_('OAuth client ID');
        $oauthClientId->attr('value', (string) $this->oauthClientId);
        $oauthClientId->showIf = 'authentication=oauth';
        $authentication->add($oauthClientId);

        $oauthUsername = $modules->get('InputfieldText');
        $oauthUsername->attr('name', 'oauthUsername');
        $oauthUsername->label = $this->_('OAuth mailbox username');
        $oauthUsername->description = $this->_('Usually the complete email address. It is encrypted in the credentials table and never repopulated into this form; enter it only for initial connection or replacement.');
        $oauthUsername->attr('autocomplete', 'off');
        $oauthUsername->attr('value', '');
        $oauthUsername->showIf = 'authentication=oauth';
        $authentication->add($oauthUsername);

        $oauthTenant = $modules->get('InputfieldText');
        $oauthTenant->attr('name', 'oauthTenant');
        $oauthTenant->label = $this->_('Microsoft tenant');
        $oauthTenant->description = $this->_('Use common, organizations, consumers, a tenant ID, or a verified tenant domain.');
        $oauthTenant->attr('value', (string) $this->oauthTenant);
        $oauthTenant->showIf = 'authentication=oauth, oauthProvider=microsoft';
        $authentication->add($oauthTenant);

        if((string) $this->authentication === 'oauth' && (string) $this->oauthProvider !== '' && (string) $this->oauthClientId !== '') {
            $oauthAction = $modules->get('InputfieldMarkup');
            $oauthAction->label = $this->_('OAuth connection');
            $oauthUrl = $this->wire()->config->urls->admin . 'setup/mailbox/oauth-start/';
            $oauthAction->value = '<form method="post" action="' . $this->wire()->sanitizer->entities($oauthUrl) . '">' . $this->wire()->session->CSRF->renderInput() . '<input type="hidden" name="account" value="1"><button class="uk-button uk-button-primary" type="submit">' . $this->_('Connect mailbox with OAuth') . '</button></form>';
            if($credentialStatus = $this->credentialStatus(1)) {
                if(($credentialStatus['password_source'] ?? '') === 'oauth_tokens') {
                    $disconnect = $this->wire()->config->urls->admin . 'setup/mailbox/oauth-disconnect/';
                    $oauthAction->value .= '<form method="post" action="' . $this->wire()->sanitizer->entities($disconnect) . '">' . $this->wire()->session->CSRF->renderInput() . '<input type="hidden" name="account" value="1"><button class="uk-button uk-button-danger" type="submit">' . $this->_('Disconnect OAuth') . '</button></form>';
                }
            }
            $authentication->add($oauthAction);
        }

        $inputfields->add($authentication);

        $credentials = $modules->get('InputfieldFieldset');
        $credentials->attr('name', 'mailboxCredentials');
        $credentials->label = $this->_('Credentials');
        $credentials->description = $credentialsReady ? $this->_('Encrypted credentials are already configured. Expand only to replace or remove them.') : $this->_('Credentials are required before the first connection test.');
        $credentials->icon = 'lock';
        $credentials->collapsed = $credentialsReady ? Inputfield::collapsedYes : Inputfield::collapsedNo;
        $username = $modules->get('InputfieldText');
        $username->attr('name', 'username');
        $username->label = $this->_('Username');
        $username->description = $credentialStatus['configured']
            ? $this->_('Credentials are configured. The saved username is intentionally hidden; enter both username and password only to replace them.')
            : $this->_('Usually the complete email address. Some self-hosted servers use only the local account name.');
        $username->attr('autocomplete', 'off');
        $username->attr('value', '');
        $username->showIf = 'authentication=password';
        $credentials->add($username);

        $password = $modules->get('InputfieldPassword');
        $password->attr('name', 'password');
        $password->label = $this->_('Password or app password');
        $password->description = $credentialStatus['configured']
            ? $this->_('An encrypted credential is saved. Leave this field empty to keep it unchanged. You may enter env:VARIABLE_NAME instead of a password.')
            : $this->_('For providers with two-factor authentication, use an app password. You may enter env:VARIABLE_NAME so the password itself never enters the database.');
        $password->attr('autocomplete', 'new-password');
        $password->attr('value', '');
        $password->showIf = 'authentication=password';
        $credentials->add($password);

        if($credentialStatus['configured']) {
            $clear = $modules->get('InputfieldCheckbox');
            $clear->attr('name', 'clearPassword');
            $clear->label = $this->_('Remove saved credentials');
            $credentials->add($clear);
        }

        $inputfields->add($credentials);

        $advanced = $modules->get('InputfieldFieldset');
        $advanced->attr('name', 'mailboxAdvanced');
        $advanced->label = $this->_('Connection behavior and timeouts');
        $advanced->description = $this->_('Defaults suit most servers. Change these values only while diagnosing connection behavior.');
        $advanced->icon = 'sliders';
        $advanced->collapsed = Inputfield::collapsedYes;

        $retries = $modules->get('InputfieldInteger');
        $retries->attr('name', 'connectionRetries');
        $retries->label = $this->_('Connection attempts');
        $retries->description = $this->_('Maximum 3 attempts per request.');
        $retries->attr('value', (int) $this->connectionRetries);
        $retries->columnWidth = 20;
        $advanced->add($retries);

        foreach([
            'openTimeout' => $this->_('Open timeout'),
            'readTimeout' => $this->_('Read timeout'),
            'writeTimeout' => $this->_('Write timeout'),
            'closeTimeout' => $this->_('Close timeout'),
        ] as $name => $label) {
            $timeout = $modules->get('InputfieldInteger');
            $timeout->attr('name', $name);
            $timeout->label = $label . ' (' . $this->_('seconds') . ')';
            $timeout->attr('value', (int) $this->{$name});
            $timeout->columnWidth = 20;
            $advanced->add($timeout);
        }

        $authenticator = $modules->get('InputfieldSelect');
        $authenticator->attr('name', 'disableAuthenticator');
        $authenticator->label = $this->_('Disabled authenticator');
        $authenticator->description = $this->_('Disable GSSAPI only when Kerberos negotiation causes connection errors.');
        $authenticator->addOptions(['' => $this->_('None'), 'GSSAPI' => 'GSSAPI / Kerberos']);
        $authenticator->attr('value', (string) $this->disableAuthenticator);
        $advanced->add($authenticator);

        $inputfields->add($advanced);

        $folders = $modules->get('InputfieldFieldset');
        $folders->attr('name', 'mailboxDisplay');
        $folders->label = $this->_('Folders and display');
        $folders->description = $this->_('Control the initial folder, list size, searches, body limits, and attachment boundaries.');
        $folders->icon = 'folder-open';
        $folders->collapsed = Inputfield::collapsedYes;

        $defaultFolder = $modules->get('InputfieldText');
        $defaultFolder->attr('name', 'defaultFolder');
        $defaultFolder->label = $this->_('Default folder');
        $defaultFolder->description = $this->_('Server-native folder name opened first. Usually INBOX.');
        $defaultFolder->attr('value', (string) $this->defaultFolder);
        $defaultFolder->columnWidth = 50;
        $folders->add($defaultFolder);

        $folderPattern = $modules->get('InputfieldText');
        $folderPattern->attr('name', 'folderPattern');
        $folderPattern->label = $this->_('Folder list pattern');
        $folderPattern->description = $this->_('IMAP wildcard pattern. Use * for all levels or % for one hierarchy level.');
        $folderPattern->attr('value', (string) $this->folderPattern);
        $folderPattern->columnWidth = 50;
        $folders->add($folderPattern);

        $showUnselectable = $modules->get('InputfieldCheckbox');
        $showUnselectable->attr('name', 'showUnselectableFolders');
        $showUnselectable->label = $this->_('Show non-selectable folder containers');
        $showUnselectable->attr('checked', (bool) $this->showUnselectableFolders);
        $folders->add($showUnselectable);

        $remoteWarning = $modules->get('InputfieldMarkup');
        $remoteWarning->attr('name', 'mailboxRemoteImageWarning');
        $remoteWarning->skipLabel = Inputfield::skipLabelBlank;
        $remoteWarning->value = '<div class="MailboxConfig-remote-warning"><span uk-icon="warning"></span><div><strong>' . $this->_('External content can report mailbox activity') . '</strong><p>' . $this->_('Remote images can reveal an open. Following a message link can reveal your IP address, browser details, click time, and any unique token embedded in that URL. Mailbox never sends the ProcessWire admin address as the HTTP Referer.') . '</p></div></div>';
        $folders->add($remoteWarning);

        $remoteImages = $modules->get('InputfieldCheckbox');
        $remoteImages->attr('name', 'allowRemoteMessageImages');
        $remoteImages->label = $this->_('I understand the privacy risk — load remote HTTPS images in HTML messages');
        $remoteImages->description = $this->_('Applies to every mailbox viewed in this admin. Disable it to return to the privacy-first image-blocking mode.');
        $remoteImages->attr('checked', (bool) $this->allowRemoteMessageImages);
        $folders->add($remoteImages);

        $externalLinks = $modules->get('InputfieldCheckbox');
        $externalLinks->attr('name', 'allowExternalMessageLinks');
        $externalLinks->label = $this->_('I understand the privacy risk — allow HTTPS links in HTML messages');
        $externalLinks->description = $this->_('Links open in a new tab without a Referer. Only public-hostname HTTPS URLs on port 443 are allowed; scripts, forms, local addresses, and unsafe schemes remain blocked.');
        $externalLinks->attr('checked', (bool) $this->allowExternalMessageLinks);
        $folders->add($externalLinks);

        $perPage = $modules->get('InputfieldInteger');
        $perPage->attr('name', 'messagesPerPage');
        $perPage->label = $this->_('Messages per page');
        $perPage->description = $this->_('Defaults to 100 messages. Allowed range: 10–100.');
        $perPage->attr('min', 10);
        $perPage->attr('max', 100);
        $perPage->attr('value', (int) $this->messagesPerPage);
        $perPage->columnWidth = 50;
        $folders->add($perPage);

        $maxBody = $modules->get('InputfieldInteger');
        $maxBody->attr('name', 'maxBodyBytes');
        $maxBody->label = $this->_('Maximum displayed body bytes');
        $maxBody->attr('value', (int) $this->maxBodyBytes);
        $maxBody->columnWidth = 50;
        $folders->add($maxBody);

        $maxAttachment = $modules->get('InputfieldInteger');
        $maxAttachment->attr('name', 'maxAttachmentBytes');
        $maxAttachment->label = $this->_('Maximum attachment bytes');
        $maxAttachment->description = $this->_('Maximum decoded attachment size for trusted downloads. Range: 64 KiB to 50 MiB.');
        $maxAttachment->attr('value', (int) $this->maxAttachmentBytes);
        $maxAttachment->columnWidth = 50;
        $folders->add($maxAttachment);

        $maxAgentAttachment = $modules->get('InputfieldInteger');
        $maxAgentAttachment->attr('name', 'maxAgentAttachmentBytes');
        $maxAgentAttachment->label = $this->_('Maximum agent-readable attachment bytes');
        $maxAgentAttachment->description = $this->_('Text/JSON/XML/CSV only. Range: 4 KiB to 5 MiB and never above the download limit.');
        $maxAgentAttachment->attr('value', (int) $this->maxAgentAttachmentBytes);
        $maxAgentAttachment->columnWidth = 50;
        $folders->add($maxAgentAttachment);

        $maxSearchResults = $modules->get('InputfieldInteger');
        $maxSearchResults->attr('name', 'maxSearchResults');
        $maxSearchResults->label = $this->_('Maximum collected search results');
        $maxSearchResults->attr('value', (int) $this->maxSearchResults);
        $maxSearchResults->columnWidth = 50;
        $folders->add($maxSearchResults);

        $maxSearchFolders = $modules->get('InputfieldInteger');
        $maxSearchFolders->attr('name', 'maxSearchFolders');
        $maxSearchFolders->label = $this->_('Maximum folders per whole-account search');
        $maxSearchFolders->attr('value', (int) $this->maxSearchFolders);
        $maxSearchFolders->columnWidth = 50;
        $folders->add($maxSearchFolders);

        $inputfields->add($folders);

        $sync = $modules->get('InputfieldFieldset');
        $sync->attr('name', 'mailboxSync');
        $sync->label = $this->_('Background synchronization and local index');
        $sync->description = $this->_('Disabled by default. Indexed message metadata, job payloads, and notifications are encrypted with the Mailbox keyring. LazyCron is request-driven; use the CLI worker for deterministic schedules.');
        $sync->icon = 'refresh';
        $sync->collapsed = (bool) $this->enableBackgroundSync ? Inputfield::collapsedNo : Inputfield::collapsedYes;

        $enableSync = $modules->get('InputfieldCheckbox');
        $enableSync->attr('name', 'enableBackgroundSync');
        $enableSync->label = $this->_('Enable encrypted local index and background synchronization');
        $enableSync->attr('checked', (bool) $this->enableBackgroundSync);
        $sync->add($enableSync);

        $syncInterval = $modules->get('InputfieldSelect');
        $syncInterval->attr('name', 'syncInterval');
        $syncInterval->label = $this->_('LazyCron interval');
        foreach(['everyMinute' => $this->_('Every minute'), 'every5Minutes' => $this->_('Every 5 minutes'), 'every15Minutes' => $this->_('Every 15 minutes'), 'every30Minutes' => $this->_('Every 30 minutes'), 'everyHour' => $this->_('Every hour')] as $value => $label) $syncInterval->addOption($value, $label);
        $syncInterval->attr('value', (string) $this->syncInterval);
        $syncInterval->showIf = 'enableBackgroundSync=1';
        $syncInterval->columnWidth = 50;
        $sync->add($syncInterval);

        foreach([
            'maxSyncFolders' => [$this->_('Maximum folders per sync'), 1, 500, (int) $this->maxSyncFolders],
            'maxSyncMessagesPerFolder' => [$this->_('Recent messages indexed per folder'), 10, 1000, (int) $this->maxSyncMessagesPerFolder],
            'syncJobsPerRun' => [$this->_('Jobs processed per run'), 1, 20, (int) $this->syncJobsPerRun],
            'syncHistoryDays' => [$this->_('Completed job/read notification retention days'), 1, 3650, (int) $this->syncHistoryDays],
        ] as $name => $definition) {
            $field = $modules->get('InputfieldInteger');
            $field->attr('name', $name);
            $field->label = $definition[0];
            $field->attr('min', $definition[1]);
            $field->attr('max', $definition[2]);
            $field->attr('value', $definition[3]);
            $field->showIf = 'enableBackgroundSync=1';
            $field->columnWidth = 33;
            $sync->add($field);
        }

        $enableWebhook = $modules->get('InputfieldCheckbox');
        $enableWebhook->attr('name', 'enableWebhookNotifications');
        $enableWebhook->label = $this->_('Queue signed webhook notifications');
        $enableWebhook->description = $this->_('Sends only redacted identifiers after initial sync. Configure a 32+ byte mailboxWebhookSecret or provider in config.php. Delivery is HTTPS-only, signed, retried through the encrypted queue, and disabled by default.');
        $enableWebhook->attr('checked', (bool) $this->enableWebhookNotifications);
        $enableWebhook->showIf = 'enableBackgroundSync=1';
        $sync->add($enableWebhook);

        $webhookUrl = $modules->get('InputfieldURL');
        $webhookUrl->attr('name', 'webhookUrl');
        $webhookUrl->label = $this->_('Webhook HTTPS endpoint');
        $webhookUrl->attr('value', (string) $this->webhookUrl);
        $webhookUrl->showIf = 'enableWebhookNotifications=1';
        $sync->add($webhookUrl);
        $inputfields->add($sync);

        $actions = $modules->get('InputfieldFieldset');
        $actions->attr('name', 'mailboxActions');
        $actions->label = $this->_('Message actions');
        $actions->description = $this->_('Controls the buttons shown when a message is open. These admin actions are independent of the optional Agent API.');
        $actions->icon = 'reply';
        $actions->collapsed = Inputfield::collapsedNo;

        $actionsGuide = $modules->get('InputfieldMarkup');
        $actionsGuide->attr('name', 'mailboxActionsGuide');
        $actionsGuide->skipLabel = Inputfield::skipLabelBlank;
        $actionsGuide->value = '<div class="MailboxConfig-action-guide"><div><span uk-icon="file-edit"></span><div><strong>' . $this->_('Manage messages') . '</strong><p>' . $this->_('Shows read/unread, move, and safe non-expunging delete controls to users with mailbox-write.') . '</p></div></div><div><span uk-icon="reply"></span><div><strong>' . $this->_('Reply and forward') . '</strong><p>' . $this->_('Shows SMTP reply and forward controls to users with mailbox-send. Configure and test SMTP for each account first.') . '</p></div></div></div>';
        $actions->add($actionsGuide);

        $enableMutations = $modules->get('InputfieldCheckbox');
        $enableMutations->attr('name', 'enableMailMutations');
        $enableMutations->label = $this->_('Enable message management in the admin');
        $enableMutations->description = $this->_('Allows mailbox-write users to change flags, move messages, and mark messages for deletion. Delete never expunges from the admin reader. API/CLI mutation access remains independently permission-gated.');
        $enableMutations->attr('checked', (bool) $this->enableMailMutations);
        $actions->add($enableMutations);

        $enableSending = $modules->get('InputfieldCheckbox');
        $enableSending->attr('name', 'enableMailSending');
        $enableSending->label = $this->_('Enable SMTP reply and forward');
        $enableSending->description = $this->_('Allows mailbox-send users to send plain-text replies and forwards from an open message. Requires saved SMTP settings and a successful SMTP connection test.');
        $enableSending->attr('checked', (bool) $this->enableMailSending);
        $actions->add($enableSending);
        $inputfields->add($actions);

        $squadStatus = $this->squadStatus();
        $aiModel = $modules->get('InputfieldFieldset');
        $aiModel->attr('name', 'mailboxAiModel');
        $aiModel->label = $this->_('AI model');
        $aiModel->description = $this->_('Default Squad provider, model, and bounded generation limits for Mailbox analysis and agent calls. Per-call API options may override these defaults within the same limits.');
        $aiModel->icon = 'brain';
        $aiModel->collapsed = (bool) $this->enableSquadIntegration ? Inputfield::collapsedNo : Inputfield::collapsedYes;

        $squadChannelReady = (bool) $this->enableAgentApi || (bool) $this->enableCli;
        $squadCanEnable = !empty($squadStatus['compatible']) && $squadChannelReady;
        $readiness = $modules->get('InputfieldMarkup');
        $readiness->attr('name', 'mailboxAiReadiness');
        $readiness->skipLabel = Inputfield::skipLabelBlank;
        if(empty($squadStatus['compatible'])) {
            $readinessTitle = $this->_('Squad must be installed first');
            $readinessText = $this->_('Install and activate Squad, then reload this page. AI integration cannot be selected until Mailbox detects its ask() and run() API.');
            $readinessState = 'is-blocked';
        } else if(!$squadChannelReady) {
            $readinessTitle = $this->_('Enable an access channel first');
            $readinessText = $this->_('Enable and save either the permission-gated PHP agent API or the local CLI in API & CLI, then return here.');
            $readinessState = 'is-blocked';
        } else {
            $readinessTitle = $this->_('Squad is ready');
            $readinessText = $this->_('Squad and an approved Mailbox access channel are available.');
            $readinessState = 'is-ready';
        }
        $readinessLink = !empty($squadStatus['compatible']) && !$squadChannelReady ? '<a href="#Inputfield_mailboxIntegrations">' . $this->_('Open API & CLI settings') . '</a>' : '';
        $readiness->value = '<div class="MailboxConfig-ai-readiness ' . $readinessState . '"><span uk-icon="' . ($squadCanEnable ? 'check' : 'ban') . '"></span><div><strong>' . $readinessTitle . '</strong><p>' . $readinessText . '</p>' . $readinessLink . '</div></div>';
        $aiModel->add($readiness);

        $enableSquad = $modules->get('InputfieldCheckbox');
        $enableSquad->attr('name', 'enableSquadIntegration');
        $enableSquad->label = $this->_('Enable Squad AI for mailbox analysis and bounded agent tools');
        $enableSquad->description = $this->_('Requires Squad with ask() and run(), plus the PHP agent API or local CLI. Agent-safe message text is sent to the selected external AI provider; HTML, raw MIME, executable URLs, credentials, and attachments are excluded.');
        $enableSquad->notes = $squadCanEnable
            ? $this->_('All prerequisites are ready. Enabling this setting sends agent-safe message text to the provider configured in Squad.')
            : $this->_('Unavailable until the prerequisite shown above has been completed and saved.');
        $enableSquad->attr('checked', (bool) $this->enableSquadIntegration);
        if(!$squadCanEnable) $enableSquad->attr('disabled', 'disabled');
        $aiModel->add($enableSquad);

        $providerModel = $modules->get('InputfieldSelect');
        $providerModel->attr('name', 'squadProviderModel');
        $providerModel->label = $this->_('Provider and model');
        $providerModel->description = $this->_('Credentials remain in Squad. Follow its default selection or choose one currently active provider and model.');
        $providerModel->addOption('', $this->_('Use Squad default'));
        foreach($this->squadModelOptions() as $value => $label) $providerModel->addOption($value, $label);
        $providerModel->attr('value', (string) $this->squadProviderModel);
        $providerModel->showIf = 'enableSquadIntegration=1';
        $aiModel->add($providerModel);

        foreach([
            'squadMaxTokens' => [$this->_('Maximum output tokens'), 64, 4000, (int) $this->squadMaxTokens],
            'squadTimeout' => [$this->_('Timeout (seconds)'), 1, 60, (int) $this->squadTimeout],
            'squadMaxSteps' => [$this->_('Maximum agent steps'), 1, 6, (int) $this->squadMaxSteps],
        ] as $name => $definition) {
            $field = $modules->get('InputfieldInteger');
            $field->attr('name', $name);
            $field->label = $definition[0];
            $field->attr('min', $definition[1]);
            $field->attr('max', $definition[2]);
            $field->attr('value', $definition[3]);
            $field->columnWidth = 25;
            $field->showIf = 'enableSquadIntegration=1';
            $aiModel->add($field);
        }

        $temperature = $modules->get('InputfieldText');
        $temperature->attr('name', 'squadTemperature');
        $temperature->attr('type', 'number');
        $temperature->attr('min', '0');
        $temperature->attr('max', '1');
        $temperature->attr('step', '0.1');
        $temperature->attr('value', (string) $this->squadTemperature);
        $temperature->label = $this->_('Temperature');
        $temperature->columnWidth = 25;
        $temperature->showIf = 'enableSquadIntegration=1';
        $aiModel->add($temperature);

        $aiSafety = $modules->get('InputfieldMarkup');
        $aiSafety->attr('name', 'mailboxAiSafety');
        $aiSafety->skipLabel = Inputfield::skipLabelBlank;
        $aiSafety->value = '<div class="MailboxConfig-ai-safety"><span uk-icon="shield"></span><div><strong>' . $this->_('Mailbox keeps the safety controls fixed') . '</strong><p>' . $this->_('Response caching, prompt caching, web search, caller system prompts, provider keys, and conversation history remain unavailable. The agent may create a pending confirmation proposal but can never approve or execute it.') . '</p></div></div>';
        $aiModel->add($aiSafety);
        $inputfields->add($aiModel);

        $agents = $modules->get('InputfieldFieldset');
        $agents->attr('name', 'mailboxIntegrations');
        $agents->label = $this->_('Frontend, CLI, and agent access');
        $agents->description = $this->_('All capabilities are disabled by default. Logged-in status alone never grants mailbox access.');
        $agents->icon = 'plug';
        $agentsEnabled = (bool) $this->enableAgentApi || (bool) $this->enableCli || (bool) $this->enableLinkConfirmations;
        $agents->collapsed = $agentsEnabled ? Inputfield::collapsedNo : Inputfield::collapsedYes;

        $enableApi = $modules->get('InputfieldCheckbox');
        $enableApi->attr('name', 'enableAgentApi');
        $enableApi->label = $this->_('Enable permission-gated PHP agent API');
        $enableApi->description = $this->_('Users must also have mailbox-api. This does not create a public HTTP route.');
        $enableApi->attr('checked', (bool) $this->enableAgentApi);
        $agents->add($enableApi);

        $enableRest = $modules->get('InputfieldCheckbox');
        $enableRest->attr('name', 'enableRestApi');
        $enableRest->label = $this->_('Enable same-origin JSON REST API');
        $enableRest->description = $this->_('Adds /mailbox-api/v1/ routes. Requires the PHP agent API, a logged-in ProcessWire session, mailbox-api permission, and CSRF for every mutation. CORS is not enabled.');
        $enableRest->attr('checked', (bool) $this->enableRestApi);
        $enableRest->showIf = 'enableAgentApi=1';
        $agents->add($enableRest);

        $enableCli = $modules->get('InputfieldCheckbox');
        $enableCli->attr('name', 'enableCli');
        $enableCli->label = $this->_('Enable local Mailbox CLI');
        $enableCli->description = $this->_('The CLI must run on the ProcessWire host and never accepts mailbox credentials as arguments.');
        $enableCli->attr('checked', (bool) $this->enableCli);
        $agents->add($enableCli);

        $enableConfirmations = $modules->get('InputfieldCheckbox');
        $enableConfirmations->attr('name', 'enableLinkConfirmations');
        $enableConfirmations->label = $this->_('Enable controlled confirmation-link workflow');
        $enableConfirmations->description = $this->_('Requires an allowlist and a separate proposal approval before any HTTPS request.');
        $enableConfirmations->attr('checked', (bool) $this->enableLinkConfirmations);
        $agents->add($enableConfirmations);

        $advancedConfirmations = $modules->get('InputfieldCheckbox');
        $advancedConfirmations->attr('name', 'enableAdvancedConfirmations');
        $advancedConfirmations->label = $this->_('Enable reviewed POST/form/code confirmation workflows');
        $advancedConfirmations->description = $this->_('Allows only bounded, allowlisted POST forms containing hidden values, one confirmation submit control, and optionally one email code field. Passwords, file uploads, arbitrary fields, JavaScript, and redirects are never executed.');
        $advancedConfirmations->attr('checked', (bool) $this->enableAdvancedConfirmations);
        $advancedConfirmations->showIf = 'enableLinkConfirmations=1';
        $agents->add($advancedConfirmations);

        $approvalIntegration = $modules->get('InputfieldSelect');
        $approvalIntegration->attr('name', 'approvalIntegration');
        $approvalIntegration->label = $this->_('External approval workspace');
        $approvalIntegration->description = $this->_('Creates redacted review items through verified public APIs. Mailbox remains authoritative and keeps its own permission and separation-of-duties checks.');
        $approvalIntegration->addOptions(['none' => $this->_('None'), 'verk' => 'Verk', 'kontor' => 'Kontor AI', 'both' => $this->_('Verk and Kontor AI')]);
        $approvalIntegration->attr('value', (string) $this->approvalIntegration);
        $approvalIntegration->showIf = 'enableLinkConfirmations=1';
        $agents->add($approvalIntegration);

        $kontorOrganization = $modules->get('InputfieldText');
        $kontorOrganization->attr('name', 'kontorOrganizationUid');
        $kontorOrganization->label = $this->_('Kontor organization UID');
        $kontorOrganization->description = $this->_('Required when Kontor AI is selected; Mailbox never guesses or creates an organization.');
        $kontorOrganization->attr('value', (string) $this->kontorOrganizationUid);
        $kontorOrganization->showIf = 'approvalIntegration=kontor|both';
        $agents->add($kontorOrganization);

        $allowedHosts = $modules->get('InputfieldTextarea');
        $allowedHosts->attr('name', 'allowedConfirmationHosts');
        $allowedHosts->label = $this->_('Allowed confirmation hosts');
        $allowedHosts->description = $this->_('One exact hostname or wildcard such as *.example.com per line. IP addresses and URL schemes are not accepted.');
        $allowedHosts->attr('value', (string) $this->allowedConfirmationHosts);
        $allowedHosts->showIf = 'enableLinkConfirmations=1';
        $agents->add($allowedHosts);

        $confirmationTimeout = $modules->get('InputfieldInteger');
        $confirmationTimeout->attr('name', 'confirmationTimeout');
        $confirmationTimeout->label = $this->_('Confirmation request timeout (seconds)');
        $confirmationTimeout->attr('value', (int) $this->confirmationTimeout);
        $confirmationTimeout->columnWidth = 50;
        $confirmationTimeout->showIf = 'enableLinkConfirmations=1';
        $agents->add($confirmationTimeout);

        $confirmationBytes = $modules->get('InputfieldInteger');
        $confirmationBytes->attr('name', 'maxConfirmationResponseBytes');
        $confirmationBytes->label = $this->_('Maximum confirmation response bytes');
        $confirmationBytes->attr('value', (int) $this->maxConfirmationResponseBytes);
        $confirmationBytes->columnWidth = 50;
        $confirmationBytes->showIf = 'enableLinkConfirmations=1';
        $agents->add($confirmationBytes);

        $inputfields->add($agents);

        $test = $modules->get('InputfieldMarkup');
        $test->attr('name', 'mailboxTest');
        $test->label = $this->_('Connection test');
        $test->icon = 'check-circle';
        $test->value = '<div class="MailboxConfig-test"><div><strong>' . $this->_('Save first, then verify both transports') . '</strong><p>' . $this->_('The Mailbox screen tests TLS, authentication, the default folder, folder discovery, and SMTP without modifying messages or sending mail.') . '</p></div><a class="uk-button uk-button-primary" href="' . $entities($mailboxUrl) . '">' . $this->_('Open connection tests') . '</a></div>';
        $inputfields->add($test);

        return $inputfields;
    }
}

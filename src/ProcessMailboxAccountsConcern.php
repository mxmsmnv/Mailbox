<?php namespace ProcessWire;

/** Three-account admin management and account-scoped workspace selection. */
trait ProcessMailboxAccountsConcern {

    public function ___executeAccounts(): string {
        $this->configureSectionChrome($this->_('Accounts'));
        $this->assertAccountAdministrator();
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
            $this->handleAccountAction();
            $this->wire()->session->redirect(rtrim((string) $this->wire()->page->url, '/') . '/accounts/');
            return '';
        }
        return "<div class='ProcessMailbox pw-module-workspace'>" . $this->renderTabs('accounts') . $this->renderAccountManager() . '</div>';
    }

    protected function selectedAccountId(): int {
        $requested = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
            ? (int) $this->wire()->input->post->account
            : (int) $this->wire()->input->get->account;
        $fallback = 0;
        foreach($this->mailbox->getAccounts() as $account) {
            if(empty($account['enabled'])) continue;
            if(!empty($account['is_default'])) $fallback = (int) $account['id'];
            if($requested > 0 && (int) $account['id'] === $requested) return $requested;
            if($fallback === 0) $fallback = (int) $account['id'];
        }
        if($requested > 0) throw new WireException($this->_('The selected mailbox account is unavailable.'));
        if($fallback > 0) return $fallback;
        throw new WireException($this->_('No enabled mailbox account is available.'));
    }

    protected function renderAccountSwitcher(): string {
        $items = '';
        foreach($this->mailbox->getAccounts() as $account) {
            if(empty($account['enabled'])) continue;
            $id = (int) $account['id'];
            $active = $id === $this->activeAccountId ? ' uk-button-primary' : ' uk-button-default';
            $default = !empty($account['is_default']) ? " <span class='uk-text-small' aria-label='" . $this->e($this->_('Default account')) . "'>★</span>" : '';
            $items .= "<a class='uk-button uk-button-small{$active}' href='" . $this->e($this->url(['account' => $id])) . "'>" . $this->e((string) $account['label']) . "{$default}</a>";
        }
        return "<nav class='mc-account-switcher Mailbox-account-switcher' aria-label='" . $this->e($this->_('Mailbox accounts')) . "'><div class='uk-button-group'>{$items}</div></nav>";
    }

    private function handleAccountAction(): void {
        $action = strtolower((string) $this->wire()->input->post->account_action);
        $id = max(0, (int) $this->wire()->input->post->id);
        if($action === 'save') {
            $this->saveAccountFromRequest($id);
            return;
        }
        if($id < 1) throw new WireException($this->_('Invalid mailbox account.'));
        if($action === 'test') {
            try {
                $account = $this->mailbox->getAccount($id);
                $result = $this->mailbox->withAccount($id, function(): array {
                    return $this->mailbox->testAuthentication();
                });
                $this->message(sprintf(
                    $this->_('%1$s IMAP login succeeded in %2$d ms using %3$s. No folders or messages were downloaded.'),
                    (string) $account['label'],
                    (int) $result['elapsed_ms'],
                    (string) ($result['imap_transport'] ?? 'IMAP')
                ));
            } catch(\Throwable $error) {
                $detail = $error->getMessage();
                if(stripos($detail, 'No SNI provided') !== false) {
                    $detail .= ' ' . $this->_('The local PHP IMAP TLS client did not send SNI. For Gmail, use OAuth2/XOAUTH2 or update the IMAP TLS runtime; do not disable certificate validation.');
                }
                $this->error($this->_('IMAP login failed:') . ' ' . $detail);
            }
            return;
        }
        if($action === 'default') {
            $this->mailbox->setDefaultAccount($id);
            $this->message($this->_('Default mailbox changed.'));
            return;
        }
        if($action === 'delete') {
            if((string) $this->wire()->input->post->confirm !== 'DELETE') throw new WireException($this->_('Type DELETE to confirm account removal.'));
            $this->mailbox->deleteAccount($id);
            $this->message($this->_('Mailbox account and its encrypted credentials were deleted.'));
            return;
        }
        throw new WireException($this->_('Unknown mailbox account action.'));
    }

    private function saveAccountFromRequest(int $id): void {
        $input = $this->wire()->input->post;
        $label = trim((string) $input->label);
        $presetKey = (string) $input->preset;
        $presets = $this->mailbox->getPresets();
        if(!isset($presets[$presetKey])) throw new WireException($this->_('Invalid mailbox preset.'));
        $existing = $id > 0 ? $this->mailbox->getAccount($id) : null;
        $settings = $existing ? (array) $existing['settings'] : (array) $presets[$presetKey];
        $submitted = [
            'preset' => $presetKey,
            'host' => trim((string) $input->host),
            'port' => (int) $input->port,
            'encryption' => (string) $input->encryption,
            'imapTransport' => (string) $input->imapTransport,
            'validateCertificate' => (int) $input->validateCertificate,
            'authentication' => (string) $input->authentication,
            'oauthProvider' => (string) $input->oauthProvider,
            'oauthClientId' => trim((string) $input->oauthClientId),
            'oauthTenant' => trim((string) $input->oauthTenant),
            'defaultFolder' => trim((string) $input->defaultFolder),
            'folderPattern' => trim((string) $input->folderPattern),
            'smtpHost' => trim((string) $input->smtpHost),
            'smtpPort' => (int) $input->smtpPort,
            'smtpEncryption' => (string) $input->smtpEncryption,
            'smtpValidateCertificate' => (int) $input->smtpValidateCertificate,
            'saveSentCopies' => (int) $input->saveSentCopies,
            'sentFolder' => trim((string) $input->sentFolder),
        ];
        if(!$existing && $presetKey !== 'custom') {
            foreach(['port', 'encryption', 'imapTransport', 'validateCertificate', 'authentication', 'oauthProvider', 'smtpPort', 'smtpEncryption', 'smtpValidateCertificate'] as $name) {
                $submitted[$name] = $presets[$presetKey][$name];
            }
            if($submitted['host'] === '') $submitted['host'] = (string) $presets[$presetKey]['host'];
            if($submitted['smtpHost'] === '') $submitted['smtpHost'] = (string) $presets[$presetKey]['smtpHost'];
        }
        $settings = array_merge($settings, $submitted);
        $username = trim((string) $input->username);
        $password = (string) $input->password;
        $authentication = $settings['authentication'] === 'oauth' ? 'oauth' : 'password';
        $previousAuthentication = $existing ? (string) ($existing['settings']['authentication'] ?? 'password') : '';
        $oauthIdentityChanged = $existing && $authentication === 'oauth' && (
            $previousAuthentication !== 'oauth'
            || (string) ($existing['settings']['oauthProvider'] ?? '') !== (string) $settings['oauthProvider']
            || (string) ($existing['settings']['oauthClientId'] ?? '') !== (string) $settings['oauthClientId']
            || (string) ($existing['settings']['oauthTenant'] ?? 'common') !== (string) $settings['oauthTenant']
        );
        $makeDefault = (bool) (int) $input->make_default;
        $enabled = $existing && !empty($existing['is_default']) ? true : (bool) (int) $input->enabled;

        if($id < 1) {
            if($authentication === 'oauth') {
                if($username === '') throw new WireException($this->_('OAuth mailbox username is required.'));
                if($password !== '') throw new WireException($this->_('Do not enter a password for an OAuth mailbox.'));
                $account = $this->mailbox->createOAuthAccount($label, $settings, $username, $makeDefault);
            } else {
                if($username === '' || $password === '') throw new WireException($this->_('Username and password are required for a new password mailbox.'));
                $account = $this->mailbox->createAccount($label, $settings, $username, $password, $makeDefault);
            }
            $this->message(sprintf($this->_('Mailbox account %s created.'), (string) $account['label']));
            return;
        }

        if($authentication === 'oauth') {
            if($password !== '') throw new WireException($this->_('Do not enter a password for an OAuth mailbox.'));
            if($oauthIdentityChanged && $username === '') throw new WireException($this->_('Enter the OAuth mailbox username when changing authentication, provider, client ID, or tenant. The account must then be reconnected.'));
            $account = $this->mailbox->updateAccount($id, $label, $settings, null, null, $enabled);
            if($username !== '') $this->mailbox->prepareOAuthIdentity($id, $username, (string) $account['settings']['oauthProvider']);
        } else {
            if(($username === '') xor ($password === '')) throw new WireException($this->_('Provide both username and password when replacing credentials.'));
            if($previousAuthentication === 'oauth' && ($username === '' || $password === '')) throw new WireException($this->_('Username and password are required when changing an OAuth account to password authentication.'));
            $account = $this->mailbox->updateAccount($id, $label, $settings, $username !== '' ? $username : null, $password !== '' ? $password : null, $enabled);
        }
        if($makeDefault && empty($account['is_default'])) $this->mailbox->setDefaultAccount($id);
        $this->message(sprintf($this->_('Mailbox account %s updated.'), (string) $account['label']));
    }

    private function renderAccountManager(): string {
        $accounts = $this->mailbox->getAccounts();
        $limit = $this->mailbox->getAccountLimit();
        $enabled = count(array_filter($accounts, static function(array $account): bool { return !empty($account['enabled']); }));
        $defaultLabel = '';
        foreach($accounts as $account) if(!empty($account['is_default'])) $defaultLabel = (string) $account['label'];
        $cards = '';
        foreach($accounts as $account) $cards .= $this->renderAccountForm($account);
        if(count($accounts) < $limit) $cards .= $this->renderAccountForm(null);
        $presetJson = json_encode($this->mailbox->getPresets(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if(!is_string($presetJson)) $presetJson = '{}';
        $base = rtrim((string) $this->wire()->page->url, '/');
        return "<section class='Mailbox-accounts-hero'><div><span class='Mailbox-eyebrow'>" . $this->_('Three-mailbox workspace') . "</span><h2 class='uk-h2 uk-margin-small-top uk-margin-small-bottom'>" . $this->_('Connect and manage mailbox accounts') . "</h2><p class='uk-text-lead uk-margin-remove'>" . $this->_('Each account keeps its connection profile separate and stores its username, password, and OAuth tokens in an encrypted account-scoped credential row. Existing secrets are never displayed back to the browser.') . "</p></div><div class='Mailbox-accounts-hero-actions'><a class='uk-button uk-button-default' href='" . $this->e($base . '/discover/') . "'><span uk-icon='search' class='uk-margin-small-right'></span>" . $this->_('Discover settings') . "</a><a class='uk-button uk-button-primary' href='" . $this->e($this->url()) . "'><span uk-icon='mail' class='uk-margin-small-right'></span>" . $this->_('Open mailbox') . "</a></div></section>
        <section class='Mailbox-account-metrics'><div><span uk-icon='server'></span><small>" . $this->_('Slots used') . "</small><strong>" . count($accounts) . " / {$limit}</strong></div><div><span uk-icon='check'></span><small>" . $this->_('Enabled accounts') . "</small><strong>{$enabled}</strong></div><div><span uk-icon='star'></span><small>" . $this->_('Default mailbox') . "</small><strong>" . $this->e($defaultLabel ?: $this->_('Not selected')) . "</strong></div></section>
        <section class='uk-card uk-card-default uk-card-small uk-card-body Mailbox-accounts-guide'><div><span uk-icon='lock'></span><div><strong>" . $this->_('Safe credential replacement') . "</strong><p>" . $this->_('Leave username and password blank to keep the encrypted credentials. Enter both fields only when replacing a password login.') . "</p></div></div><div><span uk-icon='refresh'></span><div><strong>" . $this->_('OAuth requires a separate connection') . "</strong><p>" . $this->_('Save provider settings first, then use Connect with OAuth. Client secrets stay in config.php, Vault, or KMS.') . "</p></div></div><div><span uk-icon='check'></span><div><strong>" . $this->_('Test the saved login') . "</strong><p>" . $this->_('Use Test connection beside an account. It authenticates only and never downloads folders or messages.') . "</p></div></div></section>
        <div class='Mailbox-accounts-heading'><div><span class='Mailbox-eyebrow'>" . $this->_('Account profiles') . "</span><h2 class='uk-h3 uk-margin-small-top uk-margin-remove-bottom'>" . $this->_('Configured mailboxes and available slot') . "</h2></div><span class='uk-text-meta'>" . sprintf($this->_('%d of %d slots available'), max(0, $limit - count($accounts)), $limit) . "</span></div><script type='application/json' id='MailboxAccountPresets'>{$presetJson}</script><div class='Mailbox-account-list'>{$cards}</div>";
    }

    private function renderAccountForm(?array $account): string {
        $new = $account === null;
        $id = $new ? 0 : (int) $account['id'];
        if(!$new && $id === 1) return $this->renderPrimaryAccountCard($account);
        $settings = $new ? (array) $this->mailbox->getPresets()['custom'] : (array) $account['settings'];
        $label = $new ? '' : (string) $account['label'];
        $status = $new ? ['configured' => false, 'password_source' => 'none'] : (array) $account['credentials'];
        $presetOptions = '';
        $presetGroup = '';
        foreach($this->mailbox->getPresets() as $key => $preset) {
            $group = (string) ($preset['group'] ?? $this->_('Other'));
            if($group !== $presetGroup) {
                if($presetGroup !== '') $presetOptions .= '</optgroup>';
                $presetOptions .= "<optgroup label='" . $this->e($group) . "'>";
                $presetGroup = $group;
            }
            $selected = (string) ($settings['preset'] ?? 'custom') === $key ? ' selected' : '';
            $presetOptions .= "<option value='" . $this->e($key) . "'{$selected}>" . $this->e((string) $preset['label']) . '</option>';
        }
        if($presetGroup !== '') $presetOptions .= '</optgroup>';
        $select = static function(string $actual, string $expected): string { return $actual === $expected ? ' selected' : ''; };
        $checked = static function(bool $value): string { return $value ? ' checked' : ''; };
        $csrf = $this->wire()->session->CSRF->renderInput();
        $title = $new ? $this->_('Add another mailbox') : $this->e($label);
        $credentialHint = !empty($status['configured']) ? sprintf($this->_('Credentials configured (%s). Leave both fields blank to keep them.'), (string) $status['password_source']) : $this->_('Credentials are not configured.');
        $actions = "<button class='uk-button uk-button-primary' type='submit'><span uk-icon='check' class='uk-margin-small-right'></span>" . ($new ? $this->_('Add mailbox') : $this->_('Save changes')) . '</button>';
        if(!$new) {
            $actions .= " <button class='uk-button uk-button-default' type='submit' name='account_action' value='test' formnovalidate><span uk-icon='bolt' class='uk-margin-small-right'></span>" . $this->_('Test connection') . '</button>';
        }
        if(!$new && empty($account['is_default'])) {
            $actions .= " <button class='uk-button uk-button-default' type='submit' name='make_default' value='1'><span uk-icon='star' class='uk-margin-small-right'></span>" . $this->_('Save and make default') . '</button>';
        }
        $oauth = '';
        if(!$new && (string) ($settings['authentication'] ?? '') === 'oauth') {
            $oauthUrl = rtrim((string) $this->wire()->page->url, '/') . '/oauth-start/';
            $oauth = "<form method='post' action='" . $this->e($oauthUrl) . "' class='uk-display-inline'>{$csrf}<input type='hidden' name='account' value='{$id}'><button class='uk-button uk-button-secondary' type='submit'><span uk-icon='sign-in' class='uk-margin-small-right'></span>" . $this->_('Connect with OAuth') . '</button></form>';
        }
        $danger = '';
        if(!$new && empty($account['is_default'])) {
            $danger = "<details class='Mailbox-account-danger'><summary><span uk-icon='trash'></span>" . $this->_('Delete this account') . "</summary><form method='post'>{$csrf}<input type='hidden' name='account_action' value='delete'><input type='hidden' name='id' value='{$id}'><p>" . $this->_('This removes the profile, encrypted credentials, cached views, index rows, jobs, and notifications for this account.') . "</p><label class='uk-form-label'>" . $this->_('Type DELETE to confirm') . "</label><div class='Mailbox-danger-action'><input class='uk-input' name='confirm' autocomplete='off' maxlength='6' placeholder='DELETE'><button class='uk-button uk-button-danger' type='submit'>" . $this->_('Delete account') . '</button></div></form></details>';
        }
        $badges = $new ? "<span class='Mailbox-account-badge is-new'>" . $this->_('Available slot') . "</span>" : "<span class='Mailbox-account-badge " . (!empty($account['enabled']) ? 'is-enabled' : 'is-disabled') . "'>" . (!empty($account['enabled']) ? $this->_('Enabled') : $this->_('Disabled')) . "</span>" . (!empty($account['is_default']) ? "<span class='Mailbox-account-badge is-default'>" . $this->_('Default') . "</span>" : '') . "<span class='Mailbox-account-badge " . (!empty($status['configured']) ? 'is-secure' : 'is-warning') . "'>" . (!empty($status['configured']) ? $this->_('Credentials stored') : $this->_('Credentials missing')) . "</span>";
        $open = $new ? ' open' : '';
        return "<details class='uk-card uk-card-default Mailbox-account-card" . ($new ? ' is-new' : '') . "'{$open}><summary><div class='Mailbox-account-summary-main'><span class='Mailbox-account-icon' uk-icon='" . ($new ? 'plus' : 'mail') . "'></span><div><h3 class='uk-h3 uk-margin-remove'>{$title}</h3><p class='uk-text-meta uk-margin-remove-top'>" . ($new ? $this->_('Configure one more independent mailbox profile.') : $this->e((string) ($settings['host'] ?? '')) . ':' . (int) ($settings['port'] ?? 993) . ' · ' . strtoupper((string) ($settings['authentication'] ?? 'password'))) . "</p></div></div><div class='Mailbox-account-badges'>{$badges}<span class='Mailbox-account-chevron' uk-icon='chevron-down'></span></div></summary><div class='Mailbox-account-card-body'><form method='post' data-mailbox-account-form>{$csrf}<input type='hidden' name='account_action' value='save'><input type='hidden' name='id' value='{$id}'>
            <section class='Mailbox-account-section'><header><span uk-icon='user'></span><div><h4>" . $this->_('Identity and provider') . "</h4><p>" . $this->_('Name this profile and select a starting template and login method.') . "</p></div></header><div class='Mailbox-account-grid is-three'><div><label class='uk-form-label'>" . $this->_('Account label') . "</label><input class='uk-input' name='label' maxlength='190' placeholder='" . $this->e($this->_('Support mailbox')) . "' required value='" . $this->e($label) . "'></div><div><label class='uk-form-label'>" . $this->_('Service preset') . "</label><select class='uk-select' name='preset' data-mailbox-preset-select>{$presetOptions}</select><div class='Mailbox-preset-control'><button class='uk-button uk-button-default uk-button-small' type='button' data-mailbox-apply-preset>" . $this->_('Apply preset values') . "</button><small data-mailbox-preset-note>" . $this->_('Provides editable defaults; it does not detect or verify the server.') . "</small></div></div><div><label class='uk-form-label'>" . $this->_('Authentication') . "</label><select class='uk-select' name='authentication' data-mailbox-auth-select><option value='password'" . $select((string) ($settings['authentication'] ?? 'password'), 'password') . ">" . $this->_('Password / app password') . "</option><option value='oauth'" . $select((string) ($settings['authentication'] ?? ''), 'oauth') . ">OAuth2 / XOAUTH2</option></select></div></div></section>
            <section class='Mailbox-account-section'><header><span uk-icon='lock'></span><div><h4>" . $this->_('Credentials') . "</h4><p>" . $this->e($credentialHint) . " " . $this->_('Saved usernames and secrets are never rendered.') . "</p></div></header><div class='Mailbox-account-grid is-two'><div><label class='uk-form-label'>" . $this->_('New username / mailbox address') . "</label><input class='uk-input' name='username' maxlength='320' autocomplete='off' placeholder='name@example.com'><small>" . $this->_('Leave blank to keep the stored encrypted username.') . "</small></div><div data-mailbox-auth-panel='password'><label class='uk-form-label'>" . $this->_('New password / app password') . "</label><input class='uk-input' type='password' name='password' maxlength='32768' autocomplete='new-password'><small>" . $this->_('Provide username and password together when replacing credentials.') . "</small></div><div class='Mailbox-oauth-fields' data-mailbox-auth-panel='oauth'><div><label class='uk-form-label'>OAuth provider</label><select class='uk-select' name='oauthProvider'><option value=''>—</option><option value='google'" . $select((string) ($settings['oauthProvider'] ?? ''), 'google') . ">Google</option><option value='microsoft'" . $select((string) ($settings['oauthProvider'] ?? ''), 'microsoft') . ">Microsoft</option></select></div><div><label class='uk-form-label'>OAuth client ID</label><input class='uk-input' name='oauthClientId' maxlength='255' value='" . $this->e((string) ($settings['oauthClientId'] ?? '')) . "'></div><div><label class='uk-form-label'>OAuth tenant</label><input class='uk-input' name='oauthTenant' maxlength='253' value='" . $this->e((string) ($settings['oauthTenant'] ?? 'common')) . "'></div></div></div></section>
            <div class='Mailbox-account-endpoints'><section class='Mailbox-account-section'><header><span uk-icon='download'></span><div><h4>" . $this->_('Incoming mail · IMAP') . "</h4><p>" . $this->_('Used to list folders and read messages.') . "</p></div></header><div class='Mailbox-account-grid is-endpoint'><div class='is-host'><label class='uk-form-label'>" . $this->_('IMAP host') . "</label><input class='uk-input' name='host' required value='" . $this->e((string) ($settings['host'] ?? '')) . "'></div><div><label class='uk-form-label'>" . $this->_('Port') . "</label><input class='uk-input' type='number' min='1' max='65535' name='port' value='" . (int) ($settings['port'] ?? 993) . "'></div><div><label class='uk-form-label'>" . $this->_('Security') . "</label><select class='uk-select' name='encryption'><option value='ssl'" . $select((string) ($settings['encryption'] ?? 'ssl'), 'ssl') . ">SSL/TLS</option><option value='tls'" . $select((string) ($settings['encryption'] ?? ''), 'tls') . ">STARTTLS</option></select></div></div><div class='Mailbox-account-transport'><label class='uk-form-label'>" . $this->_('IMAP engine') . "</label><select class='uk-select' name='imapTransport'><option value='auto'" . $select((string) ($settings['imapTransport'] ?? 'auto'), 'auto') . ">" . $this->_('Auto (recommended)') . "</option><option value='webklex'" . $select((string) ($settings['imapTransport'] ?? ''), 'webklex') . ">Webklex</option><option value='native'" . $select((string) ($settings['imapTransport'] ?? ''), 'native') . ">" . $this->_('Native PHP IMAP') . "</option></select><small>" . $this->_('Auto uses Webklex for Gmail and other hosted services, avoiding local MAMP SNI limitations.') . "</small></div><label class='Mailbox-account-check'><input type='hidden' name='validateCertificate' value='0'><input class='uk-checkbox' type='checkbox' name='validateCertificate' value='1'" . $checked(!empty($settings['validateCertificate'])) . "> <span>" . $this->_('Validate the IMAP TLS certificate') . "</span></label></section>
            <section class='Mailbox-account-section'><header><span uk-icon='upload'></span><div><h4>" . $this->_('Outgoing mail · SMTP') . "</h4><p>" . $this->_('Used only when sending is separately enabled.') . "</p></div></header><div class='Mailbox-account-grid is-endpoint'><div class='is-host'><label class='uk-form-label'>" . $this->_('SMTP host') . "</label><input class='uk-input' name='smtpHost' value='" . $this->e((string) ($settings['smtpHost'] ?? '')) . "'></div><div><label class='uk-form-label'>" . $this->_('Port') . "</label><input class='uk-input' type='number' min='1' max='65535' name='smtpPort' value='" . (int) ($settings['smtpPort'] ?? 587) . "'></div><div><label class='uk-form-label'>" . $this->_('Security') . "</label><select class='uk-select' name='smtpEncryption'><option value='tls'" . $select((string) ($settings['smtpEncryption'] ?? 'tls'), 'tls') . ">STARTTLS</option><option value='ssl'" . $select((string) ($settings['smtpEncryption'] ?? ''), 'ssl') . ">SSL/TLS</option></select></div></div><label class='Mailbox-account-check'><input type='hidden' name='smtpValidateCertificate' value='0'><input class='uk-checkbox' type='checkbox' name='smtpValidateCertificate' value='1'" . $checked(!empty($settings['smtpValidateCertificate'])) . "> <span>" . $this->_('Validate the SMTP TLS certificate') . "</span></label></section></div>
            <details class='Mailbox-account-advanced'><summary><span uk-icon='settings'></span>" . $this->_('Folders and account behavior') . "</summary><div class='Mailbox-account-grid is-three'><div><label class='uk-form-label'>" . $this->_('Default folder') . "</label><input class='uk-input' name='defaultFolder' value='" . $this->e((string) ($settings['defaultFolder'] ?? 'INBOX')) . "'></div><div><label class='uk-form-label'>" . $this->_('Folder pattern') . "</label><input class='uk-input' name='folderPattern' value='" . $this->e((string) ($settings['folderPattern'] ?? '*')) . "'></div><div><label class='uk-form-label'>" . $this->_('Sent folder') . "</label><input class='uk-input' name='sentFolder' value='" . $this->e((string) ($settings['sentFolder'] ?? 'Sent')) . "'></div></div><div class='Mailbox-account-options'><label class='Mailbox-account-check'><input type='hidden' name='saveSentCopies' value='0'><input class='uk-checkbox' type='checkbox' name='saveSentCopies' value='1'" . $checked(!empty($settings['saveSentCopies'])) . "> <span>" . $this->_('Save an IMAP Sent copy after successful SMTP delivery') . "</span></label><label class='Mailbox-account-check'><input type='hidden' name='enabled' value='0'><input class='uk-checkbox' type='checkbox' name='enabled' value='1'" . $checked($new || !empty($account['enabled'])) . (!empty($account['is_default']) ? ' disabled' : '') . "> <span>" . $this->_('Enabled in the mailbox switcher') . "</span></label></div></details>
            <footer class='Mailbox-account-actions'><div>{$actions}</div><p><span uk-icon='lock'></span>" . $this->_('Test connection uses the saved profile and credentials without loading folders or messages.') . "</p></footer></form><div class='Mailbox-oauth-action'>{$oauth}</div>{$danger}</div></details>";
    }

    private function assertAccountAdministrator(): void {
        if(!$this->wire()->user->isSuperuser()) throw new WirePermissionException($this->_('Only a superuser may manage mailbox accounts and credentials.'));
    }

    private function renderPrimaryAccountCard(array $account): string {
        $settingsUrl = $this->settingsUrl();
        $status = (array) $account['credentials'];
        $settings = (array) $account['settings'];
        $defaultAction = '';
        if(empty($account['is_default'])) {
            $defaultAction = "<form method='post' class='uk-display-inline'>" . $this->wire()->session->CSRF->renderInput() . "<input type='hidden' name='account_action' value='default'><input type='hidden' name='id' value='1'><button class='uk-button uk-button-default' type='submit'><span uk-icon='star' class='uk-margin-small-right'></span>" . $this->_('Make default') . '</button></form>';
        }
        $credentialBadge = !empty($status['configured']) ? "<span class='Mailbox-account-badge is-secure'>" . $this->_('Credentials stored') . "</span>" : "<span class='Mailbox-account-badge is-warning'>" . $this->_('Credentials missing') . "</span>";
        $smtpHost = (string) ($settings['smtpHost'] ?? '');
        $testAction = "<form method='post' class='uk-display-inline'>" . $this->wire()->session->CSRF->renderInput() . "<input type='hidden' name='account_action' value='test'><input type='hidden' name='id' value='1'><button class='uk-button uk-button-default' type='submit'><span uk-icon='bolt' class='uk-margin-small-right'></span>" . $this->_('Test connection') . '</button></form>';
        return "<article class='uk-card uk-card-default Mailbox-account-card is-primary'><header class='Mailbox-primary-header'><div class='Mailbox-account-summary-main'><span class='Mailbox-account-icon' uk-icon='home'></span><div><span class='Mailbox-eyebrow'>" . $this->_('Primary profile · Account 1') . "</span><h3 class='uk-h3 uk-margin-small-top uk-margin-remove-bottom'>" . $this->e((string) $account['label']) . "</h3><p class='uk-text-meta uk-margin-remove-top'>" . $this->_('Managed by the canonical Mailbox module settings.') . "</p></div></div><div class='Mailbox-account-badges'>" . (!empty($account['is_default']) ? "<span class='Mailbox-account-badge is-default'>" . $this->_('Default') . "</span>" : '') . "<span class='Mailbox-account-badge is-enabled'>" . $this->_('Enabled') . "</span>{$credentialBadge}</div></header><div class='Mailbox-primary-body'><div class='Mailbox-primary-endpoints'><div><small>IMAP · " . $this->_('Incoming') . "</small><strong>" . $this->e((string) ($settings['host'] ?? '')) . ':' . (int) ($settings['port'] ?? 993) . "</strong><span>" . strtoupper((string) ($settings['encryption'] ?? 'ssl')) . "</span></div><div><small>SMTP · " . $this->_('Outgoing') . "</small><strong>" . ($smtpHost !== '' ? $this->e($smtpHost) . ':' . (int) ($settings['smtpPort'] ?? 587) : $this->_('Not configured')) . "</strong><span>" . ($smtpHost !== '' ? strtoupper((string) ($settings['smtpEncryption'] ?? 'tls')) : $this->_('Optional')) . "</span></div><div><small>" . $this->_('Authentication') . "</small><strong>" . ((string) ($settings['authentication'] ?? 'password') === 'oauth' ? 'OAuth2 / XOAUTH2' : $this->_('Password / app password')) . "</strong><span>" . $this->_('Secrets hidden') . "</span></div></div><div class='Mailbox-primary-notice'><span uk-icon='info'></span><p>" . $this->_('The primary profile is synchronized with the main module configuration so there is only one source of truth. Use Settings to change its server values or replace credentials.') . "</p></div><div class='Mailbox-primary-actions'><a class='uk-button uk-button-primary' href='" . $this->e($settingsUrl) . "'><span uk-icon='settings' class='uk-margin-small-right'></span>" . $this->_('Edit primary settings') . "</a>{$testAction}{$defaultAction}</div></div></article>";
    }
}

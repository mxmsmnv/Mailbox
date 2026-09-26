<?php namespace ProcessWire;

require_once __DIR__ . '/src/ProcessMailboxAccountsConcern.php';
require_once __DIR__ . '/src/ProcessMailboxDiscoveryConcern.php';
require_once __DIR__ . '/src/ProcessMailboxBulkConcern.php';
require_once __DIR__ . '/src/ProcessMailboxAiConcern.php';
require_once __DIR__ . '/src/MailboxDependencyInstaller.php';
require_once __DIR__ . '/src/MailboxMessageView.php';

/**
 * ProcessMailbox
 *
 * Admin-only IMAP browser and mail workspace.
 */
class ProcessMailbox extends Process implements Module {

    use ProcessMailboxAccountsConcern;
    use ProcessMailboxDiscoveryConcern;
    use ProcessMailboxBulkConcern;
    use ProcessMailboxAiConcern;

    /** @var Mailbox */
    protected $mailbox;

    /** @var int */
    protected $activeAccountId = 0;

    private const VIEW_CACHE_MAX_AGE = 86400;
    private const VIEW_CACHE_REFRESH_AGE = 30;

    public static function getModuleInfo() {
        return [
            'title' => 'Mailbox (admin)',
            'summary' => 'Secure three-account mail workspace for the ProcessWire admin.',
            'version' => 101,
            'author' => 'Maxim Semenov',
            'href' => 'https://github.com/mxmsmnv/Mailbox',
            'singular' => true,
            'autoload' => false,
            'requires' => ['Mailbox', 'AdminThemeUikit'],
            'icon' => 'envelope',
            'permission' => 'mailbox-view',
            'page' => [
                'name' => 'mailbox',
                'parent' => 'setup',
                'title' => 'Mailbox',
            ],
        ];
    }

    public function init() {
        parent::init();
        $this->mailbox = $this->wire()->modules->get('Mailbox');
        $url = $this->wire()->config->urls('ProcessMailbox');
        $version = (string) max((int) @filemtime(__DIR__ . '/css/mailbox.admin.css'), (int) @filemtime(__DIR__ . '/js/mailbox.admin.js'), (int) self::getModuleInfo()['version']);
        $this->wire()->config->styles->add($url . 'css/mailbox.admin.css?v=' . $version);
        $this->wire()->config->scripts->add($url . 'js/mailbox.admin.js?v=' . $version);
    }

    public function ___execute() {
        $this->headline($this->_('Mailbox'));
        $this->browserTitle($this->_('Mailbox'));

        try {
            $this->activeAccountId = $this->selectedAccountId();
            return $this->mailbox->withAccount($this->activeAccountId, function(): string {
                return $this->executeSelectedAccount();
            });
        } catch(\Throwable $error) {
            return $this->renderError($error->getMessage());
        }
    }

    protected function executeSelectedAccount(): string {
        try {
            $page = max(1, (int) $this->wire()->input->get->p);
            $uid = max(0, (int) $this->wire()->input->get->uid);
            if($uid > 0) header('Referrer-Policy: no-referrer');
            $warming = (int) $this->wire()->input->get->cache_warm === 1;
            $refresh = (int) $this->wire()->input->get->refresh === 1;

            if($warming || $refresh) return $this->refreshSelectedAccountCache($page, $uid, $warming);

            $folderCache = $this->mailbox->getCachedFolders(self::VIEW_CACHE_MAX_AGE);
            if($folderCache === null) {
                $folder = $this->mailbox->getDefaultFolder();
                return $this->renderLoadingWorkspace([], $folder, $page, $uid);
            }
            $folders = (array) ($folderCache['value']['folders'] ?? []);
            $folder = $this->selectedFolder($folders);

            if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && (int) $this->wire()->input->post->search) {
                if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
                $scope = (string) $this->wire()->input->post->scope === 'all' ? null : $folder;
                $result = $this->mailbox->searchMessages($this->adminSearchFilters($this->wire()->input->post), $scope, max(1, (int) $this->wire()->input->post->p), (int) $this->mailbox->messagesPerPage);
                $this->configureWorkspaceChrome($folder, null, $page, true);
                return $this->renderLayout($folders, $folder, $this->renderSearchResults($result));
            }

            if($uid) {
                $messageCache = $this->mailbox->getCachedMessage($folder, $uid, self::VIEW_CACHE_MAX_AGE);
                if($messageCache === null) return $this->renderLoadingWorkspace($folders, $folder, $page, $uid);
                $age = max((int) $folderCache['age'], (int) $messageCache['age']);
                $cachedMessage = (array) ($messageCache['value']['message'] ?? []);
                if(!array_key_exists('html', $cachedMessage) || !array_key_exists('raw', $cachedMessage) || !array_key_exists('seen', $cachedMessage)) return $this->renderLoadingWorkspace($folders, $folder, $page, $uid);
                $this->configureWorkspaceChrome($folder, $cachedMessage, $page);
                return $this->renderLayout($folders, $folder, $this->renderMessage($folder, $uid, $cachedMessage, $folders, $page), $this->renderCacheStatus($folder, $page, $uid, $age));
            }

            $limit = max(1, min(100, (int) $this->mailbox->messagesPerPage));
            $messageCache = $this->mailbox->getCachedMessages($folder, $page, $limit, self::VIEW_CACHE_MAX_AGE);
            if($messageCache === null) return $this->renderLoadingWorkspace($folders, $folder, $page, 0);
            $result = (array) ($messageCache['value']['result'] ?? []);
            $age = max((int) $folderCache['age'], (int) $messageCache['age']);
            $this->configureWorkspaceChrome($folder, null, $page);
            return $this->renderLayout($folders, $folder, $this->renderMessageList($folder, $result, $folders), $this->renderCacheStatus($folder, $page, 0, $age));
        } catch(\Throwable $error) {
            return $this->renderError($error->getMessage());
        }
    }

    protected function refreshSelectedAccountCache(int $page, int $uid, bool $json): string {
        try {
            $folders = $this->mailbox->listFolders();
            $this->mailbox->cacheFolders($folders);
            $folder = $this->selectedFolder($folders);
            if($uid > 0) {
                $message = $this->mailbox->getMessage($folder, $uid);
                $this->mailbox->cacheMessage($folder, $uid, $message);
                $this->configureWorkspaceChrome($folder, $message, $page);
                $content = $this->renderMessage($folder, $uid, $message, $folders, $page);
            } else {
                $limit = max(1, min(100, (int) $this->mailbox->messagesPerPage));
                $result = $this->mailbox->listMessages($folder, $page, $limit);
                $this->mailbox->cacheMessages($folder, $page, $limit, $result);
                $this->configureWorkspaceChrome($folder, null, $page);
                $content = $this->renderMessageList($folder, $result, $folders);
            }
            if($json) $this->sendCacheWarmResponse(true);
            return $this->renderLayout($folders, $folder, $content, $this->renderCacheStatus($folder, $page, $uid, 0));
        } catch(\Throwable $error) {
            if($json) $this->sendCacheWarmResponse(false);
            throw $error;
        }
    }

    protected function sendCacheWarmResponse(bool $success): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        http_response_code($success ? 200 : 503);
        echo json_encode(['ok' => $success]);
        exit;
    }

    public function ___executeTest() {
        $this->headline($this->_('Test Mailbox connection'));
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            throw new Wire404Exception();
        }
        if(!$this->wire()->session->CSRF->hasValidToken()) {
            throw new WirePermissionException($this->_('Invalid security token.'));
        }

        try {
            $this->activeAccountId = $this->selectedAccountId();
            $result = $this->mailbox->withAccount($this->activeAccountId, function(): array { return $this->mailbox->testConnection(); });
            $this->message(sprintf(
                $this->_('IMAP connection succeeded in %d ms: %d folders, %d messages in the default folder.'),
                $result['elapsed_ms'],
                $result['folders'],
                $result['messages']
            ));
        } catch(\Throwable $error) {
            $this->error($this->_('IMAP connection failed:') . ' ' . $error->getMessage());
        }

        $this->wire()->session->redirect($this->url());
        return '';
    }

    public function ___executeTestSmtp() {
        $this->headline($this->_('Test Mailbox SMTP connection'));
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw new Wire404Exception();
        if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
        try {
            $this->activeAccountId = $this->selectedAccountId();
            $result = $this->mailbox->withAccount($this->activeAccountId, function(): array { return $this->mailbox->testSmtpConnection(); });
            $this->message(sprintf(
                $this->_('SMTP connection and authentication succeeded in %d ms using %s:%d.'),
                $result['elapsed_ms'],
                $result['host'],
                $result['port']
            ));
        } catch(\Throwable $error) {
            $this->error($this->_('SMTP connection failed:') . ' ' . $error->getMessage());
        }
        $this->wire()->session->redirect($this->url());
        return '';
    }

    public function ___executeAttachment() {
        if(!$this->wire()->user->hasPermission(Mailbox::attachmentPermission)) throw new WirePermissionException($this->_('Mailbox attachment permission is required.'));
        $this->activeAccountId = $this->selectedAccountId();
        $folder = $this->decodeFolderToken((string) $this->wire()->input->get->folder);
        $uid = max(0, (int) $this->wire()->input->get->uid);
        $part = (string) $this->wire()->input->get->part;
        $attachment = $this->mailbox->withAccount($this->activeAccountId, function() use ($folder, $uid, $part): array {
            return $this->mailbox->getAttachment($folder, $uid, $part, 'user:' . (int) $this->wire()->user->id);
        });
        $name = (string) $attachment['name'];
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'attachment';
        header('Content-Type: ' . (string) $attachment['type']);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . strlen((string) $attachment['content']));
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo (string) $attachment['content'];
        exit;
    }

    public function ___executeMessageAction() {
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw new Wire404Exception();
        if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
        $redirect = (string) $this->wire()->page->url;
        try {
            $this->activeAccountId = $this->selectedAccountId();
            $folder = $this->decodeFolderToken((string) $this->wire()->input->post->folder);
            $uid = max(0, (int) $this->wire()->input->post->uid);
            if($uid < 1) throw new WireException($this->_('Invalid message UID.'));
            $action = strtolower((string) $this->wire()->input->post->message_action);
            $redirect = $this->url(['folder' => $this->encodeFolderToken($folder), 'uid' => $uid]);
            $actor = 'user:' . (int) $this->wire()->user->id;

            $this->mailbox->withAccount($this->activeAccountId, function() use ($folder, $uid, $action, $actor, &$redirect): void {
                if(in_array($action, ['read', 'unread', 'move', 'delete'], true)) {
                    if(!$this->wire()->user->hasPermission(Mailbox::writePermission)) throw new WirePermissionException($this->_('Mailbox write permission is required.'));
                    if($action === 'read' || $action === 'unread') {
                        $this->mailbox->setMessageFlags($folder, $uid, ['seen'], $action === 'read', $actor);
                        $this->message($action === 'read' ? $this->_('Message marked as read.') : $this->_('Message marked as unread.'));
                        return;
                    }
                    if($action === 'move') {
                        $destination = (string) $this->wire()->input->post->destination;
                        $this->mailbox->moveMessage($folder, $uid, $destination, false, $actor);
                        $redirect = $this->url(['folder' => $this->encodeFolderToken($destination)]);
                        $this->message($this->_('Message moved.'));
                        return;
                    }
                    $this->mailbox->deleteMessage($folder, $uid, false, $actor);
                    $redirect = $this->url(['folder' => $this->encodeFolderToken($folder)]);
                    $this->message($this->_('Message marked for deletion. It was not permanently expunged.'));
                    return;
                }

                if(in_array($action, ['reply', 'forward'], true)) {
                    if(!$this->wire()->user->hasPermission(Mailbox::sendPermission)) throw new WirePermissionException($this->_('Mailbox send permission is required.'));
                    $body = (string) $this->wire()->input->post->body;
                    if($action === 'reply') {
                        $this->mailbox->replyMessage($folder, $uid, $body, (bool) $this->wire()->input->post->reply_all, $actor);
                        $this->message($this->_('Reply sent.'));
                        return;
                    }
                    $recipients = MailboxSmtpTransport::parseAddresses((string) $this->wire()->input->post->to);
                    if(!$recipients) throw new WireException($this->_('Enter a valid forwarding address.'));
                    $this->mailbox->forwardMessage($folder, $uid, $recipients, $body, $actor);
                    $this->message($this->_('Message forwarded.'));
                    return;
                }
                throw new WireException($this->_('Unknown message action.'));
            });
        } catch(\Throwable $error) {
            $this->error($error->getMessage());
        }
        $this->wire()->session->redirect($redirect);
        return '';
    }

    public function ___executeSeen() {
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw new Wire404Exception();
        if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
        try {
            $this->activeAccountId = $this->selectedAccountId();
            $folder = $this->decodeFolderToken((string) $this->wire()->input->post->folder);
            $uid = max(0, (int) $this->wire()->input->post->uid);
            $actor = 'user:' . (int) $this->wire()->user->id;
            $this->mailbox->withAccount($this->activeAccountId, function() use ($folder, $uid, $actor): void {
                $this->mailbox->markMessageReadOnOpen($folder, $uid, $actor);
            });
            $this->sendSeenResponse(true);
        } catch(\Throwable $error) {
            $this->sendSeenResponse(false);
        }
        return '';
    }

    protected function sendSeenResponse(bool $success): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        http_response_code($success ? 200 : 503);
        echo json_encode(['ok' => $success]);
        exit;
    }

    public function ___executeOauthStart() {
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw new Wire404Exception();
        if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
        $this->assertAccountAdministrator();
        $accountId = max(1, (int) $this->wire()->input->post->account);
        $redirectUri = $this->oauthRedirectUri();
        $url = $this->mailbox->beginOAuth($accountId, $redirectUri);
        $this->wire()->session->redirect($url);
        return '';
    }

    public function ___executeOauthCallback() {
        $this->assertAccountAdministrator();
        $providerError = preg_replace('/[^a-z0-9_.-]/i', '', (string) $this->wire()->input->get->error);
        if($providerError !== '') {
            $this->error($this->_('OAuth authorization was not completed:') . ' ' . $providerError);
        } else {
            try {
                $result = $this->mailbox->completeOAuth(
                    (string) $this->wire()->input->get->state,
                    (string) $this->wire()->input->get->code,
                    $this->oauthRedirectUri()
                );
                $this->activeAccountId = (int) $result['account_id'];
                $this->message(sprintf($this->_('OAuth mailbox account %d connected.'), (int) $result['account_id']));
            } catch(\Throwable $error) {
                $this->error($this->_('OAuth connection failed:') . ' ' . $error->getMessage());
            }
        }
        $this->wire()->session->redirect($this->url());
        return '';
    }

    public function ___executeOauthDisconnect() {
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw new Wire404Exception();
        if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
        $this->assertAccountAdministrator();
        $accountId = max(1, (int) $this->wire()->input->post->account);
        $this->mailbox->disconnectOAuth($accountId);
        $this->activeAccountId = $accountId;
        $this->message(sprintf($this->_('OAuth mailbox account %d disconnected.'), $accountId));
        $this->wire()->session->redirect($this->url());
        return '';
    }

    public function ___executeDependencies(): string {
        $this->configureSectionChrome($this->_('Runtime packages'));
        if(!$this->wire()->user->isSuperuser()) throw new WirePermissionException($this->_('Only a superuser may install Mailbox runtime packages.'));
        $configuredComposer = trim((string) ($this->wire()->config->mailboxComposerPath ?? ''));
        $runtimeRoot = rtrim((string) $this->wire()->config->paths->assets, '/\\') . '/Mailbox/runtime';
        $installer = new MailboxDependencyInstaller(__DIR__, $configuredComposer !== '' ? $configuredComposer : null, $runtimeRoot);
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
            if((string) $this->wire()->input->post->dependency_action !== 'install') throw new WireException($this->_('Unknown runtime-package action.'));
            try {
                $result = $installer->install();
                $this->message(sprintf($this->_('Mailbox runtime packages installed. PHPMailer %1$s and Webklex %2$s are ready.'), (string) ($result['phpmailer_version'] ?? ''), (string) ($result['webklex_version'] ?? '')));
            } catch(\Throwable $error) {
                $this->error($error->getMessage());
            }
            $this->wire()->session->redirect(rtrim((string) $this->wire()->page->url, '/') . '/dependencies/');
            return '';
        }
        return "<div class='ProcessMailbox pw-module-workspace'>" . $this->renderTabs('dependencies') . $this->renderDependencyInstaller($installer->status()) . '</div>';
    }

    protected function renderDependencyInstaller(array $status): string {
        $runtimeReady = !empty($status['runtime_ready']);
        $canInstall = !empty($status['can_install']);
        $version = (string) ($status['phpmailer_version'] ?? '');
        $locked = (string) ($status['locked_version'] ?? '');
        $webklexVersion = (string) ($status['webklex_version'] ?? '');
        $lockedWebklex = (string) ($status['locked_webklex_version'] ?? '');
        $composer = !empty($status['composer_available']) ? $this->_('Available') : $this->_('Not found');
        $writable = !empty($status['runtime_writable']) ? $this->_('Writable') : $this->_('Read-only');
        $stateClass = $runtimeReady ? 'is-ready' : 'is-warning';
        $stateLabel = $runtimeReady ? $this->_('Mail runtime ready') : $this->_('Mail runtime incomplete');
        $action = '';
        if($canInstall) {
            $action = "<form method='post' class='Mailbox-dependencies-action'>" . $this->wire()->session->CSRF->renderInput() . "<input type='hidden' name='dependency_action' value='install'><button class='uk-button uk-button-primary' type='submit'><span uk-icon='download' class='uk-margin-small-right'></span>" . ($runtimeReady ? $this->_('Repair locked runtime') : $this->_('Download and install runtime')) . "</button><small>" . $this->_('Runs the repository composer.lock with no development packages, plugins, or scripts.') . '</small></form>';
        } else {
            $action = "<div class='uk-alert-warning Mailbox-dependencies-warning'><strong>" . $this->_('Automatic installation is unavailable') . "</strong><p>" . $this->_('Make the module directory writable and configure an absolute executable path in $config->mailboxComposerPath, or run composer install --no-dev --prefer-dist in the Mailbox directory.') . '</p></div>';
        }
        return "<section class='Mailbox-dependencies-hero'><div><span class='Mailbox-eyebrow'>" . $this->_('Persistent Composer runtime') . "</span><h2 class='uk-h2 uk-margin-small-top uk-margin-small-bottom'>" . $this->_('Download Mailbox runtime packages') . "</h2><p class='uk-text-lead uk-margin-remove'>" . $this->_('Install the exact dependencies recorded in composer.lock into site/assets/Mailbox/runtime/vendor. The persistent runtime survives ProcessWire module upgrades; PHPMailer provides SMTP and Webklex provides SNI-capable IMAP.') . "</p></div><span class='Mailbox-dependencies-state {$stateClass}'><span uk-icon='" . ($runtimeReady ? 'check' : 'warning') . "'></span>{$stateLabel}</span></section><section class='Mailbox-dependencies-grid'><div><small>PHPMailer</small><strong>" . $this->e($version !== '' ? $version : $this->_('Not installed')) . "</strong><span>" . sprintf($this->_('Locked: %s'), $this->e($locked !== '' ? $locked : $this->_('Unknown'))) . "</span></div><div><small>Webklex PHP-IMAP</small><strong>" . $this->e($webklexVersion !== '' ? $webklexVersion : $this->_('Not installed')) . "</strong><span>" . sprintf($this->_('Locked: %s'), $this->e($lockedWebklex !== '' ? $lockedWebklex : $this->_('Unknown'))) . "</span></div><div><small>Composer</small><strong>{$composer}</strong><span>" . $this->_('Executable is selected only from trusted server configuration or known absolute paths.') . "</span></div><div><small>Persistent vendor/</small><strong>{$writable}</strong><span>site/assets/Mailbox/runtime/vendor</span></div></section><section class='uk-card uk-card-default uk-card-small uk-card-body Mailbox-dependencies-safety'><div><span uk-icon='lock'></span><div><strong>" . $this->_('Bounded installer') . "</strong><p>" . $this->_('Superuser-only, POST and CSRF protected. The command has fixed arguments, a process lock and timeout, suppresses Composer plugins/scripts, and validates both locked mail packages afterward.') . "</p></div></div><div><span uk-icon='shield'></span><div><strong>" . $this->_('Persistent and protected') . "</strong><p>" . $this->_('Runtime files live outside the replaceable module directory. Mailbox writes Apache/IIS deny rules and ProcessWire’s root rules block PHP execution under site/assets; nginx deployments must also deny direct access to this runtime path.') . "</p></div></div></section>{$action}<div class='Mailbox-dependencies-back'><a class='uk-button uk-button-default' href='" . $this->e($this->settingsUrl()) . "'><span uk-icon='settings' class='uk-margin-small-right'></span>" . $this->_('Back to Mailbox settings') . '</a></div>';
    }

    protected function oauthRedirectUri(): string {
        return rtrim((string) $this->wire()->page->httpUrl, '/') . '/oauth-callback/';
    }

    protected function selectedFolder(array $folders): string {
        $isSearch = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && (int) $this->wire()->input->post->search;
        $token = (string) ($isSearch ? $this->wire()->input->post->folder : $this->wire()->input->get->folder);
        $requested = $token !== '' ? $this->decodeFolderToken($token) : $this->mailbox->getDefaultFolder();
        $fallback = '';

        foreach($folders as $folder) {
            if(!$folder['selectable']) continue;
            if($fallback === '') $fallback = $folder['name'];
            if($folder['name'] === $requested) return $requested;
        }

        if($token !== '') throw new WireException($this->_('The selected folder is unavailable.'));
        if($fallback !== '') return $fallback;
        throw new WireException($this->_('The IMAP account has no selectable folders.'));
    }

    protected function renderLayout(array $folders, string $selected, string $content, string $cacheStatus = ''): string {
        $diagnostics = $folders ? $this->renderDiagnosticsPanel() : '';
        return "<div class='ProcessMailbox pw-module-workspace'>
            " . $this->renderTabs('mailbox') . "
            <div class='Mailbox-layout'>
                " . $this->renderFolderSidebar($folders, $selected, $cacheStatus) . "
                <main class='uk-width-expand@m'>{$content}</main>
            </div>
            {$diagnostics}
        </div>";
    }

    protected function renderTabs(string $active = 'mailbox'): string {
        $settings = $this->settingsUrl();
        $discover = rtrim((string) $this->wire()->page->url, '/') . '/discover/';
        $accounts = rtrim((string) $this->wire()->page->url, '/') . '/accounts/';
        $dependencies = rtrim((string) $this->wire()->page->url, '/') . '/dependencies/';
        $settingsLabel = $this->_('Settings');
        return "<div class='Mailbox-admin-nav uk-margin-medium-bottom'><ul class='uk-subnav uk-subnav-pill Mailbox-section-nav' aria-label='" . $this->e($this->_('Mailbox sections')) . "'>
            <li" . ($active === 'mailbox' ? " class='uk-active'" : '') . "><a href='" . $this->e($this->url()) . "'>" . $this->_('Mailbox') . "</a></li>
            <li" . ($active === 'discover' ? " class='uk-active'" : '') . "><a href='" . $this->e($discover) . "'>" . $this->_('Discover settings') . "</a></li>
            " . ($this->wire()->user->isSuperuser() ? "<li" . ($active === 'accounts' ? " class='uk-active'" : '') . "><a href='" . $this->e($accounts) . "'>" . $this->_('Accounts') . "</a></li>" : '') . "
            " . ($this->wire()->user->isSuperuser() ? "<li" . ($active === 'dependencies' ? " class='uk-active'" : '') . "><a href='" . $this->e($dependencies) . "'>" . $this->_('Runtime') . "</a></li>" : '') . "
        </ul><a class='Mailbox-settings-link' href='" . $this->e($settings) . "' title='" . $this->e($settingsLabel) . "' aria-label='" . $this->e($settingsLabel) . "'>" . $this->renderSettingsIcon() . "</a></div>";
    }

    protected function renderSettingsIcon(): string {
        return '<svg aria-hidden="true" fill="none" stroke-width="1.5" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.398.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.272-.806.108-1.204-.165-.397-.506-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" stroke-linecap="round" stroke-linejoin="round"></path>'
            . '<path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" stroke-linecap="round" stroke-linejoin="round"></path>'
            . '</svg>';
    }

    protected function settingsUrl(string $section = ''): string {
        $url = $this->wire()->config->urls->admin . 'module/edit?name=Mailbox&collapse_info=1';
        if($section !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $section)) $url .= '#Inputfield_' . $section;
        return $url;
    }

    protected function configureSectionChrome(string $title): void {
        $title = $this->safeChromeText($title, $this->_('Mailbox'));
        $this->headline($title);
        $this->browserTitle($title . ' · ' . $this->_('Mailbox'));
        $this->breadcrumb($this->url(), $this->_('Mailbox'));
    }

    protected function configureWorkspaceChrome(string $folder, ?array $message = null, int $page = 1, bool $search = false, bool $loadingMessage = false): void {
        $mailboxLabel = $this->_('Mailbox');
        $folderLabel = $this->safeChromeText($this->mailboxFolderLabel($folder), $this->_('Folder'));
        $accountLabel = $this->safeChromeText($this->activeAccountLabel(), $mailboxLabel);
        $this->breadcrumb($this->url(), $mailboxLabel);

        if($message !== null || $loadingMessage || $search) {
            $this->breadcrumb($this->url(['folder' => $this->encodeFolderToken($folder)]), $folderLabel);
        }
        if($message !== null) {
            $subject = $this->safeChromeText((string) ($message['subject'] ?? ''), $this->_('(no subject)'));
            $this->headline($subject);
            $this->browserTitle($subject . ' · ' . $folderLabel . ' · ' . $accountLabel);
            return;
        }
        if($search) {
            $title = $this->_('Search results');
            $this->headline($title);
            $this->browserTitle($title . ' · ' . $folderLabel . ' · ' . $accountLabel);
            return;
        }
        if($loadingMessage) {
            $title = $this->_('Loading message');
            $this->headline($title);
            $this->browserTitle($title . ' · ' . $folderLabel . ' · ' . $accountLabel);
            return;
        }
        $pageLabel = $page > 1 ? ' · ' . sprintf($this->_('Page %d'), $page) : '';
        $this->headline($folderLabel);
        $this->browserTitle($folderLabel . $pageLabel . ' · ' . $accountLabel . ' · ' . $mailboxLabel);
    }

    protected function activeAccountLabel(): string {
        foreach($this->mailbox->getAccounts() as $account) {
            if((int) ($account['id'] ?? 0) === $this->activeAccountId) return (string) ($account['label'] ?? '');
        }
        return $this->_('Mailbox');
    }

    protected function safeChromeText(string $value, string $fallback): string {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if($value === '') $value = $fallback;
        if(function_exists('mb_strimwidth')) return mb_strimwidth($value, 0, 110, '…', 'UTF-8');
        return strlen($value) > 110 ? substr($value, 0, 107) . '...' : $value;
    }

    protected function renderFolders(array $folders, string $selected): string {
        $items = '';
        foreach($folders as $folder) {
            $name = $folder['name'];
            $label = $folder['label'];
            $delimiter = $folder['delimiter'];
            $depth = $delimiter !== '' ? max(0, substr_count($name, $delimiter)) : 0;
            $style = " style='--mc-folder-depth:" . min(6, $depth) . "'";
            $icon = strcasecmp($name, 'INBOX') === 0 ? 'mail' : 'folder';
            $iconMarkup = "<span uk-icon='icon:{$icon};ratio:.8' class='Mailbox-folder-icon uk-margin-small-right'></span>";
            $labelMarkup = "<span class='Mailbox-folder-label'>" . $this->e($label) . '</span>';

            if(!$folder['selectable']) {
                $items .= "<li class='uk-disabled mc-folder'{$style}><a href='#' aria-disabled='true' title='" . $this->e($label) . "'>{$iconMarkup}{$labelMarkup}</a></li>";
                continue;
            }

            $class = $name === $selected ? 'uk-active mc-folder' : 'mc-folder';
            $url = $this->url(['folder' => $this->encodeFolderToken($name)]);
            $items .= "<li class='{$class}'{$style}><a href='" . $this->e($url) . "' title='" . $this->e($label) . "'>{$iconMarkup}{$labelMarkup}</a></li>";
        }

        return "<nav class='mc-folders' aria-label='" . $this->e($this->_('Mail folders')) . "'>
            <ul class='uk-nav uk-nav-default'>{$items}</ul>
        </nav>";
    }

    protected function renderFolderSidebar(array $folders, string $selected, string $cacheStatus = ''): string {
        $accountsLabel = $this->e($this->_('Accounts'));
        $foldersLabel = $this->e($this->_('Folders'));
        $searchLabel = $this->e($this->_('Search'));
        $toggle = $this->e($this->_('Toggle sidebar'));
        $search = $folders ? $this->renderSearchForm($selected) : '';
        $searchControl = $search !== '' ? ' MailboxSearchPanel' : '';
        $accountsIsland = "<section class='uk-card uk-card-default uk-card-small Mailbox-sidebar Mailbox-sidebar-accounts'><header class='Mailbox-sidebar-head'><span class='Mailbox-sidebar-title'>{$accountsLabel}</span><button class='Mailbox-sidebar-toggle' type='button' data-mailbox-sidebar-toggle title='{$toggle}' aria-label='{$toggle}' aria-controls='MailboxSidebarTools MailboxFolderNavigation{$searchControl}' aria-expanded='true'><span uk-icon='icon:menu;ratio:.85'></span></button></header><div class='Mailbox-sidebar-tools' id='MailboxSidebarTools'>" . $this->renderAccountSwitcher() . $cacheStatus . "</div></section>";
        $foldersIsland = "<section class='uk-card uk-card-default uk-card-small Mailbox-sidebar Mailbox-sidebar-folders'><header class='Mailbox-sidebar-head'><span class='Mailbox-sidebar-title'>{$foldersLabel}</span></header><div id='MailboxFolderNavigation'>" . $this->renderFolders($folders, $selected) . "</div></section>";
        $searchIsland = $search !== '' ? "<section class='uk-card uk-card-default uk-card-small Mailbox-sidebar Mailbox-sidebar-search' id='MailboxSearchPanel'><header class='Mailbox-sidebar-head'><span class='Mailbox-sidebar-title'>{$searchLabel}</span></header>{$search}</section>" : '';
        return "<aside class='Mailbox-sidebar-shell' id='MailboxSidebar' aria-label='" . $this->e($this->_('Mailbox controls and folders')) . "'><script>(function(sidebar){try{if(localStorage.getItem('processmailbox_sidebar_collapsed')==='1')sidebar.classList.add('is-collapsed');}catch(error){}})(document.currentScript.parentElement);</script><div class='Mailbox-sidebar-stack'>{$accountsIsland}{$foldersIsland}{$searchIsland}</div></aside>";
    }

    protected function renderMessageList(string $folder, array $result, array $folders = []): string {
        $folderLabel = $this->mailboxFolderLabel($folder);
        $bulkEnabled = (bool) $this->mailbox->enableMailMutations && $this->wire()->user->hasPermission(Mailbox::writePermission);
        $rows = '';
        foreach($result['messages'] as $message) {
            $url = $this->url([
                'folder' => $this->encodeFolderToken($folder),
                'p' => max(1, (int) ($result['page'] ?? 1)),
                'uid' => $message['uid'],
            ]);
            $date = $message['date'] ? date('Y-m-d H:i', $message['date']) : '—';
            $subject = $message['subject'] !== '' ? $message['subject'] : $this->_('(no subject)');
            $flag = $message['flagged'] ? "<span uk-icon='star' class='uk-margin-small-right' aria-label='" . $this->e($this->_('Flagged')) . "'></span>" : '';

            $rowClass = $message['seen'] ? 'mc-message-row' : 'mc-unread mc-message-row';
            $rowLabel = sprintf($this->_('Open message: %s'), $subject);
            $rows .= "<tr class='{$rowClass}' data-mailbox-href='" . $this->e($url) . "' tabindex='0' aria-label='" . $this->e($rowLabel) . "'>
                " . ($bulkEnabled ? "<td class='mc-select'><input class='uk-checkbox' type='checkbox' name='uids[]' value='" . (int) $message['uid'] . "' data-mailbox-select aria-label='" . $this->e(sprintf($this->_('Select message: %s'), $subject)) . "'></td>" : '') . "
                <td class='mc-from'>" . $this->e($message['from']) . "</td>
                <td class='mc-subject'><a href='" . $this->e($url) . "'>{$flag}" . $this->e($subject) . "</a></td>
                <td class='mc-size uk-text-nowrap uk-text-muted'>" . $this->e($this->formatBytes($message['size'])) . "</td>
                <td class='mc-date uk-text-nowrap'>" . $this->e($date) . "</td>
            </tr>";
        }

        if($rows === '') {
            $rows = "<tr><td colspan='" . ($bulkEnabled ? 5 : 4) . "'><div class='uk-placeholder uk-text-center uk-margin-remove'>
                <span uk-icon='icon:mail;ratio:1.5'></span>
                <h3 class='uk-h4 uk-margin-small-top'>" . $this->_('This folder is empty.') . "</h3>
                <p class='uk-text-muted uk-margin-remove'>" . $this->_('New messages will appear here.') . "</p>
            </div></td></tr>";
        }

        $bulkNotice = '';
        $tableHead = '';
        $formOpen = '';
        $formClose = '';
        if($bulkEnabled) {
            $destinations = '';
            foreach($folders as $candidate) {
                if(empty($candidate['selectable']) || (string) $candidate['name'] === $folder) continue;
                $destinations .= "<option value='" . $this->e((string) $candidate['name']) . "'>" . $this->e((string) $candidate['label']) . '</option>';
            }
            $moveOption = $destinations !== '' ? "<option value='move'>" . $this->_('Move to folder') . '</option>' : '';
            $actionUrl = rtrim((string) $this->wire()->page->url, '/') . '/bulk-action/';
            $formOpen = "<form method='post' action='" . $this->e($actionUrl) . "' data-mailbox-bulk-form data-mailbox-bulk-delete-confirm='" . $this->e($this->_('Mark the selected messages for deletion? They will not be permanently expunged.')) . "'>" . $this->wire()->session->CSRF->renderInput() . "<input type='hidden' name='account' value='" . (int) $this->activeAccountId . "'><input type='hidden' name='folder' value='" . $this->e($this->encodeFolderToken($folder)) . "'>";
            $tableHead = "<th class='mc-select'><input class='uk-checkbox' type='checkbox' data-mailbox-select-all aria-label='" . $this->e($this->_('Select all messages on this page')) . "'></th>";
            $formClose = "<div class='Mailbox-bulk-bar' data-mailbox-bulk-bar hidden><div class='Mailbox-bulk-count'><strong data-mailbox-bulk-count>0</strong><span>" . $this->_('selected') . "</span></div><label class='uk-text-meta' for='MailboxBulkAction'>" . $this->_('Action') . "</label><select class='uk-select uk-form-small' id='MailboxBulkAction' name='bulk_action' data-mailbox-bulk-action required><option value=''>" . $this->_('Choose action…') . "</option><option value='read'>" . $this->_('Mark as read') . "</option><option value='unread'>" . $this->_('Mark as unread') . "</option><option value='flag'>" . $this->_('Add flag') . "</option><option value='unflag'>" . $this->_('Remove flag') . "</option>{$moveOption}<option value='delete'>" . $this->_('Mark for deletion') . "</option></select><select class='uk-select uk-form-small' name='destination' data-mailbox-bulk-destination aria-label='" . $this->e($this->_('Destination folder')) . "' hidden disabled><option value=''>" . $this->_('Choose folder…') . "</option>{$destinations}</select><button class='uk-button uk-button-primary uk-button-small' type='submit'><span uk-icon='check'></span>" . $this->_('Apply') . "</button><button class='uk-button uk-button-default uk-button-small' type='button' data-mailbox-bulk-clear>" . $this->_('Clear') . "</button><small class='Mailbox-bulk-safety'><span uk-icon='lock'></span>" . $this->_('Delete never permanently expunges messages.') . "</small></div></form>";
        } elseif($this->wire()->user->hasPermission(Mailbox::writePermission)) {
            $bulkNotice = "<aside class='Mailbox-bulk-disabled'><span uk-icon='lock'></span><span><strong>" . $this->_('Bulk actions are disabled') . "</strong><small>" . $this->_('Enable message management to select, flag, move, or delete multiple messages.') . "</small></span>" . ($this->wire()->user->isSuperuser() ? "<a class='uk-button uk-button-default uk-button-small' href='" . $this->e($this->settingsUrl('mailboxActions')) . "'><span uk-icon='settings'></span>" . $this->_('Configure') . "</a>" : '') . "</aside>";
        }
        $pagination = $this->renderPagination($folder, (int) $result['page'], (int) $result['pages'], (int) $result['total'], (int) $result['limit']);
        $currentPage = max(1, (int) $result['page']);
        $totalPages = max(1, (int) $result['pages']);
        $totalMessages = max(0, (int) $result['total']);
        $firstMessage = $totalMessages > 0 ? (($currentPage - 1) * max(1, (int) $result['limit'])) + 1 : 0;
        $lastMessage = min($totalMessages, $currentPage * max(1, (int) $result['limit']));
        $range = sprintf($this->_('%1$d–%2$d of %3$d'), $firstMessage, $lastMessage, $totalMessages);
        $pageContext = sprintf($this->_('Page %1$d of %2$d'), $currentPage, $totalPages);
        $listContext = "<div class='Mailbox-list-context' aria-label='" . $this->e($range . '. ' . $pageContext) . "'><span class='Mailbox-list-context-icon' uk-icon='icon:clock;ratio:.82'></span><div><strong>" . $this->e($range) . "</strong><small>" . $this->_('Newest first') . " · " . $this->e($pageContext) . "</small></div></div>";
        return "<section class='uk-card uk-card-default uk-card-small Mailbox-table-panel'>
            <header class='mc-module-head uk-card-header'><div><h2 class='uk-card-title uk-margin-remove'>" . $this->e($folderLabel) . "</h2><p class='uk-text-muted uk-margin-remove'>" . sprintf($this->_('%d messages'), $result['total']) . "</p></div>{$listContext}</header>
            {$bulkNotice}{$formOpen}
            <div class='uk-overflow-auto'><table class='uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small mc-table'>
                <thead><tr>{$tableHead}<th>" . $this->_('From') . "</th><th>" . $this->_('Subject') . "</th><th>" . $this->_('Size') . "</th><th>" . $this->_('Date') . "</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table></div>{$formClose}
            <footer class='uk-card-footer'>{$pagination}</footer>
        </section>";
    }

    protected function renderSearchResults(array $result): string {
        $rows = '';
        foreach($result['messages'] as $message) {
            $folder = (string) $message['folder'];
            $url = $this->url(['folder' => $this->encodeFolderToken($folder), 'uid' => $message['uid']]);
            $date = $message['date'] ? date('Y-m-d H:i', $message['date']) : '—';
            $subject = (string) $message['subject'];
            $rowLabel = sprintf($this->_('Open message: %s'), $subject !== '' ? $subject : $this->_('(no subject)'));
            $rows .= "<tr class='mc-message-row' data-mailbox-href='" . $this->e($url) . "' tabindex='0' aria-label='" . $this->e($rowLabel) . "'><td>" . $this->e($this->mailboxFolderLabel($folder)) . "</td><td>" . $this->e($message['from']) . "</td><td><a href='" . $this->e($url) . "'>" . $this->e($subject) . "</a></td><td class='uk-text-nowrap'>" . $this->e($date) . "</td></tr>";
        }
        if($rows === '') $rows = "<tr><td colspan='4'><div class='uk-placeholder uk-text-center uk-margin-remove'>" . $this->_('No messages matched the search.') . "</div></td></tr>";
        $warning = !empty($result['truncated']) ? "<div class='uk-alert uk-alert-warning uk-margin-remove'>" . $this->_('Search results reached the configured collection limit. Refine the filters for complete results.') . "</div>" : '';
        $pagination = $this->renderSearchPagination((int) $result['page'], (int) $result['pages'], (int) $result['total'], (int) $result['limit']);
        return "<section class='uk-card uk-card-default uk-card-small Mailbox-table-panel'><header class='uk-card-header'><h2 class='uk-card-title uk-margin-remove'>" . $this->_('Search results') . "</h2><p class='uk-text-muted uk-margin-remove-top'>" . sprintf($this->_('%d collected matches'), (int) $result['total']) . "</p></header>{$warning}<div class='uk-overflow-auto'><table class='uk-table uk-table-divider uk-table-hover uk-table-small mc-table'><thead><tr><th>" . $this->_('Folder') . "</th><th>" . $this->_('From') . "</th><th>" . $this->_('Subject') . "</th><th>" . $this->_('Date') . "</th></tr></thead><tbody>{$rows}</tbody></table></div><footer class='uk-card-footer'>{$pagination}</footer></section>";
    }

    protected function renderMessage(string $folder, int $uid, ?array $message = null, array $folders = [], int $page = 1): string {
        if($message === null) $message = $this->mailbox->getMessage($folder, $uid);
        $back = $this->url(['folder' => $this->encodeFolderToken($folder), 'p' => max(1, $page)]);
        $next = $this->nextMessageUrl($folder, $uid, $page);
        $date = $message['date'] ? date('Y-m-d H:i:s T', $message['date']) : '—';
        $attachments = '';

        if($message['attachments']) {
            $items = '';
            foreach($message['attachments'] as $attachment) {
                $label = "<span class='Mailbox-attachment-name'>" . $this->e($attachment['name']) . "</span><small>" . $this->e($attachment['type']) . ' · ' . $this->e($this->formatBytes($attachment['bytes'])) . '</small>';
                if($this->wire()->user->hasPermission(Mailbox::attachmentPermission) && !empty($attachment['part'])) {
                    $download = rtrim((string) $this->wire()->page->url, '/') . '/attachment/?' . http_build_query(['account' => $this->activeAccountId, 'folder' => $this->encodeFolderToken($folder), 'uid' => $uid, 'part' => $attachment['part']], '', '&', PHP_QUERY_RFC3986);
                    $label = "<a href='" . $this->e($download) . "'><span uk-icon='download'></span><span>{$label}</span></a>";
                } else {
                    $label = "<span><span uk-icon='file-text'></span><span>{$label}</span></span>";
                }
                $items .= "<li>{$label}</li>";
            }
            $attachments = "<section class='Mailbox-message-attachments'><header><span uk-icon='paperclip'></span><div><strong>" . $this->_('Attachments') . "</strong><small>" . sprintf($this->_('%d files'), count($message['attachments'])) . "</small></div></header><ul>{$items}</ul></section>";
        }

        $cc = $message['cc'] !== '' ? "<div><span>" . $this->_('Cc') . "</span><strong>" . $this->e($message['cc']) . "</strong></div>" : '';
        $folderLabel = $this->mailboxFolderLabel($folder);
        $accountLabel = $this->activeAccountLabel();
        $html = (string) ($message['html'] ?? '');
        $raw = $message['raw'] ?? null;
        $hasHtml = trim($html) !== '';
        $defaultView = $hasHtml ? 'html' : 'text';
        $remoteImages = (bool) $this->mailbox->allowRemoteMessageImages;
        $externalLinks = (bool) $this->mailbox->allowExternalMessageLinks;
        $frameSandbox = $externalLinks ? 'allow-popups allow-popups-to-escape-sandbox' : '';
        $htmlPanel = $hasHtml
            ? "<iframe class='Mailbox-message-frame' title='" . $this->e($this->_('Sanitized HTML message')) . "' sandbox='" . $this->e($frameSandbox) . "' credentialless referrerpolicy='no-referrer' srcdoc='" . $this->e(MailboxMessageView::sanitizedDocument($html, $remoteImages, $externalLinks)) . "'></iframe>"
            : "<div class='uk-placeholder uk-text-center'>" . $this->_('This message has no HTML body.') . "</div>";
        $rawPanel = is_string($raw)
            ? "<pre class='mc-body Mailbox-message-raw'>" . $this->e($raw) . '</pre>'
            : "<div class='uk-placeholder uk-text-center'>" . $this->_('Raw MIME source is unavailable for this IMAP transport.') . '</div>';
        $externalContentEnabled = $remoteImages || $externalLinks;
        $safetyIcon = $externalContentEnabled ? 'warning' : 'lock';
        $safetyClass = $externalContentEnabled ? ' is-warning' : '';
        if($remoteImages && $externalLinks) $safetyText = $this->_('Remote images and HTTPS links enabled — activity may be disclosed. Admin address remains hidden.');
        elseif($remoteImages) $safetyText = $this->_('Remote images enabled — senders may detect opens. Scripts, forms, and links remain blocked.');
        elseif($externalLinks) $safetyText = $this->_('HTTPS links enabled — destinations may detect clicks. Admin address remains hidden; images, scripts, and forms remain blocked.');
        else $safetyText = $this->_('Scripts, tracking images, forms, and links blocked');
        $safetyAction = $this->wire()->user->isSuperuser() ? "<a href='" . $this->e($this->settingsUrl('mailboxDisplay')) . "'>" . $this->_('Display settings') . '</a>' : '';
        $views = "<div class='Mailbox-message-panels'>
            <section data-mailbox-view-panel='html'" . ($defaultView !== 'html' ? ' hidden' : '') . ">{$htmlPanel}</section>
            <section data-mailbox-view-panel='text'" . ($defaultView !== 'text' ? ' hidden' : '') . "><pre class='mc-body'>" . $this->e($message['body']) . "</pre></section>
            <section data-mailbox-view-panel='raw' hidden>{$rawPanel}</section>
        </div><footer class='Mailbox-message-reader-head'><nav class='Mailbox-message-views' aria-label='" . $this->e($this->_('Message representation')) . "' data-mailbox-message-views data-default-view='{$defaultView}'>
            <button type='button' class='uk-button uk-button-small Mailbox-message-tool " . ($defaultView === 'html' ? 'uk-button-primary' : 'uk-button-default') . "' data-mailbox-view='html' aria-pressed='" . ($defaultView === 'html' ? 'true' : 'false') . "'><span uk-icon='code'></span> HTML</button>
            <button type='button' class='uk-button uk-button-small Mailbox-message-tool " . ($defaultView === 'text' ? 'uk-button-primary' : 'uk-button-default') . "' data-mailbox-view='text' aria-pressed='" . ($defaultView === 'text' ? 'true' : 'false') . "'><span uk-icon='file-text'></span> " . $this->_('Text') . "</button>
            <button type='button' class='uk-button uk-button-default uk-button-small Mailbox-message-tool' data-mailbox-view='raw' aria-pressed='false'><span uk-icon='database'></span> Raw</button>
        </nav><span class='Mailbox-message-safety{$safetyClass}'><span uk-icon='{$safetyIcon}'></span><span>{$safetyText} {$safetyAction}</span></span></footer>";
        $actions = $this->renderMessageActions($folder, $uid, $folders);
        $aiPanel = $this->renderMessageAiPanel($folder, $uid);
        $capabilityNotice = $this->renderMessageCapabilityNotice();
        $seenReceipt = empty($message['seen']) ? $this->renderSeenReceipt($folder, $uid) : '';
        return "<article class='uk-card uk-card-default uk-card-small mc-message Mailbox-table-panel'>
            {$seenReceipt}
            <header class='uk-card-header Mailbox-message-toolbar'><div class='Mailbox-message-navigation'><a class='uk-button uk-button-default uk-button-small Mailbox-message-tool' href='" . $this->e($back) . "'><span uk-icon='arrow-left'></span>" . sprintf($this->_('Back to %s'), $this->e($folderLabel)) . "</a>" . ($next !== '' ? "<a class='uk-button uk-button-default uk-button-small Mailbox-message-tool' href='" . $this->e($next) . "'><span uk-icon='arrow-right'></span>" . $this->_('Next message') . "</a>" : "<span class='uk-button uk-button-default uk-button-small Mailbox-message-tool is-disabled' aria-disabled='true'><span uk-icon='arrow-right'></span>" . $this->_('Next message') . "</span>") . "<span class='uk-label'>" . $this->e($accountLabel) . "</span><span class='uk-label uk-label-success'>" . $this->e($folderLabel) . "</span></div>{$actions}</header>
            <section class='Mailbox-message-summary'><div class='Mailbox-message-sender-icon'><span uk-icon='user'></span></div><div class='Mailbox-message-sender'><span>" . $this->_('From') . "</span><strong>" . $this->e($message['from']) . "</strong><small>" . $this->e($date) . "</small></div><div class='Mailbox-message-recipients'><div><span>" . $this->_('To') . "</span><strong>" . $this->e($message['to']) . "</strong></div>{$cc}</div></section>
            {$aiPanel}
            {$attachments}
            {$capabilityNotice}
            <div class='uk-card-body Mailbox-message-reader'>{$views}</div>
        </article>";
    }

    protected function nextMessageUrl(string $folder, int $uid, int $page): string {
        $page = max(1, $page);
        $limit = max(1, min(100, (int) $this->mailbox->messagesPerPage));
        $cached = $this->mailbox->getCachedMessages($folder, $page, $limit, self::VIEW_CACHE_MAX_AGE);
        $result = (array) ($cached['value']['result'] ?? []);
        $messages = (array) ($result['messages'] ?? []);
        foreach($messages as $index => $candidate) {
            if((int) ($candidate['uid'] ?? 0) !== $uid) continue;
            if(isset($messages[$index + 1]['uid'])) return $this->url(['folder' => $this->encodeFolderToken($folder), 'p' => $page, 'uid' => (int) $messages[$index + 1]['uid']]);
            if($page >= (int) ($result['pages'] ?? $page)) return '';
            $nextPage = $this->mailbox->getCachedMessages($folder, $page + 1, $limit, self::VIEW_CACHE_MAX_AGE);
            $nextMessages = (array) ($nextPage['value']['result']['messages'] ?? []);
            return !empty($nextMessages[0]['uid']) ? $this->url(['folder' => $this->encodeFolderToken($folder), 'p' => $page + 1, 'uid' => (int) $nextMessages[0]['uid']]) : '';
        }
        return '';
    }

    protected function renderSeenReceipt(string $folder, int $uid): string {
        $action = rtrim((string) $this->wire()->page->url, '/') . '/seen/';
        return "<form method='post' action='" . $this->e($action) . "' data-mailbox-seen hidden>" . $this->wire()->session->CSRF->renderInput() . "<input type='hidden' name='account' value='" . (int) $this->activeAccountId . "'><input type='hidden' name='folder' value='" . $this->e($this->encodeFolderToken($folder)) . "'><input type='hidden' name='uid' value='{$uid}'></form>";
    }

    protected function renderMessageActions(string $folder, int $uid, array $folders): string {
        $canWrite = $this->wire()->user->hasPermission(Mailbox::writePermission);
        $canSend = $this->wire()->user->hasPermission(Mailbox::sendPermission);
        if(!$canWrite && !$canSend) return '';
        $write = (bool) $this->mailbox->enableMailMutations && $canWrite;
        $send = (bool) $this->mailbox->enableMailSending && $canSend;
        $actionUrl = rtrim((string) $this->wire()->page->url, '/') . '/message-action/';
        $ajaxReplyUrl = rtrim((string) $this->wire()->page->url, '/') . '/ajax-reply/';
        $hidden = "<input type='hidden' name='account' value='" . (int) $this->activeAccountId . "'><input type='hidden' name='folder' value='" . $this->e($this->encodeFolderToken($folder)) . "'><input type='hidden' name='uid' value='{$uid}'>";
        $csrf = $this->wire()->session->CSRF->renderInput();
        $controls = '';
        if($write) {
            $controls .= "<form method='post' action='" . $this->e($actionUrl) . "'>{$csrf}{$hidden}<button class='uk-button uk-button-default uk-button-small Mailbox-message-tool' name='message_action' value='read' type='submit'><span uk-icon='check'></span> " . $this->_('Read') . "</button><button class='uk-button uk-button-default uk-button-small Mailbox-message-tool' name='message_action' value='unread' type='submit'><span uk-icon='mail'></span>" . $this->_('Unread') . "</button></form>";
            $options = "<option value=''>" . $this->_('Move to…') . '</option>';
            foreach($folders as $candidate) if(!empty($candidate['selectable']) && (string) $candidate['name'] !== $folder) $options .= "<option value='" . $this->e($candidate['name']) . "'>" . $this->e($candidate['label']) . '</option>';
            $controls .= "<form method='post' action='" . $this->e($actionUrl) . "' class='Mailbox-message-move'>{$csrf}{$hidden}<select class='uk-select uk-form-small' name='destination' required>{$options}</select><button class='uk-button uk-button-default uk-button-small Mailbox-message-tool' name='message_action' value='move' type='submit'><span uk-icon='forward'></span>" . $this->_('Move') . "</button></form>";
            $controls .= "<form method='post' action='" . $this->e($actionUrl) . "' data-mailbox-confirm='" . $this->e($this->_('Mark this message for deletion? It will not be permanently expunged.')) . "'>{$csrf}{$hidden}<button class='uk-button uk-button-danger uk-button-small Mailbox-message-tool' name='message_action' value='delete' type='submit' title='" . $this->e($this->_('Delete')) . "' aria-label='" . $this->e($this->_('Delete')) . "'><span uk-icon='trash'></span></button></form>";
        }
        if($send) {
            $controls .= "<details class='Mailbox-message-compose' data-mailbox-compose><summary class='uk-button uk-button-primary uk-button-small Mailbox-message-tool' aria-haspopup='dialog'><span uk-icon='reply'></span>" . $this->_('Reply') . "</summary><form method='post' action='" . $this->e($ajaxReplyUrl) . "' data-mailbox-reply-form role='dialog' aria-label='" . $this->e($this->_('Reply')) . "'>{$csrf}{$hidden}" . $this->renderAiReplyControls($folder, $uid) . "<textarea class='uk-textarea' name='body' rows='8' maxlength='1048576' wrap='soft' required data-mailbox-reply-body placeholder='" . $this->e($this->_('Write or generate a plain-text reply…')) . "'></textarea><label><input class='uk-checkbox' type='checkbox' name='reply_all' value='1'> " . $this->_('Reply all') . "</label><div class='Mailbox-reply-submit'><button class='uk-button uk-button-primary uk-button-small' name='message_action' value='reply' type='submit'>" . $this->_('Send reply') . "</button><span data-mailbox-reply-status role='status' aria-live='polite'></span></div></form></details>";
            $controls .= "<details class='Mailbox-message-compose' data-mailbox-compose><summary class='uk-button uk-button-default uk-button-small Mailbox-message-tool' aria-haspopup='dialog'><span uk-icon='forward'></span>" . $this->_('Forward') . "</summary><form method='post' action='" . $this->e($actionUrl) . "' role='dialog' aria-label='" . $this->e($this->_('Forward')) . "'>{$csrf}{$hidden}<input class='uk-input' type='email' name='to' required placeholder='recipient@example.com'><textarea class='uk-textarea' name='body' rows='4' maxlength='1048576' wrap='soft' placeholder='" . $this->e($this->_('Optional note…')) . "'></textarea><button class='uk-button uk-button-primary uk-button-small' name='message_action' value='forward' type='submit'>" . $this->_('Send forward') . "</button></form></details>";
        }
        return $controls !== '' ? "<div class='Mailbox-message-actions'>{$controls}</div>" : '';
    }

    protected function renderMessageCapabilityNotice(): string {
        $canWrite = $this->wire()->user->hasPermission(Mailbox::writePermission);
        $canSend = $this->wire()->user->hasPermission(Mailbox::sendPermission);
        $missing = [];
        if($canWrite && !(bool) $this->mailbox->enableMailMutations) $missing[] = $this->_('read/unread, move, and delete');
        if($canSend && !(bool) $this->mailbox->enableMailSending) $missing[] = $this->_('reply and forward');
        if(!$missing) return '';
        $action = $this->wire()->user->isSuperuser()
            ? "<a class='uk-button uk-button-primary uk-button-small' href='" . $this->e($this->settingsUrl('mailboxActions')) . "'><span uk-icon='settings'></span>" . $this->_('Configure message actions') . '</a>'
            : "<span class='uk-text-meta'>" . $this->_('Ask a superuser to enable these capabilities.') . '</span>';
        return "<aside class='Mailbox-message-capability'><span class='Mailbox-message-capability-icon' uk-icon='lock'></span><div><strong>" . $this->_('Some message actions are disabled') . "</strong><p>" . sprintf($this->_('Enable %s in the Message actions section. These controls are independent of the Agent API.'), $this->e(implode('; ', $missing))) . "</p></div>{$action}</aside>";
    }

    protected function renderPagination(string $folder, int $page, int $pages, int $total, int $limit): string {
        if($pages <= 1) return '';
        $page = max(1, min($pages, $page));
        $list = '';
        if($page > 1) {
            $list .= "<li class='Mailbox-pagination-edge'><a href='" . $this->e($this->url(['folder' => $this->encodeFolderToken($folder), 'p' => $page - 1])) . "' aria-label='" . $this->e($this->_('Newer messages')) . "'><span uk-pagination-previous></span><span class='Mailbox-pagination-label'>" . $this->_('Newer') . "</span></a></li>";
        } else {
            $list .= "<li class='uk-disabled Mailbox-pagination-edge'><span aria-disabled='true'><span uk-pagination-previous></span><span class='Mailbox-pagination-label'>" . $this->_('Newer') . "</span></span></li>";
        }
        foreach($this->paginationSequence($page, $pages) as $number) {
            if($number === null) {
                $list .= "<li class='uk-disabled Mailbox-pagination-ellipsis'><span aria-hidden='true'>…</span></li>";
                continue;
            }
            if($number === $page) {
                $list .= "<li class='uk-active'><span aria-current='page' aria-label='" . sprintf($this->e($this->_('Page %d, current page')), $number) . "'>{$number}</span></li>";
                continue;
            }
            $list .= "<li><a href='" . $this->e($this->url(['folder' => $this->encodeFolderToken($folder), 'p' => $number])) . "' aria-label='" . sprintf($this->e($this->_('Page %d')), $number) . "'>{$number}</a></li>";
        }
        if($page < $pages) {
            $list .= "<li class='Mailbox-pagination-edge'><a href='" . $this->e($this->url(['folder' => $this->encodeFolderToken($folder), 'p' => $page + 1])) . "' aria-label='" . $this->e($this->_('Older messages')) . "'><span class='Mailbox-pagination-label'>" . $this->_('Older') . "</span><span uk-pagination-next></span></a></li>";
        } else {
            $list .= "<li class='uk-disabled Mailbox-pagination-edge'><span aria-disabled='true'><span class='Mailbox-pagination-label'>" . $this->_('Older') . "</span><span uk-pagination-next></span></span></li>";
        }
        return $this->renderPaginationShell($list, $page, $total, $limit, $this->_('Message pages'), $this->_('messages'));
    }

    protected function paginationSequence(int $page, int $pages): array {
        if($pages < 1) return [];
        $numbers = [1];
        $start = max(2, $page - 2);
        $end = min($pages - 1, $page + 2);
        if($start > 2) $numbers[] = null;
        for($number = $start; $number <= $end; $number++) $numbers[] = $number;
        if($end < $pages - 1) $numbers[] = null;
        if($pages > 1) $numbers[] = $pages;
        return $numbers;
    }

    protected function renderPaginationShell(string $items, int $page, int $total, int $limit, string $ariaLabel, string $itemLabel): string {
        $first = (($page - 1) * max(1, $limit)) + 1;
        $last = min(max(0, $total), $page * max(1, $limit));
        $summary = sprintf($this->_('Showing %1$d–%2$d of %3$d %4$s'), $first, $last, max(0, $total), $itemLabel);
        return "<nav class='Mailbox-pagination' aria-label='" . $this->e($ariaLabel) . "'><div class='Mailbox-pagination-meta'><span>" . $this->_('Page') . " {$page}</span><strong>" . $this->e($summary) . "</strong></div><ul class='uk-pagination uk-margin-remove'>{$items}</ul></nav>";
    }

    protected function renderLoadingWorkspace(array $folders, string $folder, int $page, int $uid): string {
        $this->configureWorkspaceChrome($folder, null, $page, false, $uid > 0);
        $warmUrl = $this->warmUrl($folder, $page, $uid, true);
        $content = "<section class='uk-card uk-card-default uk-card-small uk-card-body Mailbox-table-panel Mailbox-loading' aria-live='polite'><span uk-spinner='ratio:1.1'></span><div><h2 class='uk-card-title uk-margin-remove'>" . $this->_('Loading mailbox') . "</h2><p class='uk-text-meta uk-margin-remove-top'>" . $this->_('Connecting in the background and loading folders plus at most 100 newest messages for this page.') . "</p></div></section>";
        return $this->renderLayout($folders, $folder, $content, $this->renderCacheStatus($folder, $page, $uid, null, $warmUrl));
    }

    protected function renderCacheStatus(string $folder, int $page, int $uid, ?int $age, string $warmUrl = ''): string {
        if($warmUrl === '') $warmUrl = $this->warmUrl($folder, $page, $uid, true);
        $loading = $age === null;
        $label = $loading ? $this->_('Preparing encrypted cache…') : ($age < 5 ? $this->_('Updated just now') : sprintf($this->_('Cached %s ago'), $this->formatCacheAge($age)));
        $auto = $loading || $age >= self::VIEW_CACHE_REFRESH_AGE;
        return "<div class='Mailbox-cache-bar' data-mailbox-cache-warm='" . $this->e($warmUrl) . "' data-mailbox-auto='" . ($auto ? '1' : '0') . "'><div class='Mailbox-cache-copy'><span uk-icon='icon:database;ratio:.82'></span><span data-mailbox-cache-label>" . $this->e($label) . "</span><span class='uk-label'>" . $this->_('Encrypted') . "</span></div><a class='uk-button uk-button-default uk-button-small' data-mailbox-refresh href='" . $this->e($this->warmUrl($folder, $page, $uid, false)) . "'><span uk-icon='icon:refresh;ratio:.82'></span> " . $this->_('Refresh') . "</a></div>" . $this->renderCacheClient();
    }

    protected function warmUrl(string $folder, int $page, int $uid, bool $json): string {
        $query = ['folder' => $this->encodeFolderToken($folder), 'p' => max(1, $page), 'refresh' => 1];
        if($uid > 0) $query['uid'] = $uid;
        if($json) $query['cache_warm'] = 1;
        return $this->url($query);
    }

    protected function formatCacheAge(int $seconds): string {
        if($seconds < 60) return sprintf($this->_('%d sec'), max(1, $seconds));
        if($seconds < 3600) return sprintf($this->_('%d min'), max(1, (int) floor($seconds / 60)));
        return sprintf($this->_('%d hr'), max(1, (int) floor($seconds / 3600)));
    }

    protected function renderCacheClient(): string {
        return <<<'HTML'
<script>
(function(){
  if(window.__mailboxCacheClient) return;
  window.__mailboxCacheClient = true;
  function warm(url, trigger) {
    if(!url || document.documentElement.dataset.mailboxWarming === '1') return;
    document.documentElement.dataset.mailboxWarming = '1';
    if(trigger) trigger.classList.add('uk-disabled');
    var label = document.querySelector('[data-mailbox-cache-label]');
    if(label) label.textContent = 'Refreshing mailbox…';
    fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){ if(!response.ok) throw new Error('refresh_failed'); return response.json(); })
      .then(function(result){ if(!result.ok) throw new Error('refresh_failed'); window.location.reload(); })
      .catch(function(){
        delete document.documentElement.dataset.mailboxWarming;
        if(trigger) trigger.classList.remove('uk-disabled');
        if(label) label.textContent = 'Refresh failed — cached data is still available';
      });
  }
  document.addEventListener('click', function(event){
    var trigger = event.target.closest('[data-mailbox-refresh]');
    if(!trigger) return;
    event.preventDefault();
    var holder = document.querySelector('[data-mailbox-cache-warm]');
    warm(holder ? holder.dataset.mailboxCacheWarm : trigger.href, trigger);
  });
  var auto = document.querySelector('[data-mailbox-cache-warm][data-mailbox-auto="1"]');
  if(auto) window.setTimeout(function(){ warm(auto.dataset.mailboxCacheWarm, null); }, 80);
})();
</script>
HTML;
    }

    protected function renderError(string $message): string {
        $settings = $this->settingsUrl();
        $requirement = $this->renderImapRequirement($message);
        return "<div class='ProcessMailbox pw-module-workspace'><div class='uk-alert uk-alert-danger' uk-alert>
            <h2 class='uk-h3'>" . $this->_('Mailbox unavailable') . "</h2>
            <p>" . $this->e($message) . "</p>
            {$requirement}
            <div class='mc-actions'>" . $this->renderTestForm(false) . "<a class='uk-button uk-button-default' href='" . $this->e($settings) . "'><span uk-icon='settings' class='uk-margin-small-right'></span>" . $this->_('Open Mailbox settings') . "</a></div>
        </div></div>";
    }

    protected function renderImapRequirement(string $message): string {
        if(strpos($message, 'webklex/php-imap') !== false) {
            $runtime = rtrim((string) $this->wire()->page->url, '/') . '/dependencies/';
            return "<div class='uk-alert-warning uk-margin' uk-alert><h3 class='uk-h4'>" . $this->_('Webklex IMAP runtime is required') . "</h3><p>" . $this->_('This account uses the Auto or Webklex engine. Download the locked packages into persistent site assets, then repeat the connection test.') . "</p><a class='uk-button uk-button-primary uk-button-small' href='" . $this->e($runtime) . "'><span uk-icon='download' class='uk-margin-small-right'></span>" . $this->_('Download Runtime packages') . "</a></div>";
        }
        if(strpos($message, 'PHP IMAP extension is not installed or enabled') === false) return '';
        $guide = 'https://www.php.net/manual/en/imap.installation.php';
        return "<div class='uk-alert-warning uk-margin' uk-alert><h3 class='uk-h4'>" . $this->_('Native PHP IMAP support is required') . "</h3><p>" . $this->_('This account selected the native PHP IMAP engine, but ext-imap is unavailable in the PHP runtime serving ProcessWire. Choose Auto or Webklex and install Runtime packages, or enable ext-imap for this PHP runtime.') . "</p><ul class='uk-list uk-list-bullet'><li>" . $this->_('Recommended for local MAMP and hosted providers: choose Auto or Webklex, then install the locked package into persistent site assets from Runtime.') . "</li><li>" . $this->_('Native alternative: enable the imap extension for the PHP version assigned to this host and restart the MAMP servers.') . "</li><li>" . $this->_('Verify the web runtime with phpinfo() or the hosting panel; checking only the command-line PHP binary may test a different installation.') . "</li></ul><a class='uk-button uk-button-default uk-button-small' href='" . $this->e($guide) . "' target='_blank' rel='noopener noreferrer'><span uk-icon='info' class='uk-margin-small-right'></span>" . $this->_('Open PHP IMAP installation guide') . "</a></div>";
    }

    protected function renderTestForm(bool $small): string {
        $class = $small ? 'uk-button uk-button-default uk-button-small' : 'uk-button uk-button-default';
        $action = rtrim((string) $this->wire()->page->url, '/') . '/test/';
        return "<form method='post' action='" . $this->e($action) . "' class='uk-display-inline'>
            " . $this->wire()->session->CSRF->renderInput() . "
            <input type='hidden' name='account' value='" . $this->activeAccountId . "'>
            <button type='submit' class='{$class}'><span uk-icon='bolt' class='uk-margin-small-right'></span>" . $this->_('Test connection') . "</button>
        </form>";
    }

    protected function renderDiagnosticsPanel(): string {
        return "<section class='uk-card uk-card-default uk-card-small uk-card-body Mailbox-diagnostics' aria-label='" . $this->e($this->_('Connection diagnostics')) . "'><div><h3 class='uk-h5 uk-margin-remove'>" . $this->_('Connection diagnostics') . "</h3><p class='uk-text-meta uk-margin-remove-top'>" . $this->_('Verify the saved IMAP and SMTP settings without sending a message.') . "</p></div><div class='mc-actions'>" . $this->renderTestForm(true) . $this->renderSmtpTestForm(true) . "</div></section>";
    }

    protected function renderSearchForm(string $folder): string {
        $values = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' ? $this->wire()->input->post : $this->wire()->input->get;
        $selected = static function($actual, string $expected): string { return (string) $actual === $expected ? ' selected' : ''; };
        $advancedOpen = '';
        foreach(['subject', 'from', 'to', 'since', 'before', 'state', 'flagged', 'answered', 'has_attachment', 'min_bytes', 'max_bytes'] as $name) {
            if((string) $values->{$name} !== '' && (string) $values->{$name} !== 'any') { $advancedOpen = ' open'; break; }
        }
        return "<form method='post' action='" . $this->e($this->wire()->page->url) . "' class='Mailbox-filter-panel'>
            " . $this->wire()->session->CSRF->renderInput() . "
            <input type='hidden' name='account' value='" . $this->activeAccountId . "'><input type='hidden' name='search' value='1'><input type='hidden' name='folder' value='" . $this->e($this->encodeFolderToken($folder)) . "'>
            <div class='Mailbox-search-primary'>
                <div class='Mailbox-search-query'><span uk-icon='icon:search;ratio:.85'></span><label class='uk-hidden' for='MailboxSearchText'>" . $this->_('Search message text') . "</label><input class='uk-input' id='MailboxSearchText' name='text' maxlength='256' placeholder='" . $this->e($this->_('Search messages…')) . "' value='" . $this->e($values->text) . "'></div>
                <label class='uk-hidden' for='MailboxSearchScope'>" . $this->_('Search scope') . "</label><select class='uk-select' id='MailboxSearchScope' name='scope'><option value='folder'>" . $this->_('Current folder') . "</option><option value='all'" . $selected($values->scope, 'all') . ">" . $this->_('All folders') . "</option></select>
                <button class='uk-button uk-button-primary' type='submit'><span uk-icon='search' class='uk-margin-small-right'></span>" . $this->_('Search') . "</button>
            </div>
            <details class='uk-margin-small-top Mailbox-search-advanced'{$advancedOpen}><summary><span uk-icon='icon:settings;ratio:.75'></span> " . $this->_('Advanced filters') . "</summary><div class='uk-grid uk-grid-small uk-child-width-1-4@m uk-margin-small-top'>
                <div><label class='uk-form-label'>" . $this->_('Subject') . "</label><input class='uk-input' name='subject' maxlength='256' value='" . $this->e($values->subject) . "'></div>
                <div><label class='uk-form-label'>" . $this->_('From') . "</label><input class='uk-input' name='from' maxlength='256' value='" . $this->e($values->from) . "'></div>
                <div><label class='uk-form-label'>" . $this->_('To') . "</label><input class='uk-input' name='to' maxlength='256' value='" . $this->e($values->to) . "'></div>
                <div><label class='uk-form-label'>" . $this->_('Since') . "</label><input class='uk-input' type='date' name='since' value='" . $this->e($values->since) . "'></div>
                <div><label class='uk-form-label'>" . $this->_('Before') . "</label><input class='uk-input' type='date' name='before' value='" . $this->e($values->before) . "'></div>
                <div><label class='uk-form-label'>" . $this->_('Read state') . "</label><select class='uk-select' name='state'><option value='any'>" . $this->_('Any') . "</option><option value='unseen'" . $selected($values->state, 'unseen') . ">" . $this->_('Unread') . "</option><option value='seen'" . $selected($values->state, 'seen') . ">" . $this->_('Read') . "</option></select></div>
                <div><label class='uk-form-label'>" . $this->_('Flagged') . "</label><select class='uk-select' name='flagged'><option value='any'>" . $this->_('Any') . "</option><option value='yes'" . $selected($values->flagged, 'yes') . ">" . $this->_('Yes') . "</option><option value='no'" . $selected($values->flagged, 'no') . ">" . $this->_('No') . "</option></select></div>
                <div><label class='uk-form-label'>" . $this->_('Answered') . "</label><select class='uk-select' name='answered'><option value='any'>" . $this->_('Any') . "</option><option value='yes'" . $selected($values->answered, 'yes') . ">" . $this->_('Yes') . "</option><option value='no'" . $selected($values->answered, 'no') . ">" . $this->_('No') . "</option></select></div>
                <div><label class='uk-form-label'>" . $this->_('Has attachment') . "</label><select class='uk-select' name='has_attachment'><option value='any'>" . $this->_('Any') . "</option><option value='yes'" . $selected($values->has_attachment, 'yes') . ">" . $this->_('Yes') . "</option><option value='no'" . $selected($values->has_attachment, 'no') . ">" . $this->_('No') . "</option></select></div>
                <div><label class='uk-form-label'>" . $this->_('Size range (bytes)') . "</label><div class='uk-grid uk-grid-small uk-child-width-1-2'><div><input class='uk-input' type='number' min='0' name='min_bytes' placeholder='min' value='" . $this->e($values->min_bytes) . "'></div><div><input class='uk-input' type='number' min='0' name='max_bytes' placeholder='max' value='" . $this->e($values->max_bytes) . "'></div></div></div>
            </div></details>
        </form>";
    }

    protected function adminSearchFilters($values = null): array {
        if($values === null) $values = $this->wire()->input->post;
        $filters = [];
        foreach(['text', 'from', 'to', 'subject', 'since', 'before', 'state', 'flagged', 'answered', 'has_attachment', 'min_bytes', 'max_bytes'] as $name) {
            $value = $values->{$name};
            if($value !== null && $value !== '') $filters[$name] = (string) $value;
        }
        return $filters;
    }

    protected function renderSearchPagination(int $page, int $pages, int $total, int $limit): string {
        if($pages <= 1) return '';
        $page = max(1, min($pages, $page));
        $base = ['account' => $this->activeAccountId, 'search' => 1, 'folder' => (string) $this->wire()->input->post->folder, 'scope' => (string) ($this->wire()->input->post->scope ?: 'folder')] + $this->adminSearchFilters($this->wire()->input->post);
        $pageForm = function(int $target, string $label, string $content) use ($base): string {
            $fields = $this->wire()->session->CSRF->renderInput();
            foreach($base + ['p' => $target] as $name => $value) $fields .= "<input type='hidden' name='" . $this->e($name) . "' value='" . $this->e($value) . "'>";
            return "<form method='post' action='" . $this->e($this->wire()->page->url) . "'>{$fields}<button type='submit' aria-label='" . $this->e($label) . "'>{$content}</button></form>";
        };
        $items = $page > 1
            ? "<li class='Mailbox-pagination-edge'>" . $pageForm($page - 1, $this->_('Previous search page'), "<span uk-pagination-previous></span><span class='Mailbox-pagination-label'>" . $this->_('Previous') . '</span>') . '</li>'
            : "<li class='uk-disabled Mailbox-pagination-edge'><span aria-disabled='true'><span uk-pagination-previous></span><span class='Mailbox-pagination-label'>" . $this->_('Previous') . '</span></span></li>';
        foreach($this->paginationSequence($page, $pages) as $number) {
            if($number === null) {
                $items .= "<li class='uk-disabled Mailbox-pagination-ellipsis'><span aria-hidden='true'>…</span></li>";
            } elseif($number === $page) {
                $items .= "<li class='uk-active'><span aria-current='page'>{$number}</span></li>";
            } else {
                $items .= '<li>' . $pageForm($number, sprintf($this->_('Search page %d'), $number), (string) $number) . '</li>';
            }
        }
        $items .= $page < $pages
            ? "<li class='Mailbox-pagination-edge'>" . $pageForm($page + 1, $this->_('Next search page'), "<span class='Mailbox-pagination-label'>" . $this->_('Next') . "</span><span uk-pagination-next></span>") . '</li>'
            : "<li class='uk-disabled Mailbox-pagination-edge'><span aria-disabled='true'><span class='Mailbox-pagination-label'>" . $this->_('Next') . "</span><span uk-pagination-next></span></span></li>";
        return $this->renderPaginationShell($items, $page, $total, $limit, $this->_('Search result pages'), $this->_('matches'));
    }

    protected function renderSmtpTestForm(bool $small): string {
        $class = $small ? 'uk-button uk-button-default uk-button-small' : 'uk-button uk-button-default';
        $action = rtrim((string) $this->wire()->page->url, '/') . '/test-smtp/';
        return "<form method='post' action='" . $this->e($action) . "' class='uk-display-inline'>
            " . $this->wire()->session->CSRF->renderInput() . "
            <input type='hidden' name='account' value='" . $this->activeAccountId . "'>
            <button type='submit' class='{$class}'><span uk-icon='mail' class='uk-margin-small-right'></span>" . $this->_('Test SMTP') . "</button>
        </form>";
    }

    protected function mailboxFolderLabel(string $folder): string {
        if(function_exists('mb_convert_encoding')) {
            $label = @mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');
            if(is_string($label) && $label !== '') return $label;
        }
        return $folder;
    }

    protected function encodeFolderToken(string $folder): string {
        return rtrim(strtr(base64_encode($folder), '+/', '-_'), '=');
    }

    protected function decodeFolderToken(string $token): string {
        if($token === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) throw new WireException($this->_('Invalid folder token.'));
        $padding = strlen($token) % 4;
        if($padding) $token .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if(!is_string($decoded) || $decoded === '') throw new WireException($this->_('Invalid folder token.'));
        return $decoded;
    }

    protected function url(array $query = []): string {
        $url = (string) $this->wire()->page->url;
        if($this->activeAccountId > 0 && !array_key_exists('account', $query)) $query = ['account' => $this->activeAccountId] + $query;
        return $query ? $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;
    }

    protected function formatBytes(int $bytes): string {
        if($bytes < 1024) return $bytes . ' B';
        if($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    protected function e($value): string {
        return $this->wire()->sanitizer->entities((string) $value);
    }

}

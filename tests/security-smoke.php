<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}

    require_once dirname(__DIR__) . '/src/MailboxApprovalStore.php';
    require_once dirname(__DIR__) . '/src/MailboxConfirmationClient.php';

    function expectException(callable $callback, string $message): void {
        try {
            $callback();
        } catch(WireException $error) {
            return;
        }
        throw new \RuntimeException($message);
    }

    MailboxConfirmationClient::assertUrlAllowed('https://confirm.example.com/a', ['confirm.example.com']);
    MailboxConfirmationClient::assertUrlAllowed('https://a.example.org/a', ['*.example.org']);
    expectException(static function(): void {
        MailboxConfirmationClient::assertUrlAllowed('http://confirm.example.com/a', ['confirm.example.com']);
    }, 'HTTP URL was accepted.');
    expectException(static function(): void {
        MailboxConfirmationClient::assertUrlAllowed('https://evil.example/a', ['*.example.org']);
    }, 'Non-allowlisted host was accepted.');
    expectException(static function(): void {
        MailboxConfirmationClient::assertUrlAllowed('https://user:pass@confirm.example.com/a', ['confirm.example.com']);
    }, 'URL credentials were accepted.');

    $client = new MailboxConfirmationClient(['127.0.0.1'], 1, 4096);
    $method = new \ReflectionMethod($client, 'publicAddresses');
    if(PHP_VERSION_ID < 80100) $method->setAccessible(true);
    expectException(static function() use ($client, $method): void {
        $method->invoke($client, '127.0.0.1');
    }, 'Private address was accepted.');

    $directory = sys_get_temp_dir() . '/mailbox-security-' . bin2hex(random_bytes(6));
    $file = $directory . '/proposals.json';
    $store = new MailboxApprovalStore($file);
    $secretUrl = 'https://confirm.example.com/activate?token=secret-token';
    $proposal = $store->create('INBOX', 42, [
        'hash' => hash('sha256', $secretUrl),
        'host' => 'confirm.example.com',
        'path' => '/activate',
        'label' => 'Confirm',
    ], 'cli:agent');
    $stored = (string) file_get_contents($file);
    if(strpos($stored, $secretUrl) !== false || strpos($stored, 'secret-token') !== false) {
        throw new \RuntimeException('The full confirmation URL leaked into storage.');
    }
    expectException(static function() use ($store, $proposal): void {
        $store->approve($proposal['id'], 'cli:agent');
    }, 'Self-approval was accepted.');
    $approved = $store->approve($proposal['id'], 'user:7');
    if($approved['status'] !== 'approved') throw new \RuntimeException('Proposal was not approved.');
    $claimed = $store->claimExecution($proposal['id'], 'cli:agent');
    if($claimed['status'] !== 'executing') throw new \RuntimeException('Proposal was not claimed.');
    expectException(static function() use ($store, $proposal): void {
        $store->claimExecution($proposal['id'], 'cli:second-agent');
    }, 'Concurrent/replayed execution claim was accepted.');
    $executed = $store->markExecuted($proposal['id'], 'cli:agent', ['status' => 302]);
    if($executed['status'] !== 'executed') throw new \RuntimeException('Proposal was not marked executed.');

    $mutationSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxMutationConcern.php');
    $squadSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxSquadAdapter.php');
    $restSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxRestApi.php');
    if(strpos($mutationSource, 'bool $expunge = false') === false || strpos($restSource, "!== 'EXPUNGE'") === false) {
        throw new \RuntimeException('Permanent IMAP expunge lost its safe default or explicit confirmation guard.');
    }
    $processSource = (string) file_get_contents(dirname(__DIR__) . '/ProcessMailbox.module.php');
    $processAiSource = (string) file_get_contents(dirname(__DIR__) . '/src/ProcessMailboxAiConcern.php');
    $bulkProcessSource = (string) file_get_contents(dirname(__DIR__) . '/src/ProcessMailboxBulkConcern.php');
    $configSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxConfigConcern.php');
    $mailboxSource = (string) file_get_contents(dirname(__DIR__) . '/Mailbox.module.php');
    $accountsSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxAccountsConcern.php');
    if(strpos($configSource, "set('messagesPerPage', 100)") === false || strpos($mailboxSource, 'setDisplayDefaults()') === false || strpos($accountsSource, "['messagesPerPage'] ?? 100") === false) throw new \RuntimeException('The 100-message admin page default is missing.');
    if(strpos($processSource, '___executeOauthStart') === false || strpos($processSource, "REQUEST_METHOD'] ?? 'GET')) !== 'POST'") === false || strpos($processSource, 'CSRF->hasValidToken()') === false || strpos($configSource, 'oauth-start/?account=') !== false) throw new \RuntimeException('OAuth start is not POST+CSRF protected.');
    foreach(['renderImapRequirement', 'Webklex IMAP runtime is required', 'Download Runtime packages', 'Native PHP IMAP support is required', 'Choose Auto or Webklex', 'https://www.php.net/manual/en/imap.installation.php'] as $needle) {
        if(strpos($processSource, $needle) === false) throw new \RuntimeException('Admin IMAP dependency guidance is missing: ' . $needle);
    }
    foreach(['shell_exec(', 'passthru(', 'proc_open(', 'system('] as $unsafeInstaller) {
        if(strpos($processSource, $unsafeInstaller) !== false) throw new \RuntimeException('Admin dependency guidance gained a system installer boundary.');
    }
    foreach(['css/mailbox.admin.css', 'js/mailbox.admin.js', 'cache_warm', 'data-mailbox-cache-warm', 'getCachedMessages', 'renderDiagnosticsPanel', 'renderFolderSidebar'] as $needle) {
        if(strpos($processSource, $needle) === false) throw new \RuntimeException('Admin cache-first boundary is missing: ' . $needle);
    }
    if(substr_count($processSource, "data-mailbox-refresh href=") !== 1) throw new \RuntimeException('The main Mailbox page must expose exactly one refresh control.');
    if(strpos($processSource, 'pw-wrap') !== false) throw new \RuntimeException('Legacy pw-wrap remains in the Mailbox workspace.');
    $adminCss = (string) file_get_contents(dirname(__DIR__) . '/css/mailbox.admin.css');
    if(strpos($processSource, 'mc-folders uk-card-body') !== false || strpos($adminCss, '.mc-folders > .uk-nav { margin: 0; }') === false) throw new \RuntimeException('Mailbox folder sidebar can inherit overflowing AdminThemeUikit card navigation margins.');
    if(strpos($processSource, 'module/edit?name=Mailbox&collapse_info=1') === false) throw new \RuntimeException('Mailbox settings links do not collapse the ProcessWire module information panel.');
    foreach(['Mailbox-admin-nav', 'uk-subnav uk-subnav-pill', 'Mailbox-settings-link', 'renderSettingsIcon'] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Verk-style Mailbox navigation is missing: ' . $needle);
    foreach(['configureWorkspaceChrome', 'configureSectionChrome', 'activeAccountLabel', 'safeChromeText', 'breadcrumb(', 'browserTitle(', 'mb_strimwidth'] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Contextual Mailbox admin chrome is missing: ' . $needle);
    foreach(['paginationSequence', 'renderPaginationShell', 'Mailbox-pagination-edge', 'Mailbox-pagination-ellipsis', "aria-current='page'", 'Showing %1$d–%2$d of %3$d %4$s'] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Mailbox pagination structure is missing: ' . $needle);
    foreach(['Mailbox-message-toolbar', 'Mailbox-message-summary', 'Mailbox-message-capability', 'Mailbox-message-reader-head', 'Mailbox-message-tool', 'Next message', 'nextMessageUrl', "settingsUrl('mailboxActions')"] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Open-message workspace UX is missing: ' . $needle);
    foreach(['renderMessageAiPanel', 'renderAiReplyControls', 'data-mailbox-ai-summary', 'data-mailbox-reply-form', 'Reply mode', 'Draft with AI', 'external provider', 'AI never sends automatically'] as $needle) if(strpos($processSource . $processAiSource, $needle) === false) throw new \RuntimeException('Open-message AI UX is missing: ' . $needle);
    foreach(['___executeAiSummary', '___executeAiReplyDraft', '___executeAjaxReply', 'assertMailboxAjaxPost', 'HTTP_X_REQUESTED_WITH', 'CSRF->hasValidToken()', 'analyzeWithSquad', 'Mailbox::apiPermission', 'Mailbox::sendPermission', 'sendMailboxJson', 'Do not send anything'] as $needle) if(strpos($processAiSource, $needle) === false && strpos((string) file_get_contents(dirname(__DIR__) . '/src/MailboxAgentApi.php'), $needle) === false) throw new \RuntimeException('AJAX AI permission or execution boundary is missing: ' . $needle);
    foreach(['.Mailbox-pagination', '.Mailbox-pagination-meta', '.Mailbox-pagination-edge', '.Mailbox-pagination-ellipsis', '.Mailbox-pagination-label'] as $needle) if(strpos($adminCss, $needle) === false) throw new \RuntimeException('Mailbox pagination design is missing: ' . $needle);
    foreach(['.Mailbox-admin-nav', '.Mailbox-settings-link', 'flex: 0 0 48px', 'width: 32px'] as $needle) if(strpos($adminCss, $needle) === false) throw new \RuntimeException('Verk-style Mailbox navigation CSS is missing: ' . $needle);
    foreach(['___executeDependencies', "isSuperuser()", 'dependency_action', "CSRF->hasValidToken()", 'MailboxDependencyInstaller'] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Mailbox runtime installer controller boundary is missing: ' . $needle);
    foreach(['MailboxConfig-hero', 'mailboxOverview', 'mailboxPreset', 'mailboxImap', 'mailboxAuth', 'mailboxDisplay', 'mailboxSync', 'mailboxAiModel', 'mailboxIntegrations', 'Credentials protected', 'Inputfield::collapsedYes'] as $needle) if(strpos($configSource, $needle) === false) throw new \RuntimeException('Guided Mailbox configuration UI is missing: ' . $needle);
    foreach(['mailboxActions', 'MailboxConfig-action-guide', 'Enable message management in the admin', 'Enable SMTP reply and forward', 'independent of the optional Agent API'] as $needle) if(strpos($configSource, $needle) === false) throw new \RuntimeException('Dedicated Message actions settings are missing: ' . $needle);
    foreach(['enableSquadIntegration', 'Enable Squad AI for mailbox analysis and bounded agent tools', 'mailboxAiReadiness', 'Squad must be installed first', "attr('disabled', 'disabled')", 'squadProviderModel', 'Provider and model', 'Use Squad default', 'squadMaxTokens', 'squadTemperature', 'squadTimeout', 'squadMaxSteps', 'Response caching, prompt caching, web search', "method_exists(\$squad, 'ask')", "method_exists(\$squad, 'run')"] as $needle) if(strpos($configSource, $needle) === false) throw new \RuntimeException('Squad AI model configuration boundary is missing: ' . $needle);
    if(strpos($configSource, "isInstalled('Squad')") > strpos($configSource, "requires the PHP agent API or local CLI")) throw new \RuntimeException('Squad installation must be validated before access-channel dependencies.');
    if(substr_count($configSource, "attr('name', 'enableSquadIntegration')") !== 1) throw new \RuntimeException('Squad integration toggle must appear exactly once.');
    if(strpos($configSource, 'enableMutations->showIf') !== false || strpos($configSource, 'enableSending->showIf') !== false) throw new \RuntimeException('Admin message actions are still hidden behind Agent API conditional visibility.');
    $configCss = (string) file_get_contents(dirname(__DIR__) . '/css/mailbox.config.css');
    $configJs = (string) file_get_contents(dirname(__DIR__) . '/js/mailbox.config.js');
    if(strpos($configCss, '#ModuleEditForm.MailboxConfigForm') === false || strpos($configCss, '.InputfieldContent.uk-form-controls') === false || strpos($configCss, 'width: 100% !important') === false || strpos($configCss, 'MailboxConfig-action-guide') === false || strpos($configCss, 'MailboxConfig-ai-safety') === false || strpos($configCss, 'MailboxConfig-ai-readiness') === false || strpos($configCss, 'MailboxConfig-remote-warning') === false || strpos($configCss, '--mailbox-config-surface') === false || strpos($configCss, 'var(--pw-blocks-background, light-dark(#fff, #000))') === false || strpos($configJs, 'MailboxConfigForm') === false || strpos($configJs, 'InputfieldStateCollapsed') === false || strpos($configJs, 'window.location.hash') === false || strpos($configJs, 'revealSection') === false) throw new \RuntimeException('Mailbox configuration assets are missing, unscoped, or lack direct section navigation.');
    if(strpos($adminCss, 'text-decoration: none !important') === false || strpos($adminCss, '.Mailbox-sidebar-tools') === false || strpos($adminCss, '.Mailbox-search-primary') === false) throw new \RuntimeException('Mailbox first-page UI safeguards are missing.');
    foreach(["renderFolderSidebar(\$folders, \$selected, \$cacheStatus)", "'Mailbox controls and folders'", "'Search messages…'", "foreach(['subject', 'from'", "class='Mailbox-search-query'", "'Encrypted'", "'Refresh'"] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Sidebar mailbox command/search UI is missing: ' . $needle);
    foreach(['.Mailbox-sidebar-tools', 'grid-template-columns: 1fr', '.Mailbox-search-query', 'padding-left: 38px', '.is-collapsed .Mailbox-sidebar-tools'] as $needle) if(strpos($adminCss, $needle) === false) throw new \RuntimeException('Sidebar mailbox command/search styling is missing: ' . $needle);
    foreach(['Mailbox-sidebar-stack', 'Mailbox-sidebar-accounts', 'Mailbox-sidebar-folders', 'Mailbox-sidebar-search', "id='MailboxSearchPanel'"] as $needle) if(strpos($processSource . $adminCss, $needle) === false) throw new \RuntimeException('Mailbox sidebar islands are missing: ' . $needle);
    foreach(['Mailbox-list-context', 'Newest first', '%1$d–%2$d of %3$d', 'box-sizing: border-box', 'min-height: 72px', 'padding: 11px 18px 9px'] as $needle) if(strpos($processSource . $adminCss, $needle) === false) throw new \RuntimeException('Compact message-list header context is missing: ' . $needle);
    foreach(['mailbox.messages.indexed-hook', 'enableBackgroundSync', '1.0.0'] as $needle) if(strpos((string) file_get_contents(dirname(__DIR__) . '/src/MailboxConfirmationConcern.php'), $needle) === false) throw new \RuntimeException('Mailbox indexed-message integration capability is missing: ' . $needle);
    if(strpos($processSource, 'Mailbox-sidebar-accounts') > strpos($processSource, 'Mailbox-sidebar-folders') || strpos($processSource, 'Mailbox-sidebar-folders') > strpos($processSource, 'Mailbox-sidebar-search')) throw new \RuntimeException('Mailbox sidebar islands are not ordered Accounts, Folders, Search.');
    if(strpos($adminCss, 'border-radius: 0 !important') === false || strpos($adminCss, '.Mailbox-sidebar-stack') === false || strpos($adminCss, 'gap: 16px') === false || strpos($adminCss, 'gap: 8px') === false) throw new \RuntimeException('Mailbox sidebar island spacing or square account buttons are missing.');
    foreach(['.ProcessMailbox .uk-button,', 'display: inline-flex', 'align-items: center', 'vertical-align: middle', '.uk-icon > svg'] as $needle) if(strpos($adminCss, $needle) === false) throw new \RuntimeException('Mailbox button/icon vertical alignment is missing: ' . $needle);
    foreach(['column-gap: 7px', 'margin-inline: 0 !important', 'padding: 0 10px !important', '.pw .ProcessMailbox .uk-button.Mailbox-message-tool:not(.uk-button-text):not(.uk-button-link):hover', 'background: var(--pw-main-color, #eb1d61) !important', 'transition: background-color 140ms ease'] as $needle) if(strpos($adminCss, $needle) === false) throw new \RuntimeException('Mailbox button inversion or stable icon spacing is missing: ' . $needle);
    foreach(['--Mailbox-surface', '--Mailbox-hover', 'light-dark(#237348, #78d68b)', '.mc-folder > a:focus', 'background: var(--Mailbox-hover)', 'var(--Mailbox-surface)', '.uk-button:not(.uk-button-text):not(.uk-button-link):not(.uk-button-danger):not(:disabled):hover'] as $needle) if(strpos($adminCss, $needle) === false) throw new \RuntimeException('Mailbox dark-mode surface or hover safeguards are missing: ' . $needle);
    $adminJs = (string) file_get_contents(dirname(__DIR__) . '/js/mailbox.admin.js');
    foreach(['initializeMessageComposeDialogs', 'data-mailbox-compose', "event.key !== 'Escape'", "removeAttribute('open')"] as $needle) if(strpos($processSource . $adminJs, $needle) === false) throw new \RuntimeException('Reply/forward outside-click dismissal is missing: ' . $needle);
    foreach(['processmailbox_sidebar_collapsed', 'localStorage.setItem', 'data-mailbox-href', "event.key !== 'Enter'", 'data-mailbox-copy-target', 'navigator.clipboard.writeText', 'data-mailbox-account-form', 'data-mailbox-auth-panel', 'data-mailbox-apply-preset'] as $needle) if(strpos($adminJs, $needle) === false) throw new \RuntimeException('Mailbox admin UI behavior is missing: ' . $needle);
    foreach(['mailboxAjax', 'initializeMailboxAi', 'data-mailbox-ai-summary', 'data-mailbox-ai-draft', 'data-mailbox-reply-form', "'X-Requested-With': 'XMLHttpRequest'", 'result.textContent', 'body.value = response.content'] as $needle) if(strpos($adminJs, $needle) === false) throw new \RuntimeException('Mailbox AI AJAX client is missing: ' . $needle);
    if(strpos($adminJs, 'result.innerHTML') !== false || strpos($adminJs, 'body.innerHTML') !== false) throw new \RuntimeException('Mailbox AI output is inserted as HTML.');
    foreach(["sandbox='", 'credentialless referrerpolicy=', 'MailboxMessageView::sanitizedDocument($html, $remoteImages, $externalLinks)', 'allowRemoteMessageImages', 'allowExternalMessageLinks', 'Admin address remains hidden', "header('Referrer-Policy: no-referrer')", '___executeMessageAction', 'data-mailbox-view=', 'data-mailbox-confirm='] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Secure message reader UI is missing: ' . $needle);
    foreach(['allowRemoteMessageImages', 'allowExternalMessageLinks', 'I understand the privacy risk', 'External content can report mailbox activity', 'never sends the ProcessWire admin address as the HTTP Referer', 'public-hostname HTTPS URLs on port 443'] as $needle) if(strpos($configSource, $needle) === false) throw new \RuntimeException('External-content consent settings are missing: ' . $needle);
    foreach(['safeRemoteImageUrl', 'sanitizedCss', 'img-src data:', '($allowRemoteImages ? \' https:\' : \'\')', 'referrerpolicy', "strtolower((string) (\$parts['scheme'] ?? '')) !== 'https'"] as $needle) if(strpos((string) file_get_contents(dirname(__DIR__) . '/src/MailboxMessageView.php'), $needle) === false) throw new \RuntimeException('Remote-image sanitization boundary is missing: ' . $needle);
    foreach(['safePublicHttpsUrl', "target', '_blank'", "rel', 'noopener noreferrer'", "foreach(['ping', 'download']", 'allow-popups allow-popups-to-escape-sandbox'] as $needle) if(strpos($processSource . (string) file_get_contents(dirname(__DIR__) . '/src/MailboxMessageView.php'), $needle) === false) throw new \RuntimeException('External-message-link boundary is missing: ' . $needle);
    foreach(['___executeSeen', 'data-mailbox-seen', "REQUEST_METHOD'] ?? 'GET')) !== 'POST'", 'sendSeenResponse'] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Admin read receipt boundary is missing: ' . $needle);
    foreach(['initializeSeenReceipt', "method: 'POST'", "credentials: 'same-origin'", "new FormData(form)"] as $needle) if(strpos($adminJs, $needle) === false) throw new \RuntimeException('Admin read receipt client is missing: ' . $needle);
    foreach(['markMessageReadOnOpen', "preg_match('/^user:\\d+\$/', \$actor)", "'opened_seen'"] as $needle) if(strpos($mutationSource, $needle) === false) throw new \RuntimeException('Authenticated read receipt mutation is missing: ' . $needle);
    foreach(['bulkMessageAction', 'limited to 100 messages', "['read', 'unread', 'flag', 'unflag', 'move', 'delete']", 'uids_hash', "'expunged' => false"] as $needle) if(strpos($mutationSource, $needle) === false) throw new \RuntimeException('Bounded non-expunging bulk mutation is missing: ' . $needle);
    foreach(['getAgentMessage', "unset(\$message['attachments'])", "'cache' => false", "'webSearch' => false", "'promptCache' => false", 'MAX_MESSAGE_JSON_BYTES', 'mailbox_propose_confirmation', 'never approves or executes', 'method_exists($squad'] as $needle) if(strpos($squadSource, $needle) === false) throw new \RuntimeException('Squad security boundary is missing: ' . $needle);
    foreach(['analyzeWithSquad', 'runSquadAgent', 'assertCanSquad', 'canUseSquad'] as $needle) if(strpos((string) file_get_contents(dirname(__DIR__) . '/src/MailboxAgentApi.php'), $needle) === false) throw new \RuntimeException('Permission-gated Squad agent API is missing: ' . $needle);
    foreach(["case 'squad-analyze'", "case 'squad-agent'", 'instruction($body)', 'canUseSquad'] as $needle) if(strpos($restSource, $needle) === false) throw new \RuntimeException('Same-origin Squad REST surface is missing: ' . $needle);
    foreach(['___executeBulkAction', "REQUEST_METHOD'] ?? 'GET')) !== 'POST'", 'CSRF->hasValidToken()', 'Mailbox::writePermission', 'bulkMessageAction'] as $needle) if(strpos($bulkProcessSource, $needle) === false) throw new \RuntimeException('Bulk admin controller boundary is missing: ' . $needle);
    foreach(['data-mailbox-bulk-form', 'data-mailbox-select-all', 'data-mailbox-bulk-action', 'Delete never permanently expunges messages', "settingsUrl('mailboxActions')"] as $needle) if(strpos($processSource, $needle) === false) throw new \RuntimeException('Bulk message-list controls are missing: ' . $needle);
    foreach(['initializeBulkActions', 'selectAll.indeterminate', 'mailboxBulkDeleteConfirm'] as $needle) if(strpos($adminJs, $needle) === false) throw new \RuntimeException('Bulk selection behavior is missing: ' . $needle);
    $imapSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxImapConcern.php');
    foreach(["'html' =>", "'raw' =>", "unset(\$message['html'], \$message['raw'])"] as $needle) if(strpos($imapSource, $needle) === false) throw new \RuntimeException('Trusted/agent message representation boundary is missing: ' . $needle);
    $discoverUi = (string) file_get_contents(dirname(__DIR__) . '/src/ProcessMailboxDiscoveryConcern.php');
    foreach(['Credential-free helper', 'No password', 'No automatic changes', 'Run both connection tests', 'Technical details', 'renderDiscoveryCandidate'] as $needle) if(strpos($discoverUi, $needle) === false) throw new \RuntimeException('Guided Discover UI is missing: ' . $needle);
    $accountUi = (string) file_get_contents(dirname(__DIR__) . '/src/ProcessMailboxAccountsConcern.php');
    foreach(['Three-mailbox workspace', 'Mailbox-account-metrics', 'Identity and provider', 'Incoming mail · IMAP', 'Outgoing mail · SMTP', 'Mailbox-account-danger', 'MailboxAccountPresets'] as $needle) if(strpos($accountUi, $needle) === false) throw new \RuntimeException('Guided Accounts UI is missing: ' . $needle);
    $cacheSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxCacheConcern.php');
    $indexSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxIndexStore.php');
    foreach(['putCache', 'getCache', 'clearCache'] as $needle) {
        if(strpos($cacheSource . $indexSource, $needle) === false) throw new \RuntimeException('Encrypted view-cache boundary is missing: ' . $needle);
    }
    if(strpos($cacheSource, "wire('cache')") !== false || strpos($indexSource, "wire('cache')") !== false) throw new \RuntimeException('Mailbox data entered the general-purpose ProcessWire cache.');

    @unlink($file);
    @rmdir($directory);
    fwrite(STDOUT, "Mailbox security smoke tests passed.\n");
}

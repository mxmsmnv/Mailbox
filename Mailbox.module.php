<?php namespace ProcessWire;

require_once __DIR__ . '/src/MailboxApprovalStore.php';
require_once __DIR__ . '/src/MailboxApprovalAdapters.php';
require_once __DIR__ . '/src/MailboxSquadAdapter.php';
require_once __DIR__ . '/src/MailboxConfirmationClient.php';
require_once __DIR__ . '/src/MailboxAgentApi.php';
require_once __DIR__ . '/src/MailboxRestApi.php';
require_once __DIR__ . '/src/MailboxCredentials.php';
require_once __DIR__ . '/src/MailboxOAuth.php';
require_once __DIR__ . '/src/MailboxWebklexTransport.php';
require_once __DIR__ . '/src/MailboxSmtpTransport.php';
require_once __DIR__ . '/src/MailboxAccounts.php';
require_once __DIR__ . '/src/MailboxIndexStore.php';
require_once __DIR__ . '/src/MailboxDiscovery.php';
require_once __DIR__ . '/src/MailboxWebhookClient.php';
require_once __DIR__ . '/src/MailboxAccountsConcern.php';
require_once __DIR__ . '/src/MailboxApiConcern.php';
require_once __DIR__ . '/src/MailboxCredentialsConcern.php';
require_once __DIR__ . '/src/MailboxPresetsConcern.php';
require_once __DIR__ . '/src/MailboxDiscoveryConcern.php';
require_once __DIR__ . '/src/MailboxImapConcern.php';
require_once __DIR__ . '/src/MailboxMutationConcern.php';
require_once __DIR__ . '/src/MailboxSquadConcern.php';
require_once __DIR__ . '/src/MailboxSendingConcern.php';
require_once __DIR__ . '/src/MailboxAttachmentConcern.php';
require_once __DIR__ . '/src/MailboxSearchConcern.php';
require_once __DIR__ . '/src/MailboxSyncConcern.php';
require_once __DIR__ . '/src/MailboxCacheConcern.php';
require_once __DIR__ . '/src/MailboxConfirmationConcern.php';
require_once __DIR__ . '/src/MailboxConfigConcern.php';

/**
 * Mailbox: three-account IMAP and SMTP framework used by ProcessMailbox.
 */
class Mailbox extends WireData implements Module, ConfigurableModule {

    use MailboxCredentialsConcern;
    use MailboxAccountsConcern;
    use MailboxApiConcern;
    use MailboxPresetsConcern;
    use MailboxDiscoveryConcern;
    use MailboxImapConcern;
    use MailboxMutationConcern;
    use MailboxSquadConcern;
    use MailboxSendingConcern;
    use MailboxAttachmentConcern;
    use MailboxSearchConcern;
    use MailboxSyncConcern;
    use MailboxCacheConcern;
    use MailboxConfirmationConcern;
    use MailboxConfigConcern;

    const permission = 'mailbox-view';
    const apiPermission = 'mailbox-api';
    const confirmationPermission = 'mailbox-confirm-links';
    const writePermission = 'mailbox-write';
    const sendPermission = 'mailbox-send';
    const attachmentPermission = 'mailbox-attachments';

    /** @var array<string, callable> */
    protected $approvalProviders = [];

    /** @var array<int, MailboxCredentials> */
    protected $credentialStores = [];

    /** @var MailboxAccounts|null */
    protected $accountStore = null;

    /** @var int|null */
    protected $activeAccountId = null;

    /** @var array<int, array|null> */
    protected $accountCache = [];

    /** @var MailboxOAuth|null */
    protected $oauthService = null;

    /** @var MailboxRestApi|null */
    protected $restService = null;

    /** @var MailboxIndexStore|null */
    protected $indexService = null;

    /** @var bool */
    protected $savingSanitizedConfig = false;

    /** @var array|null */
    protected $pendingCredentialUpdate = null;

    /** @var array|null */
    protected $pendingPrimaryAccountSettings = null;

    public static function getModuleInfo() {
        return [
            'title' => 'Mailbox',
            'summary' => 'Secure three-account IMAP/SMTP workspace with encrypted indexing, APIs, AI, and controlled confirmations.',
            'version' => 102,
            'author' => 'Maxim Semenov',
            'href' => 'https://github.com/mxmsmnv/Mailbox',
            'singular' => true,
            'autoload' => true,
            'requires' => ['PHP>=8.0.2'],
            'installs' => ['ProcessMailbox'],
            'permissions' => [
                self::permission => 'View mail through Mailbox',
                self::apiPermission => 'Read mail through the Mailbox frontend and agent API',
                self::confirmationPermission => 'Approve and execute Mailbox confirmation links',
                self::writePermission => 'Change Mailbox message flags, folders, and deletion state',
                self::sendPermission => 'Send, reply to, and forward mail through Mailbox',
                self::attachmentPermission => 'Download and read Mailbox message attachments',
            ],
        ];
    }

    public function __construct() {
        $this->set('preset', 'custom');
        $this->set('host', '');
        $this->set('port', 993);
        $this->set('encryption', 'ssl');
        $this->set('imapTransport', 'auto');
        $this->set('validateCertificate', 1);
        $this->set('secureAuthentication', 0);
        $this->set('disableAuthenticator', '');
        $this->set('connectionRetries', 1);
        $this->set('openTimeout', 15);
        $this->set('readTimeout', 30);
        $this->set('writeTimeout', 30);
        $this->set('closeTimeout', 5);
        $this->setDisplayDefaults();
        $this->set('authentication', 'password');
        $this->set('oauthProvider', '');
        $this->set('oauthClientId', '');
        $this->set('oauthTenant', 'common');
        $this->set('enableAgentApi', 0);
        $this->set('enableCli', 0);
        $this->set('enableRestApi', 0);
        $this->set('enableLinkConfirmations', 0);
        $this->set('enableAdvancedConfirmations', 0);
        $this->setSquadDefaults();
        $this->set('allowedConfirmationHosts', '');
        $this->set('confirmationTimeout', 10);
        $this->set('maxConfirmationResponseBytes', 262144);
        $this->set('approvalIntegration', 'none');
        $this->set('kontorOrganizationUid', '');
        $this->set('enableMailMutations', 0);
        $this->set('enableMailSending', 0);
        $this->set('smtpHost', '');
        $this->set('smtpPort', 587);
        $this->set('smtpEncryption', 'tls');
        $this->set('smtpValidateCertificate', 1);
        $this->set('smtpFromAddress', '');
        $this->set('smtpFromName', '');
        $this->set('saveSentCopies', 0);
        $this->set('sentFolder', 'Sent');
        $this->set('maxAttachmentBytes', 10485760);
        $this->set('maxAgentAttachmentBytes', 524288);
        $this->set('maxSearchResults', 1000);
        $this->set('maxSearchFolders', 100);
        $this->set('enableBackgroundSync', 0);
        $this->set('syncInterval', 'every5Minutes');
        $this->set('maxSyncFolders', 25);
        $this->set('maxSyncMessagesPerFolder', 200);
        $this->set('syncJobsPerRun', 1);
        $this->set('syncHistoryDays', 30);
        $this->set('enableWebhookNotifications', 0);
        $this->set('webhookUrl', '');
        parent::__construct();
    }

    public function ready() {
        $this->addHookBefore('Modules::saveModuleConfigData', $this, 'normalizeConfigBeforeSave');
        $this->addHookAfter('Modules::saveModuleConfigData', $this, 'persistCredentialsAfterConfigSave');
        $this->registerConfiguredApprovalAdapters();
        $this->registerBackgroundSyncHook();
    }

    public function ___install(): void {
        // The account registry does not exist yet on a fresh install. Pin the
        // credential store to the primary account so currentAccountId() does
        // not query that table before ensureAccountRegistry() creates it.
        $this->credentials(1)->ensureTable();
        $this->ensureAccountRegistry();
        $this->indexStore()->ensureTables();
    }

    public function ___upgrade($fromVersion, $toVersion): void {
        $this->credentials(1)->ensureTable();
        $this->credentials(1)->upgradeAccountIdColumn();
        $this->ensureAccountRegistry();
        $this->indexStore()->ensureTables();
        $this->migrateCredentialsToTable();
    }

    public function ___uninstall(): void {
        $this->indexStore()->dropTables();
        $this->credentials(1)->dropTable();
        $this->accountRegistry()->dropTable();
    }
}

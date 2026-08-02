# Mailbox API

Mailbox 1.0.0 exposes three transports over explicit read, attachment, mutate, confirm, send, and optional Squad AI permission boundaries:

- low-level IMAP transport methods for trusted backend/admin code;
- `MailboxAgentApi`, which enforces a logged-in ProcessWire user and permissions for frontend and agent integrations.
- a disabled-by-default same-origin JSON REST API for logged-in browser/agent sessions.

## HTTP/REST API

Enable both the PHP agent API and REST setting to register `/mailbox-api/v1/{resource}/` through ProcessWire URL hooks. The API deliberately has no CORS or bearer-token mode: it uses the current ProcessWire login session and still requires `mailbox-api`. Responses are JSON with `Cache-Control: no-store` and request limits.

Start with `GET /mailbox-api/v1/session/`. It returns `isLogin`, `canRead`, `canReadAttachments`, `canWrite`, `canSend`, and—only for an authorized reader—the ProcessWire CSRF token name/value/header. POST requests must use `Content-Type: application/json` and include the token either under its dynamic name in the JSON object or in the returned `X-{token-name}` header.

GET resources are `session`, `accounts`, `folders`, `messages`, `message`, `attachment`, `attachment-text`, `links`, `proposals`, `indexed-messages`, and `notifications`. Mail resources accept `?account=1`; message resources additionally accept `folder`, `page`, `limit`, and/or `uid`. `notifications?all=1` includes read rows. Omission of `account` uses the default account. Account results are redacted summaries. The session resource also reports `canUseSquad`.

POST `squad-analyze` accepts `folder`, `uid`, `instruction`, and an optional bounded `options` object. POST `squad-agent` accepts `instruction` and optional `options`. Both require the normal logged-in `mailbox-api` boundary, valid CSRF, `enableSquadIntegration`, and a feature-detected Squad installation exposing `ask()` and `run()`. Supported options are only `provider`, `model`, `maxTokens`, `temperature`, `timeout`, and—for the agent—`maxSteps`; Squad keys, history, system prompts, cache, prompt cache, and web search cannot be selected by the caller.

The module settings include a dedicated **AI model** fieldset, following Liora's active-provider selector contract. `squadProviderModel` stores a non-secret `provider|model` identifier discovered from active Squad providers, while an empty value follows Squad's default. `squadMaxTokens`, `squadTemperature`, `squadTimeout`, and `squadMaxSteps` are bounded defaults; accepted per-call options override them without expanding the security boundary. Provider credentials remain exclusively in Squad.

## Admin AJAX AI reader

The opened-message workspace adds three Process-module POST endpoints: `ai-summary/`, `ai-reply-draft/`, and `ajax-reply/`. They are admin-session-only, require `X-Requested-With: XMLHttpRequest`, a valid ProcessWire CSRF token, an enabled account, and a positive message UID. Summary and reply drafting additionally require the PHP agent API, `mailbox-api`, enabled compatible Squad integration, and send only the existing agent-safe message DTO. Their fixed server prompts request either a summary or a plain-text reply draft; optional reply guidance is control-byte stripped and capped at 500 bytes.

An AI draft is never sent automatically and is inserted into the reply textarea as text for human review. `ajax-reply/` is a separate explicit submit, requires `mailbox-send` plus enabled SMTP, and calls the existing audited `replyMessage()` path. JSON responses are `no-store`; provider errors are not returned verbatim and AI output is never inserted as HTML.

POST `search` accepts JSON `scope=folder|all` plus `folder`, `page`, `limit`, `text`, `from`, `to`, `subject`, `since`, `before`, `state=seen|unseen`, `flagged=yes|no`, `answered=yes|no`, `has_attachment=yes|no`, `min_bytes`, and `max_bytes`. Dates use `YYYY-MM-DD`. Search is POST+CSRF so private terms do not enter URLs. Results contain their source folder, pagination metadata, collected/candidate counts, and `truncated`; whole-account search stops at the configured folder/result caps.

`attachment` requires `folder`, `uid`, `part`, and `mailbox-attachments`; it returns decoded bytes with forced `Content-Disposition: attachment`, `nosniff`, `no-store`, validated MIME, and a safe filename. `attachment-text` uses the same permission but returns JSON only for allowlisted text/JSON/XML/CSV formats below the lower agent limit; URL targets are replaced with hash markers and returned as redacted link metadata. Binary bytes are never base64-wrapped into JSON.

POST resources are `discover`, `search`, `notification-read`, `propose`, `approve`, `reject`, `execute`, `flags`, `move`, `delete`, `test-smtp`, `send`, `reply`, and `forward`. `discover` accepts `email` and returns review-only candidates without credentials, storage, or connection attempts. `notification-read` accepts a positive notification `id`; it changes only the local inbox and still requires CSRF. A proposal contains `folder`, `uid`, redacted link `hash`, and optional `workflow: {"mode":"get|form|code_form","max_steps":1,"code_field":"otp"}`. The code value is never accepted from the caller. Decision JSON contains proposal `id`. Approval and execution still require `mailbox-confirm-links`, self-approval remains blocked, and every proposal executes against its recorded originating account. `test-smtp` requires `mailbox-send` and authenticates without submitting a message.

## Settings discovery

`discoverMailboxSettings(string $email): array` and `MailboxAgentApi::discover(string $email): array` consult a small built-in hosted-provider domain map, secure RFC 6186/8314 SRV services (`_imaps`, `_imap`, `_submissions`, `_submission`), and provider-hosted Thunderbird XML. The local CLI equivalent is `discover --email=user@example.com`. Provider XML fetches use HTTPS with certificate verification, public-IP resolution, DNS pinning, no proxy, no redirects, bounded XML, and `LIBXML_NONET`; the mailbox password is never requested or transmitted.

Each result contains `source`, `confidence`, `username`, a bounded `settings` object, and warnings. Top-level `credentials_used=false` and `applied=false` are invariant. Discovery does not prove ownership or reachability and never mutates an account. An administrator must review a candidate, explicitly save it through the account/configuration workflow, and run `testConnection()` plus `testSmtpConnection()` separately. Arbitrary OAuth issuers are not inferred: only the supported Google/Microsoft mappings can select OAuth, and a registered client ID is still required.

SMTP message bodies are plain text; attachments are explicit bounded byte payloads:

- `send`: `{"to":[{"email":"user@example.com","name":"User"}],"cc":[],"bcc":[],"reply_to":[],"subject":"Hello","body":"Text","attachments":[{"name":"report.pdf","type":"application/pdf","content_base64":"..."}]}`;
- `reply`: `{"folder":"INBOX","uid":123,"body":"Reply text","reply_all":false,"attachments":[]}`;
- `forward`: `{"folder":"INBOX","uid":123,"to":[{"email":"user@example.com"}],"body":"Optional note","attachments":[]}`.

SMTP resources require enabled PHP/REST access, `mailbox-api`, enabled SMTP, and `mailbox-send`. Subject/header injection is rejected; addresses are validated and deduplicated; total recipients are capped at 50; the plain-text body is capped at 1 MiB. Outgoing attachments allow only explicit attachment disposition, safe names and MIME syntax, at most 20 entries, at most 10 MiB each, and 10 MiB total. JSON uses strict `content_base64`; trusted PHP may instead supply binary `content`, but never both. Other POST resources retain the 64 KiB JSON limit, while SMTP resources have a 15 MiB request envelope.

When `saveSentCopies` is enabled, Mailbox appends PHPMailer's exact generated MIME to the configured selectable `sentFolder` after successful SMTP delivery and marks it Seen. It supports ext-imap and OAuth/Webklex. This setting is off by default because providers such as Gmail may already save sent submissions. Append failure returns `sent_copy="failed"` and is audited, but the call remains `sent=true` so a consumer does not retry and duplicate an already delivered message. Generated MIME and attachment content are removed from public results.

The transport enforces resource-specific JSON limits, input bounds, session-local read/write rate limits, no-store headers, generic internal errors, and POST CSRF. It never returns credentials, OAuth tokens, raw confirmation URLs, or executable paths.

## Accounts

Mailbox stores up to three non-secret connection profiles in `mailbox_accounts` and credentials in account-scoped rows of `mailbox_credentials`. Existing installations become account `1` during upgrade. Creation of a fourth account is rejected transactionally; upgrades never delete an existing profile.

- `getAccounts(): array` — account metadata, validated settings, and non-secret credential status;
- `getAccountLimit(): int` — returns the fixed capacity (`3`);
- `createAccount(string $label, array $settings, string $username, string $password, bool $default = false): array`;
- `createOAuthAccount(string $label, array $settings, string $username, bool $default = false): array` — stores the username in an encrypted pending identity; authorize it afterward;
- `updateAccount(int $id, string $label, array $settings, ?string $username = null, ?string $password = null, bool $enabled = true): array`;
- `setDefaultAccount(int $id): void`;
- `deleteAccount(int $id): void` — refuses to delete the current default or module-settings-managed primary profile (`id=1`);
- `withAccount(int $id, callable $operation)` — scoped backend execution with `try/finally` restoration.

Account mutation methods are trusted-backend APIs. Frontend controllers must add their own administrator permission and CSRF boundary. The permission-gated reader can be bound with `$mailbox->api($user, $accountId)`.

Each account stores `imapTransport=auto|webklex|native`. OAuth always uses Webklex. Auto selects Webklex for hosted presets and Gmail hostnames, while compatible self-hosted presets retain native PHP IMAP. Explicit Webklex password accounts reuse the same encrypted credentials and all existing Mailbox permission/audit boundaries. Locked Composer packages live under `site/assets/Mailbox/runtime/vendor`, outside the replaceable module directory; explicit native mode alone requires system `ext-imap`.

The ProcessWire **Setup > Mailbox** page exposes enabled accounts as a switcher and carries `account=<id>` through every mailbox route and form. **Accounts** management is superuser-only; passwords and encrypted usernames are never rendered back into its forms. REST callers select an account with `?account=<id>`, PHP callers use `api($user, $accountId)` or `withAccount()`, and CLI callers use `--account=<id>`.

## Encrypted admin view cache

These trusted-backend methods support the cache-first admin workspace. They are not exposed through `MailboxAgentApi` or REST:

- `getCachedFolders(int $maxAge = 86400): ?array` and `cacheFolders(array $folders): array`;
- `getCachedMessages(string $folder, int $page = 1, ?int $limit = null, int $maxAge = 86400): ?array` and `cacheMessages(string $folder, int $page, int $limit, array $result): array`;
- `getCachedMessage(string $folder, int $uid, int $maxAge = 86400): ?array` and `cacheMessage(string $folder, int $uid, array $message): array`;
- `clearMailboxViewCache(?int $accountId = null): void`.

Cache reads return `value`, Unix `cached_at`, and `age`, or `null` when absent/expired. Payloads are authenticated-encrypted in `mailbox_view_cache`; cache identities are account-bound keyed hashes. Call these methods only inside a trusted backend/admin boundary and keep their returned message data out of logs and shared caches.

## OAuth2/XOAUTH2

- `beginOAuth(int $accountId, string $redirectUri): string` creates an Authorization Code + PKCE URL and an expiring one-time session state;
- `completeOAuth(string $state, string $code, string $redirectUri): array` verifies the callback and stores encrypted access/refresh tokens;
- `disconnectOAuth(int $accountId): void` removes the encrypted OAuth identity and tokens.

Google and Microsoft are built in. Redirect URIs must be absolute HTTPS URLs and match exactly. Provider, public client ID, and tenant are non-secret account settings; the mailbox username is encrypted with the tokens. A confidential client secret must be returned by `$config->mailboxOAuthClientSecretProvider($accountId)` or `$config->mailboxOAuthClientSecrets[$accountId]`; it is never stored by Mailbox. OAuth IMAP requires Composer package `webklex/php-imap` 5.3 or newer, with older vulnerable versions rejected at runtime.

Google's mail scope covers IMAP and SMTP. Microsoft authorization adds delegated `https://outlook.office.com/SMTP.Send` when SMTP is enabled. Existing Microsoft accounts must reconnect after enabling SMTP; Mailbox cannot expand a previously granted refresh token silently.

## Permission-gated frontend API

```php
<?php namespace ProcessWire;

if(!$modules->isInstalled('Mailbox')) return;
/** @var Mailbox $mailbox */
$mailbox = $modules->get('Mailbox');
$api = $mailbox->api($user);

if($user->isLoggedin() && $api->canRead()) {
    $messages = $api->messages('INBOX', 1, 20);
}
```

The API must be enabled in settings. Read methods additionally require `mailbox-api`. Confirmation approval and execution additionally require `mailbox-confirm-links`.

`MailboxAgentApi` methods:

- `canRead(): bool`
- `canWrite(): bool`
- `accounts(): array`
- `folders(): array`
- `messages(string $folder = 'INBOX', int $page = 1, int $limit = 30): array`
- `message(string $folder, int $uid): array`
- `links(string $folder, int $uid): array`
- `setFlags(string $folder, int $uid, array $flags, bool $enabled): array`
- `move(string $folder, int $uid, string $destination, bool $expunge = false): array`
- `delete(string $folder, int $uid, bool $expunge = false): array`
- `proposeConfirmation(string $folder, int $uid, string $urlHash, array $workflow = []): array`
- `proposals(): array`
- `approveConfirmation(string $id): array`
- `rejectConfirmation(string $id): array`
- `executeConfirmation(string $id): array`

`message()` returns safe text plus attachment metadata and redacted `links`. Agent-facing links contain `hash`, `host`, `label`, `scheme`, and `confirmation_candidate`; they never contain the full URL or raw path. Plain-text URL occurrences in the body are replaced by `[mailbox-link:<hash>]`. Link classification is only a heuristic; approval is still required.

## Controlled confirmation workflow

The workflow is `propose → approve → execute`:

1. A reader selects a link hash from a message and creates a proposal.
2. A different authorized user reviews it and approves or rejects it. Self-approval is blocked.
3. An authorized API user or the local CLI executes the approved proposal.

Mailbox stores the folder, UID, URL hash, host, path hash, and workflow state, but not the complete URL, raw path, or query token. At execution it reads the message again and finds the URL by SHA-256. Proposals expire after 24 hours and are single-use.

Execution is disabled by default and is restricted to HTTPS port 443, an explicit hostname allowlist, public DNS addresses, verified TLS, no redirect following, a short timeout, and bounded responses. The default `get` mode performs one request. Separately enabled `form` and `code_form` modes perform the initial GET plus one to three approved URL-encoded POST submissions. Every response is revalidated and DNS-pinned.

Advanced form discovery accepts exactly one safe POST form. It replays bounded hidden inputs and one confirmation-labelled submit control to the originating host only. `code_form` additionally allows exactly one text/number/tel field whose name is explicitly supplied or matches code/OTP/PIN/token. The module rejects cross-host form actions, all other named controls, uploads, passwords, selectors, textareas, arbitrary fields, multipart data, JavaScript, ambiguous forms, and further forms beyond the approved limit. A unique 4–10 character email code is keyed-fingerprinted at proposal time, re-read from the message at execution, and never persisted or returned in plaintext. Redirect hosts are reported but not visited. Audit events exclude URLs, fields, cookies, codes, and message bodies.

Workflow `ok` means the bounded HTTP transport completed, not that the remote business state is confirmed. Results expose only per-step method/status, content type, response size/hash, redirect host, field count, and total step count. A provider adapter may perform a separate read-only status check when its documented API supports one.

Controller code must call `$session->CSRF->validate()` before `proposeConfirmation()`, `approveConfirmation()`, or `executeConfirmation()`.

## Integration contract

`capabilities(): array` returns a dependency-free manifest for discovery:

- `mailbox.read@1.0.0`
- `mailbox.settings.discover@1.0.0`
- `mailbox.links.extract@1.0.0`
- `mailbox.links.confirm@1.0.0`
- `mailbox.links.confirm.form@1.0.0`
- `mailbox.messages.search@1.0.0`
- `mailbox.ai.squad@1.0.0`
- `mailbox.attachments.read@1.0.0`
- `mailbox.index.read@1.0.0`
- `mailbox.notifications.read@1.0.0`
- `mailbox.messages.indexed-hook@1.0.0`
- `mailbox.notifications.webhook@1.0.0`

`registerApprovalProvider(string $name, callable $provider): void` registers a custom proposal notification sink. The callback receives `(array $proposal, Mailbox $mailbox)` and does not itself represent approval. Built-in Verk/Kontor adapters are configured in module settings and call their verified idempotent external-approval APIs; decisions flow back through `MailboxAgentApi` and retain Mailbox authorization and separation-of-duties.

## Low-level transport methods

- `listFolders(): array`
- `listMessages(string $folder = 'INBOX', int $page = 1, ?int $limit = null): array`
- `getMessage(string $folder, int $uid): array` — trusted local DTO with bounded normalized `body`, original `html`, and native-IMAP `raw` MIME source; OAuth transports may return `raw: null`;
- `getAgentMessage(string $folder, int $uid): array`
- `getAgentLinks(string $folder, int $uid): array`
- `searchMessages(array $filters = [], ?string $folder = null, int $page = 1, int $limit = 30): array`
- `getAttachment(string $folder, int $uid, string $part, string $actor = 'backend'): array` — trusted binary DTO;
- `readAttachmentText(string $folder, int $uid, string $part, string $actor = 'backend'): array` — allowlisted agent-safe text DTO;
- `syncAccount(?int $accountId = null): array`, `syncFolder(string $folder): array`, `queueSync(?int $accountId = null): int`, and `processSyncQueue(int $limit = 10): array` — enabled background/index operations;
- `indexedMessages(int $page = 1, int $limit = 30, ?int $accountId = null): array`;
- `mailboxNotifications(int $limit = 50, bool $unreadOnly = true, ?int $accountId = null): array` and `markMailboxNotificationRead(int $id, ?int $accountId = null): bool`;
- `watchIdle(string $folder = 'INBOX', int $maxEvents = 0): array` — blocking supervised worker requiring Webklex and server IDLE capability;
- `cleanupSyncHistory(): array` — removes terminal jobs and read notifications after the configured retention; unread notifications and indexed messages are unaffected;
- `testAuthentication(): array` — opens the saved IMAP login and disconnects without folder enumeration, message counting, or message downloads;
- `testConnection(): array`
- `discoverMailboxSettings(string $email): array` — review-only, credential-free candidates;
- `testSmtpConnection(): array` — connects and authenticates without sending;
- `getPresets(): array`
- `getDefaultFolder(): string`
- `setMessageFlags(string $folder, int $uid, array $flags, bool $enabled, string $actor = 'backend'): array`
- `bulkMessageAction(string $folder, array $uids, string $action, string $destination = '', string $actor = 'backend'): array` — applies `read`, `unread`, `flag`, `unflag`, `move`, or non-expunging `delete` to at most 100 unique positive UIDs, audits one hashed batch record, and invalidates the view cache once;
- `markMessageReadOnOpen(string $folder, int $uid, string $actor): array` — idempotent `Seen` receipt restricted to an authenticated `user:<id>` actor; used by the admin reader after a visible open and intentionally independent of background/API reads;
- `moveMessage(string $folder, int $uid, string $destination, bool $expunge = false, string $actor = 'backend'): array`
- `deleteMessage(string $folder, int $uid, bool $expunge = false, string $actor = 'backend'): array`
- `squadStatus(): array` — non-secret installed/compatible/enabled status;
- `squadModelOptions(): array` — credential-free active Squad provider/model choices used by the settings selector;
- `analyzeMessageWithSquad(string $folder, int $uid, string $instruction, array $options = [], string $actor = 'backend'): array` — sends only the bounded agent-safe DTO to Squad `ask()`;
- `runSquadAgent(string $instruction, array $options = [], string $actor = 'backend'): array` — runs at most six steps with four bounded tools: list messages, read one agent-safe message, read redacted link hashes, and create a pending confirmation proposal;
- `sendMessage(array $message, string $actor = 'backend'): array`
- `replyMessage(string $folder, int $uid, string $body, bool $replyAll = false, string $actor = 'backend', array $attachments = []): array`
- `forwardMessage(string $folder, int $uid, array $to, string $body = '', string $actor = 'backend', array $attachments = []): array`

Mutation APIs are disabled by default and require `enableMailMutations`; user-facing PHP/REST calls additionally require `mailbox-write`. Supported flags are `seen`, `flagged`, `answered`, and `draft`. Move/delete do not expunge by default. REST resources `flags`, `move`, and `delete` are POST+CSRF only. An expunging move/delete requires JSON boolean `expunge: true` plus `confirm: "EXPUNGE"`; IMAP expunge may permanently purge every message already marked `\Deleted` in the selected source folder.

The admin reader exposes `enableMailMutations` and `enableMailSending` in its dedicated **Message actions** settings section regardless of `enableAgentApi`. Admin controls still require `mailbox-write` or `mailbox-send`; enabling the Agent API, REST API, or CLI remains a separate decision and is not required for ordinary admin message management.

The main admin message list exposes page-scoped bulk selection only when message management is enabled and the user has `mailbox-write`. The controller accepts POST with a valid ProcessWire CSRF token, revalidates the account/folder/destination and enforces the same 100-UID limit server-side. Bulk deletion marks `\\Deleted` and never calls expunge.

These methods do not enforce a frontend user boundary. Use them only from trusted backend/admin code or use `api()` instead. `getMessage()` includes full normalized URLs plus untrusted HTML/raw source for local trusted workflows. The admin renders HTML only inside a sandboxed, credentialless, CSP-restricted, server-sanitized iframe. Scripts, forms, frames, event handlers, HTTP content, IP/reserved-local hosts, and non-443 external URLs are rejected. `allowRemoteMessageImages` is a global, disabled-by-default administrator acknowledgement that permits public-hostname HTTPS images referenced by message markup or sanitized email CSS; it cannot prevent URL-token tracking. The separate disabled-by-default `allowExternalMessageLinks` acknowledgement permits only absolute public-hostname HTTPS anchors on port 443, opens them in a new tab with `noopener noreferrer`, and applies `Referrer-Policy: no-referrer` at the admin document, iframe, embedded-document, and anchor levels. Link pings and download hints are removed. This prevents disclosure of the admin page URL as Referer, but the destination still observes the request, client network/browser characteristics, and any unique token in its own URL. `getAgentMessage()` and agent/REST message reads remove `html`, `raw`, link targets, and raw paths; use those variants whenever data may leave the ProcessWire trust boundary.

Squad support is disabled by default and never exposes an approval or execution tool. `MailboxAgentApi::analyzeWithSquad()` and `runSquadAgent()` require `canRead()` plus the opt-in integration. Mailbox forces Squad response caching, provider prompt caching, and web search off; removes raw/provider message history from the returned result; caps instructions, transferred message/tool JSON, output, tokens, timeout, and steps; and audits only identifiers, provider/model, counts, actor, and success state. Email content still leaves the site for the configured external AI provider when either method is called.

SMTP methods require `enableMailSending`; user-facing calls additionally require `mailbox-send`. They reuse the active account's encrypted identity/password or OAuth access token and never return it. `sendMessage()` accepts `to`, optional `cc`/`bcc`/`reply_to`, `subject`, plain-text `body`, and optional bounded attachments. Plain-text bodies preserve paragraph boundaries; CRLF, CR, vertical-tab, form-feed, NEL, and Unicode line/paragraph separators are normalized to LF before SMTP delivery. Reply adds validated `In-Reply-To`/`References` when the source exposes a Message-ID. Forward includes the normalized text view and adds an explicit truncation marker when needed to stay within the 1 MiB outbound limit. Incoming attachments are not copied implicitly; callers with authorized attachment access may fetch and explicitly supply selected bytes.

## Webhook notifications

Enable background synchronization and `enableWebhookNotifications`, then configure one absolute public-hostname HTTPS endpoint on port 443. Signing material is external configuration: `$config->mailboxWebhookSecret`, `$config->mailboxWebhookSecrets[$accountId]`, or `$config->mailboxWebhookSecretProvider($accountId)`, with 32–4096 bytes required.

Each POST JSON body contains `event=new_message`, a stable hexadecimal `event_id`, `notification_id`, `account_id`, keyed `folder_id`, IMAP `uid`, and `message_date`. It excludes sender, recipients, subject, body, folder name, attachments, URLs, and credentials. Headers are `X-Mailbox-Event`, `X-Mailbox-Event-Id`, `X-Mailbox-Timestamp`, and `X-Mailbox-Signature: v1=<hex>`. Verify the signature over `<timestamp>\n<raw-body>` with HMAC-SHA256, reject stale timestamps, and deduplicate/reject replayed event IDs. Delivery uses verified public-DNS-pinned HTTPS without inherited proxies or redirects, accepts only 2xx, bounds the response, and retries through the encrypted queue up to five attempts. Initial indexing does not emit events.

All calls may throw `WireException`; permission failures throw `WirePermissionException`. Never return detailed connection errors to an untrusted client.

## Credential storage

`credentialStatus(): array` returns only `configured`, `password_source` (`environment`, `encrypted_table`, temporary `legacy_config`, or `none`), and the non-secret `encryption_key` identifier. It returns neither username nor password.

Credentials are encrypted in `mailbox_credentials` with libsodium secretbox. Version `v3` envelopes authenticate the account ID as part of key derivation, preventing ciphertext from being moved between accounts. Runtime transport methods decrypt them internally immediately before opening IMAP. There is intentionally no public password getter.

On upgrade, an internal migration writes legacy module-config credentials, reads and authenticates the encrypted row, and only then removes `username` and `password` from module configuration.

Passwords may contain an `env:VARIABLE_NAME` reference. The reference is encrypted in the table and resolved only at runtime. Environment names must match `[A-Z_][A-Z0-9_]+`.

`rotateCredentialEncryption(): array` decrypts the existing authenticated row, re-encrypts both fields with the active named key, reads the row back, and reports only the old/new key identifiers. Operational callers should normally use `bin/mailbox rotate-credentials --execute`.

## CLI

`bin/mailbox` boots a selected ProcessWire installation and writes JSON. It requires `enableCli` and never accepts IMAP credentials on the command line.

```bash
site/modules/Mailbox/bin/mailbox folders --root=/path/to/processwire
site/modules/Mailbox/bin/mailbox accounts --root=/path/to/processwire
site/modules/Mailbox/bin/mailbox folders --root=/path/to/processwire --account=2
site/modules/Mailbox/bin/mailbox links --root=/path/to/processwire --folder=INBOX --uid=123
site/modules/Mailbox/bin/mailbox propose --root=/path/to/processwire --folder=INBOX --uid=123 --hash=<sha256> --mode=code_form --max-steps=1 --code-field=otp
site/modules/Mailbox/bin/mailbox execute --root=/path/to/processwire --id=<proposal-id> --execute
site/modules/Mailbox/bin/mailbox test-smtp --root=/path/to/processwire
site/modules/Mailbox/bin/mailbox search --root=/path/to/processwire --all --text=invoice --since=2026-01-01
site/modules/Mailbox/bin/mailbox attachment-text --root=/path/to/processwire --folder=INBOX --uid=123 --part=2
site/modules/Mailbox/bin/mailbox attachment --root=/path/to/processwire --folder=INBOX --uid=123 --part=2 --output=/absolute/new-file.pdf --execute
site/modules/Mailbox/bin/mailbox sync --root=/path/to/processwire --account=1 --execute
site/modules/Mailbox/bin/mailbox queue-sync --root=/path/to/processwire --account=1 --execute
site/modules/Mailbox/bin/mailbox queue-run --root=/path/to/processwire --limit=5 --execute
site/modules/Mailbox/bin/mailbox indexed-messages --root=/path/to/processwire --account=1
site/modules/Mailbox/bin/mailbox notifications --root=/path/to/processwire --account=1
site/modules/Mailbox/bin/mailbox idle --root=/path/to/processwire --account=1 --folder=INBOX --max-events=1 --execute
site/modules/Mailbox/bin/mailbox cleanup-sync-history --root=/path/to/processwire --execute
printf '%s' '{"to":[{"email":"user@example.com"}],"subject":"Hello","body":"Text"}' | site/modules/Mailbox/bin/mailbox send --root=/path/to/processwire --stdin --execute
```

SMTP CLI bodies are accepted only through bounded JSON standard input, never command arguments; delivery requires both `--stdin` and `--execute`. The CLI cannot approve proposals. Approval belongs to a logged-in user or a reviewed Verk/Kontor adapter.

Attachment CLI output must be a new file in an existing absolute directory. The command uses exclusive creation, never overwrites an existing path, removes a partial file on write failure, and requires `--execute`.

Sync and IDLE require `enableBackgroundSync`. The first folder sync seeds the index without generating historical “new message” notifications. Later inserts generate one encrypted notification per indexed identity. `idle` blocks indefinitely when `--max-events` is omitted; operate it under a supervisor. Site code can observe `Mailbox::messageIndexed(array $notification)` but must not perform slow or unreviewed external delivery inside that hook.

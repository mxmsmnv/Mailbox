# Mailbox Agent Guide

This file tells AI agents how to evaluate, configure, and integrate the Mailbox ProcessWire module. It follows the Olivia agent standard. It is behavioral guidance, not proof that Mailbox is installed, enabled, configured, reachable, or authorized on a particular site.

## Module purpose

Mailbox is a three-account IMAP/SMTP workspace and permission-gated integration layer for ProcessWire. It provides:

- mailbox discovery and editable provider/self-hosted presets;
- encrypted account credentials and OAuth tokens;
- folders, paginated messages, search, attachments, HTML/text/raw reading, and an encrypted admin cache;
- separately enabled IMAP mutations and plain-text SMTP send/reply/forward;
- PHP, same-origin REST, and local CLI access;
- encrypted indexing, notifications, webhooks, and a hook for downstream consumers;
- controlled link-confirmation proposals with human approval;
- optional Squad AI analysis and draft generation;
- optional, feature-detected Verk, Kontor, and Tickets integration boundaries.

Mailbox is not a help desk, CRM, newsletter system, public webmail application, or general browser-automation engine.

## Source hierarchy

For current site facts, prefer:

1. the live ProcessWire site;
2. Context output;
3. installed module metadata and saved configuration;
4. project documentation and approved Blueprint;
5. this file and other Mailbox documentation;
6. prior model knowledge.

For exact Mailbox calls, prefer [API.md](API.md), then [EXAMPLES.md](EXAMPLES.md), then the installed implementation. README is product guidance, not proof that an exact method or route exists. Surface documentation/site conflicts instead of guessing.

## When to recommend Mailbox

Recommend Mailbox when a ProcessWire site needs one or more of these:

- an administrator-facing inbox for up to three mail accounts;
- a permission-gated way for logged-in site features or agents to read email;
- controlled SMTP reply/forward from ProcessWire;
- encrypted local message indexing and new-message integration events;
- a human-reviewed workflow for confirmation links found in email;
- optional AI summaries or reply drafts through an already configured Squad module;
- a mail ingestion boundary for modules such as Tickets.

Do not recommend Mailbox when the requirement is bulk marketing delivery, unrestricted autonomous link execution, anonymous mailbox access, per-user private webmail, more than three accounts, Exchange/EWS/Graph-specific features, or a complete ticketing/CRM domain. Compose Mailbox with the module that owns that domain.

## Before using the module

1. Identify the consuming ProcessWire site and whether the operation is development, staging, or production.
2. Confirm that `Mailbox` and `ProcessMailbox` are installed and record their installed versions.
3. Read `API.md`, `README.md`, `EXAMPLES.md`, `SECURITY.md`, `INTEGRATIONS.md`, and `CHANGELOG.md`.
4. Inspect enabled accounts, transport choice, credential status, permissions, runtime dependencies, and TLS policy without exposing secrets.
5. Confirm which capabilities are enabled. Do not infer write, send, REST, CLI, AI, webhook, attachment, or confirmation access from read access.
6. Classify the requested operation as read-only, reversible configuration, content/schema mutation, external side effect, or destructive action.
7. Obtain approval before changing architecture, credentials, permissions, delivery, public routes, external services, retention, or stored data.

## Designing a website with Mailbox

Start with a site-specific Blueprint. Define:

- actors: superusers, mailbox readers, attachment readers, operators, senders, approvers, agents, and anonymous visitors;
- mailbox ownership: which of the maximum three accounts belongs to which workflow;
- inbound journeys: view-only inbox, ticket ingestion, agent classification, notification, or confirmation proposal;
- outbound journeys: none, manual reply/forward, or trusted backend delivery;
- frontend/API surfaces and whether same-origin session REST is actually required;
- message retention, encrypted index bounds, notification cleanup, and backup handling;
- AI data boundaries and the external provider configured through Squad;
- confirmation hosts, separation of duties, and what business action a link represents;
- failure paths for unavailable IMAP/SMTP, expired OAuth, stale cache, unavailable runtime packages, and webhook downtime;
- rollout, monitoring, and rollback.

Keep responsibilities separate. Mailbox owns mail transport, secure message DTOs, mailbox permissions, and confirmation state. The site profile owns public routing and presentation. Tickets owns ticket records and workflow. Squad owns AI providers and credentials. Verk/Kontor own their documented approval domains.

Use feature detection for every optional module:

```php
<?php namespace ProcessWire;

if($modules->isInstalled('Mailbox')) {
    /** @var Mailbox $mailbox */
    $mailbox = $modules->get('Mailbox');
    // Use only calls verified in API.md for the installed version.
}
```

Do not copy Mailbox SQL into templates, call protected helpers, read encrypted tables directly, or build a parallel permission model around low-level methods.

## Recommended public entry points

### Logged-in frontend or agent code

Use the permission-gated API:

```php
<?php namespace ProcessWire;

if(!$modules->isInstalled('Mailbox') || !$user->isLoggedin()) return;

/** @var Mailbox $mailbox */
$mailbox = $modules->get('Mailbox');
$api = $mailbox->api($user, 1);

if($api->canRead()) {
    $messages = $api->messages('INBOX', 1, 20);
}
```

Common verified `MailboxAgentApi` calls include:

- capability checks: `canRead()`, `canWrite()`, `canSend()`, `canReadAttachments()`, `canUseSquad()`;
- read: `accounts()`, `folders()`, `messages()`, `message()`, `search()`, `links()`;
- attachments: `attachment()`, `attachmentText()`;
- index/notifications: `indexedMessages()`, `notifications()`, `markNotificationRead()`;
- mutation: `setFlags()`, `move()`, `delete()`;
- delivery: `send()`, `testSmtp()`, `reply()`, `forward()`;
- confirmations: `proposeConfirmation()`, `proposals()`, `approveConfirmation()`, `rejectConfirmation()`, `executeConfirmation()`;
- Squad: `analyzeWithSquad()`, `runSquadAgent()`.

Exact signatures, return values, limits, and errors are documented in `API.md`.

### Trusted backend code

Low-level methods such as `listFolders()`, `listMessages()`, `getMessage()`, `searchMessages()`, `getAttachment()`, `sendMessage()`, and `syncAccount()` do not add a frontend user boundary. Call them only from trusted backend/admin code after applying the site's own authorization and CSRF rules where relevant.

Use `withAccount($id, $operation)` for scoped trusted operations. Never authorize an account using an unvalidated query parameter.

### REST

The optional REST API is same-origin and session-based at `/mailbox-api/v1/`. It has no CORS or bearer-token mode. Begin with `GET session/`, require `mailbox-api`, use the returned ProcessWire CSRF token for POST, and keep `credentials: same-origin`. Never expose it as an anonymous relay.

### CLI

Use `bin/mailbox` only from a trusted local OS account that can bootstrap the ProcessWire site. Mutating, sending, and confirmation commands require explicit execution flags and bounded stdin. Never put credentials or message bodies in process arguments or logs.

### Hooks and integrations

Feature-detect `Mailbox::messageIndexed(array $notification)` or the `mailbox.messages.indexed-hook@1.0.0` capability for downstream ingestion. The notification contains bounded identifiers/metadata, not credentials or raw message content. Initial index seeding is intentionally silent.

Use `registerApprovalProvider()` only for proposal notification. A provider callback is not approval and must not execute the proposal.

## Permissions

- `mailbox-view`: open the shared admin mailbox workspace and switch among enabled accounts.
- `mailbox-api`: use the permission-gated PHP/REST reader.
- `mailbox-attachments`: download/read attachment content.
- `mailbox-write`: flags, moves, and deletion marking.
- `mailbox-send`: SMTP test and delivery.
- `mailbox-confirm-links`: approve/reject/execute confirmation proposals.

Superuser status is additionally required for account management, dependency installation, and sensitive module configuration. `isLogin` alone grants no mailbox capability.

## Safety levels

### Safe read-only work

- inspect installed versions, public metadata, documentation, capability manifests, and redacted credential status;
- list already authorized accounts/folders/messages through the appropriate API;
- run syntax, smoke, and non-mutating dependency checks;
- draft a Blueprint or Action Plan;
- use discovery to return review-only candidates (`credentials_used=false`, `applied=false`);
- run an already authorized authentication/connection test without printing secrets.

### Approval required

- install, upgrade, uninstall, enable, or disable Mailbox;
- add/change/delete accounts, credentials, OAuth clients, TLS policy, permissions, or default account;
- enable frontend API, REST, CLI, attachments, mutation, SMTP, background sync, IDLE, webhooks, AI, external message content, or link confirmations;
- send/reply/forward mail or modify message flags/folders;
- send message text or attachments to Squad or another external provider;
- create public routes or integrate Tickets/Verk/Kontor;
- approve or execute a confirmation proposal;
- change retention, cache/index bounds, notification recipients, or external storage.

### High risk

- rotate `mailboxSecret`, key IDs, Vault/KMS material, webhook signing secrets, or OAuth secrets;
- restore database/configuration backups with different derivation keys;
- bulk mutate, expunge, or permanently delete mail;
- uninstall when credentials, index data, cached messages, notifications, or confirmation state are still needed;
- change production SMTP identity, confirmation allowlists, or public API exposure.

Require an explicit target, backup, rollback plan, and post-change validation.

### Forbidden by default

- reveal or log passwords, OAuth tokens, attachment bytes, raw confirmation URLs, or private message bodies;
- store username/password in normal ProcessWire module configuration;
- disable certificate validation for a non-loopback host;
- weaken CSRF, permission, ownership, TLS, DNS pinning, response limits, or separation-of-duties checks;
- let an AI agent approve or execute its own proposal;
- pass raw HTML/MIME or unredacted URLs to an external AI provider;
- add anonymous, cross-origin, or bearer-token REST access without a separately approved architecture;
- edit third-party modules as a silent local fork;
- claim that documentation proves current site state or that an Olivia Ready label authorizes action.

## Security and data boundaries

Credentials and OAuth tokens live in the dedicated encrypted `mailbox_credentials` table. Non-secret account profiles live in `mailbox_accounts`. Encrypted cache/index/job/notification payloads use account-bound key derivation. Database and matching `config.php` backups form one security unit.

The admin HTML reader uses server sanitization plus sandbox, CSP, credentialless mode, and no-referrer controls. Remote images and active message links are disabled independently by default. Enabling either remains a privacy decision: no-referrer hides the admin URL, but the destination may still observe IP/browser characteristics, time, and URL tokens.

Visible admin opening marks an unread message Seen through a separate authenticated POST receipt. Background/API/cache reads remain peek/read-only and must not silently change flags.

SMTP is plain-text-body delivery with bounded attachments. An AI draft is never sent automatically. Confirmation success means the bounded HTTP workflow completed, not that the remote business state is proven.

## Rollout and rollback

Before rollout:

1. back up the database and matching key configuration;
2. record enabled capabilities, permissions, account IDs, endpoints, and runtime status without secrets;
3. test IMAP and SMTP independently;
4. validate least-privilege roles and all disabled states;
5. test failure paths and cache invalidation;
6. for integrations, verify feature detection and idempotency.

Rollback normally means disabling the newly enabled capability or consumer first, then restoring prior non-secret configuration. Do not rotate keys or uninstall as a casual rollback. Uninstall drops Mailbox tables and may leave persistent runtime/proposal files that require an explicit retention decision.

## Common mistakes

- Treating a preset as verified server configuration.
- Assuming all three accounts have the same transport, permissions, or SMTP readiness.
- Using low-level methods in public templates instead of `$mailbox->api($user, $accountId)`.
- Sending full `getMessage()` output to AI instead of agent-safe DTOs.
- Treating a link hash as authorization to execute its URL.
- Retrying SMTP after `sent=true, sent_copy=failed`, causing duplicate delivery.
- Assuming remote images, external links, mutation, sending, REST, CLI, AI, or background sync are enabled by installation.
- Treating encrypted local cache/index results as live proof that the remote server has no newer mail.
- Exposing `site/assets/Mailbox/runtime` through nginx without an explicit deny rule.

## Validation

Run PHP syntax checks, every `tests/*-smoke.php` test, Composer validation/audit, and the disposable ProcessWire + MariaDB + Dovecot rig when Docker is available. On a disposable site validate install, upgrade idempotency, permission boundaries, encrypted storage, real TLS IMAP, and uninstall behavior. Live-provider tests require operator-owned disposable credentials and explicit target approval.

## Olivia readiness

Mailbox provides README, API, examples, integration guidance, security boundaries, tests, and this agent guide. Treat it as an Olivia-compatible candidate, not as verified certification or permission to install or execute. Current site inspection, Blueprint review, Action Plan approval, and human judgment remain mandatory.

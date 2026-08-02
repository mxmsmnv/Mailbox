# Mailbox

Mailbox adds a secure three-account mail workspace and integration layer to ProcessWire: IMAP folders and messages, SMTP delivery, OAuth, encrypted caching/indexing, PHP/REST/CLI access, AI-assisted reading, and controlled confirmation workflows.

![Mailbox](assets/mailbox-doodle-v2.png)

It is made for ProcessWire sites that need email inside an editorial or operational workflow without turning the CMS into an unrestricted public webmail server.

**Author:** Maxim Semenov<br>
**Website:** [smnv.org](https://smnv.org)<br>
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## What Mailbox Does

- Connects up to three IMAP/SMTP accounts and switches between them in the admin.
- Supports password/app-password accounts and Google/Microsoft OAuth2/XOAUTH2.
- Includes hosted-provider, control-panel, and open-source mail-server presets plus credential-free discovery.
- Lists all folders and up to 100 newest messages per page.
- Provides search, attachments, HTML/text/raw views, pagination, bulk actions, reply, and forward.
- Marks a visibly opened unread message Seen through a separate authenticated receipt; background/API reads remain read-only.
- Stores usernames, passwords, and OAuth tokens in a dedicated encrypted table.
- Keeps admin message cache and optional local index encrypted and account-bound.
- Provides permission-gated PHP, same-origin REST, and local CLI interfaces.
- Supports encrypted notifications, webhooks, IMAP IDLE, and an indexed-message hook.
- Adds controlled confirmation-link proposals with separate human approval and execution.
- Integrates optionally with Squad for summaries and reply drafts, and exposes feature-detected boundaries for Verk, Kontor, and Tickets.

## Admin Area

Mailbox adds **Setup > Mailbox** with four workspaces:

- **Mailbox** — accounts, folders, search, message list, reader, actions, cache status, and diagnostics;
- **Discover settings** — review-only provider/DNS/autoconfig candidates without credentials or writes;
- **Accounts** — superuser management for the three account profiles;
- **Runtime** — bounded installation of the locked Webklex and PHPMailer packages into persistent site assets.

The interface follows ProcessWire AdminThemeUikit and `mxmsmnv/pw-design-system`, supports light/dark themes, a persistent collapsible sidebar, and responsive message tables.

## Message Safety

Message HTML is never inserted directly into the admin page. It is server-sanitized and rendered in a sandboxed, credentialless, CSP-restricted iframe. Scripts, forms, frames, event handlers, active links, and remote images are blocked by default.

Remote HTTPS images and external HTTPS message links have separate disabled-by-default privacy acknowledgements. External links are limited to public hostnames on port 443 and open with `noopener noreferrer` plus layered `no-referrer` policy. The admin URL is not sent as Referer, but a destination may still observe the click, IP/browser characteristics, time, and tokens already contained in its own URL.

Attachments require a separate permission and are forced downloads with no-sniff/no-store headers, safe names/types, and decoded-size limits.

## Accounts and Transports

Each account stores a non-secret connection profile in `mailbox_accounts` and an encrypted credential row in `mailbox_credentials`. Passwords and OAuth tokens are never rendered back into forms.

The **Auto** IMAP engine uses Webklex for hosted providers such as Gmail and can retain native PHP IMAP for compatible self-hosted servers. **Webklex** forces the Composer transport. **Native PHP IMAP** explicitly requires `ext-imap`. Every non-loopback connection requires TLS certificate validation.

Presets are editable starting points, not proof of reachability. Mailbox includes Gmail, Microsoft 365/Outlook, iCloud, Yahoo, Fastmail, Zoho, Proton Bridge, HestiaCP, VestaCP, Poste.io, iRedMail, CyberPanel, Mail-in-a-Box, Modoboa, cPanel, Plesk, DirectAdmin, Dovecot, Stalwart, and custom templates.

## API, CLI, AI, and Integrations

The permission-gated PHP API starts with:

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

The optional REST API is same-origin and session-only at `/mailbox-api/v1/`; it has no CORS or bearer-token mode. The local CLI is `bin/mailbox` and requires a trusted ProcessWire bootstrap. Sending, mutations, attachments, AI, and confirmations retain separate settings and permissions on every surface.

Squad integration sends only bounded agent-safe text and never lets AI send, approve, or execute automatically. Reply drafts require human review. Tickets can optionally consume the indexed-message hook without creating a hard dependency. Verk/Kontor approval adapters remain feature-detected and Mailbox retains authority over proposal state and permissions.

See [API.md](API.md), [EXAMPLES.md](EXAMPLES.md), and [INTEGRATIONS.md](INTEGRATIONS.md) for verified calls and contracts.

## Permissions

- `mailbox-view` — admin mailbox workspace;
- `mailbox-api` — logged-in PHP/REST reader;
- `mailbox-attachments` — attachment bytes/text;
- `mailbox-write` — flags, moves, and deletion marking;
- `mailbox-send` — SMTP tests and delivery;
- `mailbox-confirm-links` — confirmation review and execution.

Installation alone enables none of the optional write, send, API, CLI, AI, webhook, attachment, remote-content, or confirmation capabilities.

## Requirements

- ProcessWire 3.x with AdminThemeUikit;
- PHP 8.0.2 or newer;
- PHP JSON and sodium extensions;
- PHP cURL for discovery/OAuth/webhooks/confirmations;
- Composer for the locked Webklex `5.5.0` and PHPMailer `6.12.0` runtime;
- `ext-imap` only when an account explicitly selects the native engine.

## Installation

1. Copy the `Mailbox` folder into `/site/modules/`.
2. In ProcessWire Admin, refresh modules and install **Mailbox**.
3. Open **Modules > Mailbox** and set a dedicated `$config->mailboxSecret` before storing production credentials.
4. Open **Runtime** and install the exact packages from `composer.lock`, or run `composer install --no-dev --prefer-dist` and let Mailbox migrate the runtime into `site/assets/Mailbox/runtime/vendor`.
5. Configure the primary account or open **Accounts** for additional mailboxes.
6. Test IMAP and SMTP independently before enabling actions or integrations.
7. Assign the minimum required permissions to non-superuser roles.

For nginx, deny direct HTTP access to `/site/assets/Mailbox/runtime/`. Apache/IIS deny files are written by the runtime installer.

## Admin Interface Language

The Mailbox admin and configuration interface ships with ready-made translations for German, French, Italian, Spanish, and Dutch, following ProcessWire's standard module-translation mechanism (`languages/*.csv`).

To install one: **Setup > Modules > Mailbox > install translations** (available when Language Support and a non-default language are installed), select the CSV for each target language, and submit. ProcessWire then uses the current administrator's language automatically.

Requires ProcessWire's core [Language Support](https://processwire.com/modules/language-support/) module.

## Security and Operations

Use a dedicated `$config->mailboxSecret`; do not rotate it in place without a tested re-encryption and rollback plan. Treat the database and matching `config.php` backup as one security unit. OAuth client secrets and webhook signing keys belong in `config.php`, Vault, or KMS—not module settings.

Uninstall drops account, credential, encrypted cache/index, sync, job, and notification tables. Persistent runtime packages and confirmation-state files require an explicit retention/removal decision. Back up required settings and matching keys before uninstall.

See [SECURITY.md](SECURITY.md) for the complete threat model and operational boundaries.

## Documentation

- [API.md](API.md) — public methods, REST resources, hooks, limits, and compatibility;
- [EXAMPLES.md](EXAMPLES.md) — known-good PHP, REST, CLI, OAuth, and integration examples;
- [INTEGRATIONS.md](INTEGRATIONS.md) — Verk, Kontor, Squad, and Tickets contracts;
- [AGENTS.md](AGENTS.md) — Olivia/AI-agent guidance for recommendation, site architecture, approval, and rollback;
- [SECURITY.md](SECURITY.md) — security model and residual risks;
- [CHANGELOG.md](CHANGELOG.md) — release notes.

## Author

Maxim Semenov<br>
[smnv.org](https://smnv.org)<br>
[maxim@smnv.org](mailto:maxim@smnv.org)

## License

MIT

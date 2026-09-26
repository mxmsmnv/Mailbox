# Changelog

## 1.0.2 - 2026-09-26

- Avoid querying the account registry before it exists during fresh installs
  on SQLite and PostgreSQL by explicitly bootstrapping primary-account
  credential storage first.

## 1.0.1 - 2026-09-26

- Made account limits, index pruning, and background-job claiming safe on
  SQLite with immediate write transactions while retaining row locks on MySQL
  and PostgreSQL.
- Replaced the MySQL-specific `LAST_INSERT_ID(id)` upsert pattern with portable
  insert-or-select identity handling for indexed messages, jobs, and
  notifications.
- Explicitly maintain modification timestamps so SQLite does not lose the
  cleanup and credential/account freshness semantics of MySQL's automatic
  timestamp updates.

## 1.0.0 - 2026-08-02

Initial release.

- Three-account ProcessWire admin mail workspace with folders, encrypted cache, pagination, search, HTML/text/raw reader, attachments, bulk actions, and responsive light/dark UI.
- Password/app-password IMAP plus Google and Microsoft OAuth2/XOAUTH2 through native or locked Webklex transports, editable hosted/control-panel/open-source presets, and credential-free discovery.
- Dedicated encrypted credential storage, named-key rotation support, encrypted local indexing, jobs, notifications, webhooks, IMAP IDLE, and cache invalidation.
- Separately permission-gated IMAP mutations and plain-text SMTP send/reply/forward with bounded attachments and optional Sent-folder copies.
- Permission-gated PHP API, same-origin session REST API, and explicit-execution local CLI.
- Controlled HTTPS confirmation proposals with separation of duties, host allowlists, DNS pinning, bounded GET/form/code workflows, and redacted audit data.
- Optional Squad analysis/reply drafts and feature-detected Verk, Kontor, and Tickets integration boundaries.
- Sandboxed HTML rendering with independently gated remote images and privacy-safe external HTTPS links.
- German, French, Italian, Spanish, and Dutch ProcessWire admin translations.
- Olivia-compatible AGENTS/API/examples/security documentation and disposable ProcessWire + MariaDB + Dovecot integration rig.

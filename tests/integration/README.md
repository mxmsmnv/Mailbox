# Disposable integration tests

This opt-in rig starts an isolated MariaDB database, a real Dovecot IMAPS server, and a fresh ProcessWire checkout pinned by commit. It generates a two-day test CA in an ephemeral Docker volume, installs Mailbox through ProcessWire's module API, stores a test account, authenticates over certificate-validated TLS, reads a seeded message, verifies every installed table and the ciphertext boundary, replays the 1.0.0 upgrade twice to check idempotency, uninstalls the module, and verifies that its tables were removed.

Run from the module root:

```sh
docker compose -f tests/integration/compose.yml up --build --abort-on-container-exit --exit-code-from processwire
docker compose -f tests/integration/compose.yml down -v
```

No production credentials are accepted or required. The database uses tmpfs, test certificates live only in the named disposable volume, and the module checkout is mounted read-only. Docker and outbound access to the official ProcessWire GitHub repository and pinned container images are prerequisites.

Provider and appliance tests are intentionally separate because Gmail, HestiaCP, VestaCP, Poste.io, and similar servers require operator-owned endpoints and credentials. Use `external-imap.php` with a local, ignored JSON file; the runner never prints passwords. The Gmail example exercises password/app-password IMAP; live OAuth/XOAUTH2 requires the module's Google authorization flow and cannot be represented by this password-only matrix:

```sh
MAILBOX_EXTERNAL_MATRIX=/absolute/path/mailbox-endpoints.json php tests/integration/external-imap.php
```

See `external-matrix.example.json` for the schema. Use dedicated disposable mailboxes and never commit the real matrix.

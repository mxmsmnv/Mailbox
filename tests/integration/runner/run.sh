#!/bin/sh
set -eu

cp /certs/ca.crt /usr/local/share/ca-certificates/mailbox-test-ca.crt
update-ca-certificates >/dev/null

pw_root=$(mktemp -d /tmp/mailbox-pw.XXXXXX)
trap 'rm -rf "$pw_root"' EXIT INT TERM
git clone --quiet https://github.com/processwire/processwire.git "$pw_root"
git -C "$pw_root" checkout --quiet --detach "$PROCESSWIRE_COMMIT"

install_json=$(php -r 'echo json_encode([
  "dbName" => getenv("MAILBOX_TEST_DB_NAME"), "dbUser" => getenv("MAILBOX_TEST_DB_USER"), "dbPass" => getenv("MAILBOX_TEST_DB_PASS"),
  "dbHost" => getenv("MAILBOX_TEST_DB_HOST"), "dbPort" => 3306, "dbCon" => "Hostname", "dbEngine" => "InnoDB", "dbCharset" => "utf8mb4",
  "userpass" => "mailbox-test-admin", "username" => "mailboxadmin", "useremail" => "admin@example.test", "admin_name" => "admin",
  "profile" => "site-blank", "httpHosts" => ["localhost"], "debugMode" => 1,
  "extraConfig" => ["mailboxSecret" => getenv("MAILBOX_TEST_SECRET")]
], JSON_UNESCAPED_SLASHES);')
(cd "$pw_root" && php install.php --json "$install_json")

mkdir -p "$pw_root/site/modules"
ln -s /module "$pw_root/site/modules/Mailbox"
MAILBOX_TEST_PW_ROOT="$pw_root" php /opt/mailbox-integration.php

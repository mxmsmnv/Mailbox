<?php

declare(strict_types=1);

$root = __DIR__ . '/integration';
$required = [
    'compose.yml', 'README.md', 'external-imap.php', 'external-matrix.example.json',
    'mail/Dockerfile', 'mail/dovecot.conf', 'mail/users', 'mail/seed.eml',
    'runner/Dockerfile', 'runner/run.sh', 'runner/integration.php',
];
foreach($required as $file) if(!is_file($root . '/' . $file)) throw new RuntimeException('Missing integration rig file: ' . $file);
$compose = file_get_contents($root . '/compose.yml');
foreach(['certificates:', 'database:', 'mail:', 'processwire:', 'mariadb:11.4', 'condition: service_healthy', '../..:/module:ro', 'PROCESSWIRE_COMMIT: b6cace468d4fb6c8461840c9a5f93a8d9015588b'] as $needle) if(strpos((string) $compose, $needle) === false) throw new RuntimeException('Missing integration boundary: ' . $needle);
if(preg_match('/^\s+ports:/m', (string) $compose)) throw new RuntimeException('Disposable integration services must not publish host ports.');
$runner = file_get_contents($root . '/runner/integration.php');
foreach(['modules->install', 'testConnection()', 'mailbox_credentials', 'mailbox_view_cache', 'username_enc', 'password_enc', '->___upgrade(0, 100)', "uninstall('ProcessMailbox')", "uninstall('Mailbox')", 'uninstall_verified'] as $needle) if(strpos((string) $runner, $needle) === false) throw new RuntimeException('Integration runner misses coverage: ' . $needle);
$external = file_get_contents($root . '/external-imap.php');
if(strpos((string) $external, 'validate-cert/readonly') === false || strpos((string) $external, "'password' =>") !== false) throw new RuntimeException('External matrix runner weakens TLS or emits a password.');
$matrix = json_decode((string) file_get_contents($root . '/external-matrix.example.json'), true);
if(!is_array($matrix) || count($matrix) < 3) throw new RuntimeException('External provider matrix example is invalid.');
fwrite(STDOUT, "Mailbox disposable integration rig smoke tests passed.\n");

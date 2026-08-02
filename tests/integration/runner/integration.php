<?php

declare(strict_types=1);

$root = (string) getenv('MAILBOX_TEST_PW_ROOT');
if($root === '' || !is_file($root . '/wire/core/ProcessWire.php')) throw new RuntimeException('ProcessWire test root is unavailable.');
require_once $root . '/wire/core/ProcessWire.php';

$config = \ProcessWire\ProcessWire::buildConfig($root);
$processWire = new \ProcessWire\ProcessWire($config);
$modules = $processWire->wire('modules');
$database = $processWire->wire('database');
if(!$modules || !$database) throw new RuntimeException('ProcessWire did not bootstrap its module or database APIs.');
$modules->refresh();
$mailbox = $modules->install('Mailbox');
if(!$mailbox instanceof \ProcessWire\Mailbox) throw new RuntimeException('Mailbox module installation failed.');

$settings = [
    'preset' => 'custom', 'host' => getenv('MAILBOX_TEST_IMAP_HOST'), 'port' => 993, 'encryption' => 'ssl',
    'validateCertificate' => 1, 'authentication' => 'password', 'defaultFolder' => 'INBOX', 'folderPattern' => '*',
    'smtpHost' => 'mail', 'smtpPort' => 465, 'smtpEncryption' => 'ssl', 'smtpValidateCertificate' => 1,
];
$mailbox->updateAccount(1, 'Disposable Dovecot', $settings, (string) getenv('MAILBOX_TEST_IMAP_USER'), (string) getenv('MAILBOX_TEST_IMAP_PASS'));
$connection = $mailbox->testConnection();
if(empty($connection['ok']) || (int) $connection['folders'] < 1 || (int) $connection['messages'] < 1) throw new RuntimeException('Real TLS IMAP connection test failed.');
$messages = $mailbox->listMessages('INBOX', 1, 10);
if(($messages['messages'][0]['subject'] ?? '') !== 'Mailbox integration message') throw new RuntimeException('Seed IMAP message was not read.');

$tables = ['mailbox_accounts', 'mailbox_credentials', 'mailbox_message_index', 'mailbox_sync_state', 'mailbox_jobs', 'mailbox_notifications', 'mailbox_view_cache'];
foreach($tables as $table) {
    $statement = $database->prepare('SHOW TABLES LIKE ?');
    $statement->execute([$table]);
    if(!$statement->fetchColumn()) throw new RuntimeException('Missing installed Mailbox table: ' . $table);
}
$credential = $database->query('SELECT username_enc, password_enc FROM mailbox_credentials WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if(!is_array($credential) || strpos(implode('|', $credential), (string) getenv('MAILBOX_TEST_IMAP_USER')) !== false || strpos(implode('|', $credential), (string) getenv('MAILBOX_TEST_IMAP_PASS')) !== false) throw new RuntimeException('Credential plaintext was found in the database.');

$mailbox->___upgrade(0, 100);
$mailbox->___upgrade(0, 100);
if(count($mailbox->getAccounts()) !== 1) throw new RuntimeException('Idempotent upgrade changed the account registry.');

if($modules->isInstalled('ProcessMailbox')) $modules->uninstall('ProcessMailbox');
$modules->uninstall('Mailbox');
foreach($tables as $table) {
    $statement = $database->prepare('SHOW TABLES LIKE ?');
    $statement->execute([$table]);
    if($statement->fetchColumn()) throw new RuntimeException('Mailbox uninstall left table behind: ' . $table);
}

fwrite(STDOUT, json_encode(['ok' => true, 'processwire' => (string) $processWire->wire('config')->version, 'mailbox' => '1.0.0', 'imap_messages' => (int) $connection['messages'], 'migration_replays' => 2, 'uninstall_verified' => true], JSON_UNESCAPED_SLASHES) . PHP_EOL);

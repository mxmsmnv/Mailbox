<?php

declare(strict_types=1);

if(PHP_SAPI !== 'cli') exit(1);
if(!function_exists('imap_open')) throw new RuntimeException('The PHP IMAP extension is required.');
$path = (string) getenv('MAILBOX_EXTERNAL_MATRIX');
if($path === '' || $path[0] !== DIRECTORY_SEPARATOR || !is_file($path)) throw new RuntimeException('MAILBOX_EXTERNAL_MATRIX must name an existing absolute JSON file.');
$raw = file_get_contents($path);
$matrix = is_string($raw) ? json_decode($raw, true) : null;
if(!is_array($matrix) || !$matrix) throw new RuntimeException('The external IMAP matrix must be a non-empty JSON array.');
$results = [];
foreach($matrix as $entry) {
    if(!is_array($entry)) throw new RuntimeException('Each external IMAP entry must be an object.');
    $name = preg_replace('/[^a-z0-9_.-]/i', '', (string) ($entry['name'] ?? ''));
    $host = trim((string) ($entry['host'] ?? ''));
    $port = (int) ($entry['port'] ?? 0);
    $encryption = ($entry['encryption'] ?? '') === 'tls' ? 'tls' : 'ssl';
    $username = getenv((string) ($entry['username_env'] ?? ''));
    $password = getenv((string) ($entry['password_env'] ?? ''));
    if($name === '' || $host === '' || $port < 1 || $port > 65535 || $username === false || $username === '' || $password === false || $password === '') throw new RuntimeException('Incomplete endpoint or environment credentials for ' . ($name ?: 'unnamed') . '.');
    $prefix = '{' . $host . ':' . $port . '/imap/' . $encryption . '/validate-cert/readonly}INBOX';
    $started = microtime(true);
    $connection = @imap_open($prefix, $username, $password, OP_READONLY, 1);
    if($connection === false) throw new RuntimeException('External IMAP authentication failed for ' . $name . '.');
    try {
        $check = @imap_check($connection);
        $results[] = ['name' => $name, 'ok' => true, 'messages' => $check ? (int) $check->Nmsgs : (int) imap_num_msg($connection), 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
    } finally {
        imap_close($connection);
    }
}
fwrite(STDOUT, json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_SLASHES) . PHP_EOL);

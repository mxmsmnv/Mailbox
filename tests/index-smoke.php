<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class Wire {
        private $services = [];
        public function setService(string $name, $value): void { $this->services[$name] = $value; }
        public function wire(string $name) { return $this->services[$name] ?? null; }
    }
    require_once dirname(__DIR__) . '/src/MailboxIndexStore.php';

    $config = (object) ['mailboxActiveKey' => 'blue', 'mailboxKeys' => ['blue' => str_repeat('b', 64), 'green' => str_repeat('g', 64)]];
    $store = new MailboxIndexStore();
    $store->setService('config', $config);
    $reflection = new \ReflectionClass($store);
    $encrypt = $reflection->getMethod('encrypt');
    $decrypt = $reflection->getMethod('decrypt');
    $folderHash = $reflection->getMethod('folderHash');
    if(PHP_VERSION_ID < 80100) {
        $encrypt->setAccessible(true);
        $decrypt->setAccessible(true);
        $folderHash->setAccessible(true);
    }

    $plain = '{"subject":"private invoice","uid":42}';
    $cipher = $encrypt->invoke($store, 7, $plain);
    if(strpos($cipher, 'v1:blue:7:') !== 0 || strpos($cipher, 'private invoice') !== false) throw new \RuntimeException('Index encryption envelope failed.');
    if($decrypt->invoke($store, 7, $cipher) !== $plain) throw new \RuntimeException('Index encryption round trip failed.');
    $inbox7 = $folderHash->invoke($store, 7, 'INBOX');
    $inbox8 = $folderHash->invoke($store, 8, 'INBOX');
    if($inbox7 === hash('sha256', 'INBOX') || hash_equals($inbox7, $inbox8)) throw new \RuntimeException('Folder hashes are not account-bound keyed identities.');
    try {
        $decrypt->invoke($store, 8, $cipher);
        throw new \RuntimeException('Cross-account index decryption was accepted.');
    } catch(\ReflectionException $error) {
        throw $error;
    } catch(\Throwable $error) {
        $cause = $error instanceof \ReflectionException ? $error : ($error->getPrevious() ?: $error);
        if(!$cause instanceof WireException) throw $error;
    }

    $source = file_get_contents(dirname(__DIR__) . '/src/MailboxIndexStore.php');
    foreach(['mailbox_message_index', 'mailbox_sync_state', 'mailbox_jobs', 'mailbox_notifications', 'mailbox_view_cache', 'MailboxViewCache.v1', 'payload_enc', 'uid_validity', 'job_dedupe', 'FOR UPDATE', 'sodium_crypto_secretbox_open'] as $needle) {
        if(strpos((string) $source, $needle) === false) throw new \RuntimeException('Missing index boundary: ' . $needle);
    }
    if(strpos($source, 'LAST_INSERT_ID') !== false || strpos($source, "exec('BEGIN IMMEDIATE')") === false || strpos($source, 'LIMIT 1 FOR UPDATE') !== false) {
        throw new \RuntimeException('Portable index upsert or locking boundary is missing.');
    }
    $syncSource = file_get_contents(dirname(__DIR__) . '/src/MailboxSyncConcern.php');
    foreach(['$initialSync', 'folderUidValidity', 'claim(900)', 'dispatchIndexedNotification', 'enableBackgroundSync'] as $needle) {
        if(strpos((string) $syncSource, $needle) === false) throw new \RuntimeException('Missing sync boundary: ' . $needle);
    }
    $restSource = file_get_contents(dirname(__DIR__) . '/src/MailboxRestApi.php');
    if(strpos((string) $restSource, "case 'notification-read':") === false || strpos((string) $restSource, "['POST']") === false) throw new \RuntimeException('Notification acknowledgement REST boundary is missing.');
    $cliSource = file_get_contents(dirname(__DIR__) . '/bin/mailbox');
    if(strpos((string) $cliSource, "case 'idle':") === false || strpos((string) $cliSource, 'explicit --execute flag') === false) throw new \RuntimeException('IDLE CLI execution boundary is missing.');
    fwrite(STDOUT, "Mailbox encrypted index smoke tests passed.\n");
}

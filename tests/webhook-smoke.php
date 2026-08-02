<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    require_once dirname(__DIR__) . '/src/MailboxWebhookClient.php';

    $client = new MailboxWebhookClient();
    $method = new \ReflectionMethod($client, 'publicAddresses');
    if(PHP_VERSION_ID < 80100) $method->setAccessible(true);
    $addresses = $method->invoke($client, '127.0.0.1');
    if($addresses !== []) throw new \RuntimeException('Webhook accepted a loopback destination.');

    $clientSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxWebhookClient.php');
    foreach(['CURLOPT_RESOLVE', "CURLOPT_PROXY => ''", 'CURLOPT_FOLLOWLOCATION => false', 'CURLOPT_SSL_VERIFYPEER => true', "hash_hmac('sha256', \$timestamp . \"\\n\" . \$body", 'MAX_RESPONSE_BYTES'] as $needle) {
        if(strpos($clientSource, $needle) === false) throw new \RuntimeException('Missing webhook transport boundary: ' . $needle);
    }

    $syncSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxSyncConcern.php');
    foreach(["'webhook_notification'", "'folder_id'", 'mailboxWebhookSecretProvider', 'mailboxWebhookSecrets', 'webhook_delivered'] as $needle) {
        if(strpos($syncSource, $needle) === false) throw new \RuntimeException('Missing webhook queue boundary: ' . $needle);
    }
    $dispatchStart = strpos($syncSource, 'private function dispatchIndexedNotification');
    $dispatchEnd = strpos($syncSource, 'private function deliverWebhookNotification', $dispatchStart);
    $dispatch = substr($syncSource, $dispatchStart, $dispatchEnd - $dispatchStart);
    if(strpos($dispatch, "'subject'") !== false || strpos($dispatch, "'from'") !== false) throw new \RuntimeException('Webhook payload includes message content or sender identity.');

    $indexSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxIndexStore.php');
    foreach(["'webhook:' . \$accountId", "\$job['dedupe_key']"] as $needle) {
        if(strpos($indexSource, $needle) === false) throw new \RuntimeException('Webhook retry deduplication is missing.');
    }

    fwrite(STDOUT, "Mailbox webhook smoke tests passed.\n");
}

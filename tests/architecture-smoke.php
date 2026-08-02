<?php

$root = dirname(__DIR__);
$entrypoint = $root . '/Mailbox.module.php';
$lines = file($entrypoint, FILE_IGNORE_NEW_LINES);
if($lines === false) throw new RuntimeException('Unable to read Mailbox.module.php.');
if(count($lines) > 200) throw new RuntimeException('Mailbox.module.php must remain a thin facade.');

$concerns = [
    'MailboxAccountsConcern.php' => 'trait MailboxAccountsConcern',
    'MailboxApiConcern.php' => 'trait MailboxApiConcern',
    'MailboxCredentialsConcern.php' => 'trait MailboxCredentialsConcern',
    'MailboxPresetsConcern.php' => 'trait MailboxPresetsConcern',
    'MailboxDiscoveryConcern.php' => 'trait MailboxDiscoveryConcern',
    'MailboxImapConcern.php' => 'trait MailboxImapConcern',
    'MailboxMutationConcern.php' => 'trait MailboxMutationConcern',
    'MailboxSquadConcern.php' => 'trait MailboxSquadConcern',
    'MailboxSendingConcern.php' => 'trait MailboxSendingConcern',
    'MailboxAttachmentConcern.php' => 'trait MailboxAttachmentConcern',
    'MailboxSearchConcern.php' => 'trait MailboxSearchConcern',
    'MailboxSyncConcern.php' => 'trait MailboxSyncConcern',
    'MailboxCacheConcern.php' => 'trait MailboxCacheConcern',
    'MailboxConfirmationConcern.php' => 'trait MailboxConfirmationConcern',
    'MailboxConfigConcern.php' => 'trait MailboxConfigConcern',
];

$entrypointSource = implode("\n", $lines);
foreach($concerns as $file => $declaration) {
    $path = $root . '/src/' . $file;
    $source = is_file($path) ? file_get_contents($path) : false;
    if(!is_string($source) || strpos($source, $declaration) === false) {
        throw new RuntimeException("Missing Mailbox concern: {$file}");
    }
    if(strpos($entrypointSource, "require_once __DIR__ . '/src/{$file}';") === false) {
        throw new RuntimeException("Mailbox entrypoint does not load {$file}");
    }
}

$processSource = (string) file_get_contents($root . '/ProcessMailbox.module.php');
foreach(['ProcessMailboxAccountsConcern.php' => 'ProcessMailboxAccountsConcern', 'ProcessMailboxDiscoveryConcern.php' => 'ProcessMailboxDiscoveryConcern', 'ProcessMailboxBulkConcern.php' => 'ProcessMailboxBulkConcern', 'ProcessMailboxAiConcern.php' => 'ProcessMailboxAiConcern'] as $file => $trait) {
    $source = (string) file_get_contents($root . '/src/' . $file);
    if(strpos($source, 'trait ' . $trait) === false || strpos($processSource, "require_once __DIR__ . '/src/{$file}';") === false || strpos($processSource, 'use ' . $trait . ';') === false) throw new RuntimeException('Missing ProcessMailbox concern: ' . $file);
}

fwrite(STDOUT, "Mailbox architecture smoke tests passed.\n");

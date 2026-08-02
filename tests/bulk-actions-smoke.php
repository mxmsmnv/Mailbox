<?php

$root = dirname(__DIR__);
$mutation = (string) file_get_contents($root . '/src/MailboxMutationConcern.php');
$controller = (string) file_get_contents($root . '/src/ProcessMailboxBulkConcern.php');
$process = (string) file_get_contents($root . '/ProcessMailbox.module.php');
$client = (string) file_get_contents($root . '/js/mailbox.admin.js');
$css = (string) file_get_contents($root . '/css/mailbox.admin.css');

foreach([
    'imap_setflag_full($connection, $sequence',
    'imap_clearflag_full($connection, $sequence',
    'imap_mail_move($connection, $sequence, $destination, CP_UID)',
    'imap_delete($connection, $sequence, FT_UID)',
    "'expunged' => false",
    'clearMailboxViewCache()',
] as $needle) if(strpos($mutation, $needle) === false) throw new RuntimeException('Native bulk mutation contract is missing: ' . $needle);

if(substr_count($mutation, '$this->clearMailboxViewCache();') < 4) throw new RuntimeException('Mailbox cache invalidation contracts unexpectedly changed.');
foreach(['___executeBulkAction', 'CSRF->hasValidToken()', 'Mailbox::writePermission', "'user:'"] as $needle) if(strpos($controller, $needle) === false) throw new RuntimeException('Bulk controller safeguard is missing: ' . $needle);
foreach(['data-mailbox-select-all', "name='uids[]'", 'data-mailbox-bulk-bar', 'data-mailbox-bulk-destination', 'Bulk actions are disabled'] as $needle) if(strpos($process, $needle) === false) throw new RuntimeException('Bulk list UI is missing: ' . $needle);
foreach(['initializeBulkActions', 'selectAll.indeterminate', "action.value === 'move'", "action.value === 'delete'"] as $needle) if(strpos($client, $needle) === false) throw new RuntimeException('Bulk client behavior is missing: ' . $needle);
foreach(['.Mailbox-bulk-bar', '.Mailbox-bulk-disabled', '.mc-message-row.is-selected', '.Mailbox-bulk-safety'] as $needle) if(strpos($css, $needle) === false) throw new RuntimeException('Bulk UI styling is missing: ' . $needle);

fwrite(STDOUT, "Mailbox bulk-actions smoke tests passed.\n");

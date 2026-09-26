<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class Wire {
        public static $services = [];
        public function wire($name) { return self::$services[$name] ?? null; }
    }
    class FakeAccountDatabase {
        public $rows = [];
        public $lastId = 0;
        private $transaction = false;
        public function exec($sql) {
            if(stripos($sql, 'BEGIN IMMEDIATE') !== false) $this->transaction = true;
            if(stripos($sql, 'SET `is_default` = 0') !== false) foreach($this->rows as &$row) $row['is_default'] = 0;
            if(stripos($sql, 'DROP TABLE') !== false) $this->rows = [];
            return 0;
        }
        public function prepare($sql) { return new FakeAccountStatement($this, $sql); }
        public function lastInsertId() { return (string) $this->lastId; }
        public function beginTransaction() { $this->transaction = true; return true; }
        public function commit() { $this->transaction = false; return true; }
        public function rollBack() { $this->transaction = false; return true; }
        public function inTransaction() { return $this->transaction; }
        public function getAttribute($attribute) { return 'sqlite'; }
    }
    class FakeAccountStatement {
        private $database;
        private $sql;
        private $selected = [];
        public function __construct(FakeAccountDatabase $database, string $sql) { $this->database = $database; $this->sql = $sql; }
        public function execute(array $parameters = []) {
            if(stripos($this->sql, 'INSERT INTO') !== false) {
                $id = strpos($this->sql, 'VALUES (1,') !== false ? 1 : max(array_merge([0], array_keys($this->database->rows))) + 1;
                $this->database->lastId = $id;
                $this->database->rows[$id] = [
                    'id' => $id, 'uuid' => $parameters[':uuid'], 'label' => $parameters[':label'],
                    'is_default' => $id === 1 ? 1 : 0, 'enabled' => 1, 'settings_json' => $parameters[':settings'],
                    'created' => '2026-08-01 00:00:00', 'modified' => '2026-08-01 00:00:00',
                ];
            } else if(stripos($this->sql, 'SET `label`') !== false) {
                $id = (int) $parameters[':id'];
                $this->database->rows[$id]['label'] = $parameters[':label'];
                $this->database->rows[$id]['enabled'] = $parameters[':enabled'];
                $this->database->rows[$id]['settings_json'] = $parameters[':settings'];
            } else if(stripos($this->sql, 'SET `is_default` = 1') !== false) {
                $id = (int) $parameters[':id'];
                $this->database->rows[$id]['is_default'] = 1;
                $this->database->rows[$id]['enabled'] = 1;
            } else if(stripos($this->sql, 'DELETE FROM') !== false) {
                unset($this->database->rows[(int) $parameters[':id']]);
            } else if(stripos($this->sql, 'WHERE `id` = :id') !== false) {
                $row = $this->database->rows[(int) $parameters[':id']] ?? null;
                $this->selected = $row ? [$row] : [];
            } else if(stripos($this->sql, 'SELECT') !== false) {
                $this->selected = array_values($this->database->rows);
                usort($this->selected, static function(array $a, array $b): int { return $b['is_default'] <=> $a['is_default']; });
            }
            return true;
        }
        public function fetch($mode = null) { return $this->selected ? $this->selected[0] : false; }
        public function fetchAll($mode = null) { return $this->selected; }
    }

    require_once dirname(__DIR__) . '/src/MailboxAccounts.php';

    $database = new FakeAccountDatabase();
    Wire::$services['database'] = $database;
    $accounts = new MailboxAccounts();
    $primary = ['host' => 'imap.primary.example', 'port' => 993];
    if($accounts->ensureDefault($primary) !== 1) throw new \RuntimeException('Primary account migration failed.');
    if($accounts->ensureDefault(['host' => 'changed.invalid']) !== 1 || count($database->rows) !== 1) {
        throw new \RuntimeException('Primary account migration is not idempotent.');
    }
    $secondary = $accounts->create('Support', ['host' => 'imap.support.example', 'port' => 993], false);
    if($secondary['id'] !== 2 || $secondary['settings']['host'] !== 'imap.support.example') throw new \RuntimeException('Account creation failed.');
    $third = $accounts->create('Billing', ['host' => 'imap.billing.example', 'port' => 993], false);
    if($third['id'] !== 3 || count($accounts->list()) !== MailboxAccounts::MAX_ACCOUNTS) throw new \RuntimeException('Three-account capacity failed.');
    try {
        $accounts->create('Overflow', ['host' => 'imap.overflow.example', 'port' => 993], false);
        throw new \RuntimeException('A fourth mailbox account was accepted.');
    } catch(WireException $error) {}
    $accounts->setDefault(2);
    if(!$accounts->require(2)['is_default'] || $accounts->require(1)['is_default']) throw new \RuntimeException('Default account switch failed.');
    try {
        $accounts->delete(2);
        throw new \RuntimeException('Default account deletion was allowed.');
    } catch(WireException $error) {}
    try {
        $accounts->update(2, 'Support', $secondary['settings'], false);
        throw new \RuntimeException('The default account was disabled.');
    } catch(WireException $error) {}
    $updated = $accounts->update(1, 'Primary renamed', $primary, false);
    if($updated['label'] !== 'Primary renamed' || $updated['enabled']) throw new \RuntimeException('Account update failed.');
    try {
        $accounts->delete(1);
        throw new \RuntimeException('The module-managed primary account was deleted.');
    } catch(WireException $error) {}
    $accounts->delete(3);
    if($accounts->find(3) !== null) throw new \RuntimeException('Non-default account deletion failed.');

    foreach($accounts->list() as $account) {
        if(isset($account['username']) || isset($account['password'])) throw new \RuntimeException('Account registry exposed credentials.');
    }
    $processSource = (string) file_get_contents(dirname(__DIR__) . '/ProcessMailbox.module.php');
    $adminSource = (string) file_get_contents(dirname(__DIR__) . '/src/ProcessMailboxAccountsConcern.php');
    foreach(['selectedAccountId()', 'withAccount($this->activeAccountId', "name='account'", 'renderAccountSwitcher()', '___executeAccounts'] as $needle) {
        if(strpos($processSource . $adminSource, $needle) === false) throw new \RuntimeException('Admin account switching boundary is missing: ' . $needle);
    }
    if(strpos($adminSource, 'isSuperuser()') === false || strpos($adminSource, "!== 'DELETE'") === false) throw new \RuntimeException('Account administration security boundary is missing.');
    foreach(["\$action === 'test'", 'testAuthentication()', "value='test'", 'No folders or messages were downloaded.'] as $needle) {
        if(strpos($adminSource, $needle) === false) throw new \RuntimeException('Lightweight account connection test is missing: ' . $needle);
    }
    if(strpos($adminSource, 'do not disable certificate validation') === false) throw new \RuntimeException('Safe SNI failure guidance is missing.');
    $imapSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxImapConcern.php');
    $authenticationMethod = strstr($imapSource, 'public function testAuthentication(): array');
    $authenticationMethod = $authenticationMethod === false ? '' : strstr($authenticationMethod, 'public function testConnection(): array', true);
    if($authenticationMethod === '' || strpos($authenticationMethod, 'listFolders') !== false || strpos($authenticationMethod, 'imap_num_msg') !== false || strpos($authenticationMethod, 'messages()') !== false) {
        throw new \RuntimeException('Account authentication test performs mailbox enumeration.');
    }
    $moduleSource = (string) file_get_contents(dirname(__DIR__) . '/Mailbox.module.php');
    $configSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxConfigConcern.php');
    $concernSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxAccountsConcern.php');
    foreach(['pendingPrimaryAccountSettings', 'normalizeAccountSettings($data)', 'syncPrimaryAccountSettings($settings)'] as $needle) {
        if(strpos($moduleSource . $configSource . $concernSource, $needle) === false) throw new \RuntimeException('Primary account save synchronization is missing: ' . $needle);
    }
    $accountSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxAccounts.php');
    if(strpos($accountSource, "exec('BEGIN IMMEDIATE')") === false || strpos($accountSource, "ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE'") === false || strpos($accountSource, 'ORDER BY `id` FOR UPDATE') !== false) {
        throw new \RuntimeException('Portable account locking is missing.');
    }
    fwrite(STDOUT, "Mailbox account registry smoke tests passed.\n");
}

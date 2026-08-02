<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class Wire {
        public static $services = [];
        public function wire($name) { return self::$services[$name] ?? null; }
    }
    class FakeCredentialDatabase {
        public $row = null;
        public function exec($sql) {
            if(stripos($sql, 'DELETE FROM') !== false || stripos($sql, 'DROP TABLE') !== false) $this->row = null;
            return 0;
        }
        public function prepare($sql) { return new FakeCredentialStatement($this, $sql); }
    }
    class FakeCredentialStatement {
        private $database;
        private $sql;
        public function __construct(FakeCredentialDatabase $database, string $sql) {
            $this->database = $database;
            $this->sql = $sql;
        }
        public function execute(array $parameters = []) {
            if(stripos($this->sql, 'INSERT INTO') !== false) {
                $this->database->row = [
                    'username_enc' => $parameters[':username'],
                    'password_enc' => $parameters[':password'],
                ];
            } else if(stripos($this->sql, 'DELETE FROM') !== false) {
                $this->database->row = null;
            }
            return true;
        }
        public function fetch($mode = null) { return $this->database->row ?: false; }
    }

    require_once dirname(__DIR__) . '/src/MailboxCredentials.php';

    $config = (object) [
        'mailboxSecret' => 'test-secret-kept-outside-the-database',
        'tableSalt' => 'fallback-table-salt',
        'userAuthSalt' => 'fallback-user-auth-salt',
    ];
    Wire::$services['config'] = $config;
    $database = new FakeCredentialDatabase();
    Wire::$services['database'] = $database;
    $store = new MailboxCredentials();

    $encrypt = new \ReflectionMethod($store, 'encrypt');
    $decrypt = new \ReflectionMethod($store, 'decrypt');
    if(PHP_VERSION_ID < 80100) {
        $encrypt->setAccessible(true);
        $decrypt->setAccessible(true);
    }

    $plain = 'mailbox-app-password-value';
    $first = $encrypt->invoke($store, $plain);
    $second = $encrypt->invoke($store, $plain);
    if($first === $second) throw new \RuntimeException('Credential encryption reused a nonce.');
    if(strpos($first, $plain) !== false) throw new \RuntimeException('Plaintext leaked into credential ciphertext.');
    if($decrypt->invoke($store, $first) !== $plain) throw new \RuntimeException('Credential round trip failed.');

    $legacyNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $legacyKey = sodium_crypto_generichash(
        MailboxCredentials::CONTEXT . '|' . $config->mailboxSecret,
        '',
        SODIUM_CRYPTO_SECRETBOX_KEYBYTES
    );
    $legacy = 'v1:' . base64_encode($legacyNonce . sodium_crypto_secretbox($plain, $legacyNonce, $legacyKey));
    if($decrypt->invoke($store, $legacy) !== $plain) throw new \RuntimeException('Legacy v1 credential envelope is no longer readable.');

    $v2Nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $v2Key = sodium_crypto_generichash(
        MailboxCredentials::CONTEXT . '|default|' . $config->mailboxSecret,
        '',
        SODIUM_CRYPTO_SECRETBOX_KEYBYTES
    );
    $v2 = 'v2:default:' . base64_encode($v2Nonce . sodium_crypto_secretbox($plain, $v2Nonce, $v2Key));
    if($decrypt->invoke($store, $v2) !== $plain) throw new \RuntimeException('Legacy v2 credential envelope is no longer readable.');

    $otherAccount = new MailboxCredentials(2);
    $otherDecrypt = new \ReflectionMethod($otherAccount, 'decrypt');
    if(PHP_VERSION_ID < 80100) $otherDecrypt->setAccessible(true);
    try {
        $otherDecrypt->invoke($otherAccount, $first);
        throw new \RuntimeException('Account-bound ciphertext was accepted by another account.');
    } catch(\Throwable $error) {
        if($error instanceof \RuntimeException && $error->getMessage() === 'Account-bound ciphertext was accepted by another account.') throw $error;
    }

    $config->mailboxSecret = 'rotated-without-reencryption';
    try {
        $decrypt->invoke($store, $first);
        throw new \RuntimeException('Ciphertext decrypted with the wrong secret.');
    } catch(\ReflectionException $error) {
        throw $error;
    } catch(\Throwable $error) {
        $previous = $error instanceof \ReflectionException ? $error : $error->getPrevious();
        if($error instanceof \RuntimeException && $error->getMessage() === 'Ciphertext decrypted with the wrong secret.') throw $error;
    }

    $config->mailboxSecret = 'test-secret-kept-outside-the-database';
    $tampered = substr($first, 0, -2) . 'AA';
    try {
        $decrypt->invoke($store, $tampered);
        throw new \RuntimeException('Tampered ciphertext was accepted.');
    } catch(\Throwable $error) {
        if($error instanceof \RuntimeException && $error->getMessage() === 'Tampered ciphertext was accepted.') throw $error;
    }

    $store->save('agent@example.com', 'stored-app-password');
    if(!$database->row || strpos(json_encode($database->row), 'stored-app-password') !== false || strpos(json_encode($database->row), 'agent@example.com') !== false) {
        throw new \RuntimeException('Credential table row contains plaintext.');
    }
    $stored = $store->get();
    if($stored['username'] !== 'agent@example.com' || $stored['password'] !== 'stored-app-password') {
        throw new \RuntimeException('Encrypted credential table round trip failed.');
    }
    try { $store->save(str_repeat('u', 321), 'secret'); throw new \RuntimeException('Oversized IMAP username was accepted.'); } catch(WireException $error) {}
    try { $store->save('agent@example.com', str_repeat('p', 32769)); throw new \RuntimeException('Oversized IMAP secret was accepted.'); } catch(WireException $error) {}
    $store->delete();
    if($store->get() !== null) throw new \RuntimeException('Credential row was not deleted.');

    $config->mailboxKeys = ['old-key' => 'old-key-material', 'new-key' => 'new-key-material'];
    $config->mailboxActiveKey = 'old-key';
    $store->save('rotation@example.com', 'rotation-password');
    if(strpos($database->row['username_enc'], 'v3:old-key:1:') !== 0) throw new \RuntimeException('Named active key was not used.');
    $config->mailboxActiveKey = 'new-key';
    $rotation = $store->rotate();
    if($rotation !== ['rotated' => true, 'from_key' => 'old-key', 'to_key' => 'new-key']) {
        throw new \RuntimeException('Credential key rotation metadata is incorrect.');
    }
    unset($config->mailboxKeys['old-key']);
    $rotated = $store->get();
    if($rotated['username'] !== 'rotation@example.com' || $rotated['password'] !== 'rotation-password') {
        throw new \RuntimeException('Rotated credentials are not readable without the retired key.');
    }

    $configSource = file_get_contents(dirname(__DIR__) . '/src/MailboxConfigConcern.php');
    if(strpos($configSource, "storedCredentials['username']") !== false || strpos($configSource, 'storedCredentials(false)') !== false) {
        throw new \RuntimeException('Saved username is still repopulated into the configuration DOM.');
    }

    fwrite(STDOUT, "Mailbox credential encryption smoke tests passed.\n");
}

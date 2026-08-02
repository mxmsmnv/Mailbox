<?php

declare(strict_types=1);

namespace ProcessWire {
    interface Module {}
    interface ConfigurableModule {}
    class WireException extends \RuntimeException {}
    class Wire {}
    class User {}
    class WireData extends Wire {
        private $values = [];
        public function __construct() {}
        public function set($name, $value) { $this->values[$name] = $value; return $this; }
        public function __get($name) { return $this->values[$name] ?? null; }
    }

    require_once dirname(__DIR__) . '/Mailbox.module.php';

    $mailbox = new Mailbox();
    $presets = $mailbox->getPresets();
    $expected = [
        'hestiacp' => [993, 'ssl'],
        'vestacp' => [143, 'tls'],
        'cyberpanel' => [993, 'ssl'],
        'ispconfig' => [993, 'ssl'],
        'cpanel' => [993, 'ssl'],
        'plesk' => [993, 'ssl'],
        'directadmin' => [993, 'ssl'],
        'poste-io' => [993, 'ssl'],
        'iredmail' => [993, 'ssl'],
        'mail-in-a-box' => [993, 'ssl'],
        'modoboa' => [993, 'ssl'],
    ];

    foreach($expected as $name => $connection) {
        if(!isset($presets[$name])) throw new \RuntimeException("Missing preset: {$name}");
        if((int) $presets[$name]['port'] !== $connection[0] || $presets[$name]['encryption'] !== $connection[1]) {
            throw new \RuntimeException("Unexpected connection defaults for {$name}");
        }
        if($presets[$name]['host'] === '' || empty($presets[$name]['validateCertificate'])) {
            throw new \RuntimeException("Unsafe or incomplete preset: {$name}");
        }
        if(($presets[$name]['smtpHost'] ?? '') === '' || ($presets[$name]['smtpPort'] ?? 0) < 1 || !in_array($presets[$name]['smtpEncryption'] ?? '', ['ssl', 'tls'], true) || empty($presets[$name]['smtpValidateCertificate'])) {
            throw new \RuntimeException("Missing SMTP defaults for {$name}");
        }
    }

    if(count($presets) !== 25) throw new \RuntimeException('Unexpected total preset count: ' . count($presets));

    $serverPrefix = new \ReflectionMethod($mailbox, 'serverPrefix');
    if(PHP_VERSION_ID < 80100) $serverPrefix->setAccessible(true);
    $mailbox->set('host', 'mail.example.com')->set('encryption', 'none')->set('validateCertificate', 1);
    $prefix = $serverPrefix->invoke($mailbox);
    if(strpos($prefix, '/ssl/') === false || strpos($prefix, '/notls') !== false) {
        throw new \RuntimeException('Legacy unencrypted IMAP setting was not forced to TLS.');
    }
    $mailbox->set('encryption', 'ssl')->set('validateCertificate', 0);
    try {
        $serverPrefix->invoke($mailbox);
        throw new \RuntimeException('Remote certificate-validation bypass was accepted.');
    } catch(WireException $error) {
        // Expected.
    }
    $mailbox->set('host', '127.0.0.1')->set('validateCertificate', 0);
    if(strpos($serverPrefix->invoke($mailbox), '/novalidate-cert') === false) {
        throw new \RuntimeException('Loopback bridge exception was not retained.');
    }
    $mailbox->set('host', 'mail.example.com')->set('validateCertificate', 1);
    if(strpos($serverPrefix->invoke($mailbox, false), '/readonly') !== false) throw new \RuntimeException('Writable IMAP prefix remained read-only.');

    $normalizeFlags = new \ReflectionMethod($mailbox, 'normalizeMutationFlags');
    if(PHP_VERSION_ID < 80100) $normalizeFlags->setAccessible(true);
    if($normalizeFlags->invoke($mailbox, ['\\Seen', 'FLAGGED', 'deleted', 'seen']) !== ['seen', 'flagged']) throw new \RuntimeException('Mutation flag allowlist failed.');

    $normalizeAccount = new \ReflectionMethod($mailbox, 'normalizeAccountSettings');
    if(PHP_VERSION_ID < 80100) $normalizeAccount->setAccessible(true);
    $account = $normalizeAccount->invoke($mailbox, [
        'host' => 'imap.account.example',
        'port' => 70000,
        'encryption' => 'none',
        'imapTransport' => 'invalid',
        'validateCertificate' => 0,
        'username' => 'must-not-be-stored-here',
        'password' => 'must-not-be-stored-here',
        'oauthUsername' => 'oauth-must-not-be-stored-here@example.com',
        'smtpHost' => 'smtp.account.example',
        'smtpPort' => 70000,
        'smtpEncryption' => 'none',
        'smtpValidateCertificate' => 0,
    ]);
    if($account['port'] !== 65535 || $account['encryption'] !== 'ssl' || $account['imapTransport'] !== 'auto' || !$account['validateCertificate']) {
        throw new \RuntimeException('Account connection settings were not normalized securely.');
    }
    if(isset($account['username']) || isset($account['password']) || isset($account['oauthUsername'])) throw new \RuntimeException('Secrets leaked into account settings.');
    if($account['smtpPort'] !== 65535 || $account['smtpEncryption'] !== 'tls' || !$account['smtpValidateCertificate']) throw new \RuntimeException('SMTP settings were not normalized securely.');
    $usesWebklex = new \ReflectionMethod($mailbox, 'usesWebklexTransport');
    $mailbox->set('preset', 'gmail')->set('host', 'imap.gmail.com')->set('imapTransport', 'auto')->set('authentication', 'password');
    if(!$usesWebklex->invoke($mailbox)) throw new \RuntimeException('Gmail Auto transport did not select Webklex.');
    $mailbox->set('imapTransport', 'native');
    if($usesWebklex->invoke($mailbox)) throw new \RuntimeException('Explicit native IMAP selection was ignored.');
    $webklexSource = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxWebklexTransport.php');
    foreach(['iconv_mime_decode', 'mb_decode_mimeheader', "\$b['uid'] <=> \$a['uid']", "\$status['exists']"] as $needle) {
        if(strpos($webklexSource, $needle) === false) throw new \RuntimeException('Webklex bounded list normalization is missing: ' . $needle);
    }
    fwrite(STDOUT, "Mailbox preset smoke tests passed (25 presets).\n");
}

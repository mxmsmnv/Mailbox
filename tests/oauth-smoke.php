<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class FakeOAuthSession {
        private $values = [];
        public function get($name) { return $this->values[$name] ?? null; }
        public function set($name, $value): void { $this->values[$name] = $value; }
    }
    class Wire {
        public static $services = [];
        public function wire($value) {
            if(is_object($value)) return $value;
            return self::$services[$value] ?? null;
        }
    }
    class MailboxCredentials extends Wire {
        public function __construct(int $accountId = 1) {}
        public function get(): ?array { return ['username' => 'robot@example.com', 'password' => 'oauth:v1:pending']; }
    }
    class Mailbox extends Wire {
        public $provider = 'google';
        public $enableMailSending = 0;
        public function getAccount(int $id): array {
            if($id !== 7) throw new WireException('Unknown account.');
            return ['id' => 7, 'settings' => [
                'authentication' => 'oauth',
                'oauthProvider' => $this->provider,
                'oauthClientId' => 'public-client.apps.googleusercontent.com',
                'oauthTenant' => 'common',
            ]];
        }
    }

    require_once dirname(__DIR__) . '/src/MailboxOAuth.php';

    Wire::$services['session'] = new FakeOAuthSession();
    Wire::$services['config'] = (object) [];
    $mailbox = new Mailbox();
    $oauth = new MailboxOAuth($mailbox);
    $url = $oauth->authorizationUrl(7, 'https://cms.example.test/admin/setup/mailbox/oauth-callback/');
    $parts = parse_url($url);
    parse_str((string) ($parts['query'] ?? ''), $query);
    if(($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'accounts.google.com') throw new \RuntimeException('Unexpected Google authorization endpoint.');
    if(($query['code_challenge_method'] ?? '') !== 'S256' || empty($query['code_challenge']) || empty($query['state'])) throw new \RuntimeException('PKCE or OAuth state is missing.');
    if(strpos((string) ($query['scope'] ?? ''), 'https://mail.google.com/') === false) throw new \RuntimeException('Gmail IMAP scope is missing.');
    if(strpos((string) ($query['scope'] ?? ''), 'offline_access') !== false || ($query['access_type'] ?? '') !== 'offline') throw new \RuntimeException('Google offline-access parameters are invalid.');
    if(strpos($url, 'robot%40example.com') !== false || strpos($url, 'client_secret') !== false) throw new \RuntimeException('OAuth authorization URL leaked private account data.');

    $mailbox->provider = 'microsoft';
    $mailbox->enableMailSending = 1;
    $microsoftUrl = $oauth->authorizationUrl(7, 'https://cms.example.test/admin/setup/mailbox/oauth-callback/');
    parse_str((string) (parse_url($microsoftUrl, PHP_URL_QUERY) ?: ''), $microsoftQuery);
    if(strpos((string) ($microsoftQuery['scope'] ?? ''), 'https://outlook.office.com/IMAP.AccessAsUser.All') === false) throw new \RuntimeException('Microsoft IMAP scope is missing.');
    if(strpos((string) ($microsoftQuery['scope'] ?? ''), 'https://outlook.office.com/SMTP.Send') === false) throw new \RuntimeException('Microsoft SMTP.Send scope is missing.');

    try {
        $oauth->authorizationUrl(7, 'http://cms.example.test/callback');
        throw new \RuntimeException('Insecure OAuth redirect URI was accepted.');
    } catch(WireException $error) {}

    try {
        $oauth->complete(str_repeat('a', 64), 'unused-code', 'https://cms.example.test/admin/setup/mailbox/oauth-callback/');
        throw new \RuntimeException('Unknown OAuth state was accepted.');
    } catch(WireException $error) {
        if(strpos($error->getMessage(), 'state') === false) throw $error;
    }

    fwrite(STDOUT, "Mailbox OAuth PKCE/state smoke tests passed.\n");
}

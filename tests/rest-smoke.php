<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class WirePermissionException extends WireException {}
    class Wire {
        public static $services = [];
        public function wire($value = null) {
            if($value === null) return (object) self::$services;
            if(is_object($value)) return $value;
            return self::$services[$value] ?? null;
        }
    }
    class User {
        public function isLoggedin(): bool { return true; }
    }
    class FakeRestApiFacade {
        public function canRead(): bool { return true; }
        public function canWrite(): bool { return false; }
        public function canSend(): bool { return false; }
        public function canReadAttachments(): bool { return false; }
        public function canUseSquad(): bool { return false; }
        public function accounts(): array { return [['id' => 1, 'label' => 'Primary', 'configured' => true]]; }
    }
    class Mailbox extends Wire {
        public function api(?User $user = null, ?int $accountId = null): FakeRestApiFacade { return new FakeRestApiFacade(); }
    }
    class FakeCsrf {
        public function getToken($id = ''): array { return ['name' => 'TOKEN123', 'value' => 'secret-csrf', 'time' => time()]; }
    }
    class FakeSession {
        public $CSRF;
        private $values = [];
        public function __construct() { $this->CSRF = new FakeCsrf(); }
        public function get($name) { return $this->values[$name] ?? null; }
        public function set($name, $value): void { $this->values[$name] = $value; }
    }
    class FakeInputBag {
        public $account = 0;
        public $folder = 'INBOX';
        public $page = 1;
        public $limit = 30;
        public $uid = 1;
    }
    class FakeInput { public $get; public function __construct() { $this->get = new FakeInputBag(); } }
    class FakeLog { public function save($channel, $message): void {} }

    require_once dirname(__DIR__) . '/src/MailboxRestApi.php';

    Wire::$services = [
        'session' => new FakeSession(),
        'user' => new User(),
        'input' => new FakeInput(),
        'log' => new FakeLog(),
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $rest = new MailboxRestApi(new Mailbox());
    $session = json_decode($rest->handle('session'), true);
    if(empty($session['ok']) || empty($session['result']['isLogin']) || empty($session['result']['canRead'])) throw new \RuntimeException('REST session discovery failed.');
    if(($session['result']['csrf']['header'] ?? '') !== 'X-TOKEN123' || ($session['result']['csrf']['value'] ?? '') !== 'secret-csrf') throw new \RuntimeException('REST CSRF discovery failed.');
    $accounts = json_decode($rest->handle('accounts'), true);
    if(($accounts['result'][0]['label'] ?? '') !== 'Primary') throw new \RuntimeException('REST accounts dispatch failed.');
    $missing = json_decode($rest->handle('../unsafe'), true);
    if(($missing['ok'] ?? true) !== false || http_response_code() !== 404) throw new \RuntimeException('REST route validation failed.');

    fwrite(STDOUT, "Mailbox REST session/dispatch smoke tests passed.\n");
}

<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class Wire {
        public static $services = [];
        public function wire($value = null) {
            if($value === null) return (object) self::$services;
            return is_object($value) ? $value : (self::$services[$value] ?? null);
        }
    }
    class FakeAdapterUser { public $id = 9; public function isLoggedin(): bool { return true; } }
    class FakeVerk {
        public $metadata;
        public function createExternalApproval($provider, $id, $metadata, $actor): array { $this->metadata = $metadata; return ['provider' => $provider, 'external_id' => $id, 'task_id' => 17, 'actor' => $actor]; }
    }
    class FakeUid { public function toString(): string { return '01JTESTPENDING000000000000'; } }
    class FakePending { public $uid; public $status = 'pending'; public function __construct() { $this->uid = new FakeUid(); } }
    class FakeKontorAI {
        public $metadata;
        public function submitExternalApproval($provider, $id, $organization, $metadata, $actor): FakePending { $this->metadata = $metadata; return new FakePending(); }
    }
    class FakeAdapterModules {
        public $verk;
        public $kontor;
        public function __construct() { $this->verk = new FakeVerk(); $this->kontor = new FakeKontorAI(); }
        public function isInstalled($name): bool { return in_array($name, ['Verk', 'KontorAI'], true); }
        public function get($name) { return $name === 'Verk' ? $this->verk : $this->kontor; }
    }

    require_once dirname(__DIR__) . '/src/MailboxApprovalAdapters.php';
    $modules = new FakeAdapterModules();
    Wire::$services = ['modules' => $modules, 'user' => new FakeAdapterUser()];
    $proposal = [
        'id' => str_repeat('a', 32), 'requested_by' => 'user:9', 'host' => 'confirm.example.com',
        'folder' => 'INBOX', 'uid' => 42, 'account_id' => 2, 'body' => 'secret body',
        'workflow' => ['mode' => 'code_form', 'max_steps' => 2, 'code_fingerprint' => str_repeat('f', 64)],
        'url' => 'https://confirm.example.com/private?token=secret',
    ];
    $verk = (new MailboxVerkAdapter())->publish($proposal);
    $kontor = (new MailboxKontorAdapter('01JTESTORGANIZATION00000000'))->publish($proposal);
    if(($verk['task_id'] ?? 0) !== 17 || ($kontor['status'] ?? '') !== 'pending') throw new \RuntimeException('Approval adapters did not call verified public APIs.');
    foreach([$modules->verk->metadata, $modules->kontor->metadata] as $metadata) {
        if(isset($metadata['body']) || isset($metadata['url']) || array_keys($metadata) !== ['host', 'folder', 'uid', 'account_id', 'workflow'] || isset($metadata['workflow']['code_fingerprint']) || empty($metadata['workflow']['code_required'])) throw new \RuntimeException('Approval adapter leaked non-redacted proposal data.');
    }
    fwrite(STDOUT, "Mailbox Verk/Kontor adapter smoke tests passed.\n");
}

<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class FakeLog { public $entries = []; public function save($name, $value): void { $this->entries[] = [$name, $value]; } }
    class Wire {
        public static $root;
        public function wire($value = null) { return $value === null ? self::$root : $value; }
    }
    class FakeSquad {
        public $askPrompt = '';
        public $askOptions = [];
        public $runOptions = [];
        public function getProviderDefinitions(): array { return ['openai' => ['label' => 'OpenAI', 'models' => ['fallback' => 'Fallback']], 'disabled' => ['label' => 'Disabled', 'models' => ['hidden' => 'Hidden']]]; }
        public function getProvidersStatus(): array { return ['openai' => ['active' => true], 'disabled' => ['active' => false]]; }
        public function getProviderModels(string $provider): array { return $provider === 'openai' ? ['gpt-test' => 'GPT Test'] : []; }
        public function ask(string $prompt, array $options): array {
            $this->askPrompt = $prompt;
            $this->askOptions = $options;
            return ['success' => true, 'content' => 'summary', 'raw' => ['secret' => true], 'provider' => 'openai', 'model' => 'test', 'usage' => ['total_tokens' => 12]];
        }
        public function run(array $options): array {
            $this->runOptions = $options;
            $message = $options['onTool']('mailbox_message', ['folder' => 'INBOX', 'uid' => 7]);
            $proposal = $options['onTool']('mailbox_propose_confirmation', ['folder' => 'INBOX', 'uid' => 7, 'hash' => str_repeat('a', 64)]);
            if(($message['uid'] ?? 0) !== 7 || ($proposal['status'] ?? '') !== 'pending') throw new \RuntimeException('Squad mailbox tools failed.');
            return ['success' => true, 'content' => 'agent result', 'messages' => [['role' => 'tool', 'content' => 'private']], 'raw' => ['private' => true], 'steps' => 2, 'provider' => 'openai'];
        }
    }
    class FakeModules {
        public $squad;
        public function __construct() { $this->squad = new FakeSquad(); }
        public function isInstalled($name): bool { return $name === 'Squad'; }
        public function get($name) { return $this->squad; }
    }
    class Mailbox extends Wire {
        public $proposalActor = '';
        public $squadProviderModel = 'openai|gpt-test';
        public $squadMaxTokens = 900;
        public $squadTemperature = '0.2';
        public $squadTimeout = 25;
        public $squadMaxSteps = 4;
        public function getAgentMessage(string $folder, int $uid): array { return ['uid' => $uid, 'subject' => 'Test', 'body' => 'Use [mailbox-link:' . str_repeat('a', 64) . ']', 'attachments' => [['name' => 'private.pdf']], 'links' => [['hash' => str_repeat('a', 64), 'host' => 'example.test']]]; }
        public function getAgentLinks(string $folder, int $uid): array { return $this->getAgentMessage($folder, $uid)['links']; }
        public function listMessages(string $folder, int $page, int $limit): array { return ['messages' => [['uid' => 7]], 'page' => $page, 'limit' => $limit]; }
        public function createConfirmationProposal(string $folder, int $uid, string $hash, string $actor): array { $this->proposalActor = $actor; return ['id' => 'proposal', 'status' => 'pending']; }
    }

    require_once dirname(__DIR__) . '/src/MailboxSquadAdapter.php';

    $root = new \stdClass();
    $root->modules = new FakeModules();
    $root->log = new FakeLog();
    Wire::$root = $root;
    $mailbox = new Mailbox();
    $adapter = new MailboxSquadAdapter($mailbox, 'user:9');

    $status = $adapter->status();
    if(empty($status['compatible']) || array_key_exists('version', $status)) throw new \RuntimeException('Squad feature compatibility detection failed.');
    $models = $adapter->modelOptions();
    if(($models['openai|gpt-test'] ?? '') !== 'OpenAI — GPT Test (gpt-test)' || isset($models['disabled|hidden'])) throw new \RuntimeException('Squad active provider/model discovery failed.');
    $analysis = $adapter->analyze('INBOX', 7, 'Summarize', ['cache' => true, 'webSearch' => true, 'keyIndex' => 4]);
    if(($analysis['content'] ?? '') !== 'summary' || isset($analysis['raw'])) throw new \RuntimeException('Squad analysis response was not normalized.');
    $askOptions = $root->modules->squad->askOptions;
    if(($askOptions['cache'] ?? null) !== false || ($askOptions['webSearch'] ?? null) !== false || ($askOptions['promptCache'] ?? null) !== false || isset($askOptions['keyIndex'])) throw new \RuntimeException('Squad unsafe option override was accepted.');
    if(($askOptions['provider'] ?? '') !== 'openai' || ($askOptions['model'] ?? '') !== 'gpt-test' || ($askOptions['maxTokens'] ?? 0) !== 900 || ($askOptions['timeout'] ?? 0) !== 25) throw new \RuntimeException('Configured Squad defaults were not applied.');
    if(strpos($root->modules->squad->askPrompt, '[mailbox-link:') === false || strpos($root->modules->squad->askPrompt, 'https://') !== false || strpos($root->modules->squad->askPrompt, 'private.pdf') !== false) throw new \RuntimeException('Squad did not receive the redacted message DTO.');

    $agent = $adapter->run('Find the confirmation and propose review.', ['maxSteps' => 99]);
    if(($agent['steps'] ?? 0) !== 2 || isset($agent['messages']) || isset($agent['raw']) || $mailbox->proposalActor !== 'user:9') throw new \RuntimeException('Squad agent response or proposal boundary failed.');
    if(($root->modules->squad->runOptions['maxSteps'] ?? 0) !== 6 || count($root->modules->squad->runOptions['tools'] ?? []) !== 4) throw new \RuntimeException('Squad agent bounds or tools are missing.');

    fwrite(STDOUT, "Mailbox Squad adapter smoke tests passed.\n");
}

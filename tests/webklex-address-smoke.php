<?php namespace ProcessWire;

require_once dirname(__DIR__) . '/src/MailboxWebklexTransport.php';

final class MailboxFakeWebklexAttribute {
    private $values;

    public function __construct(array $values) {
        $this->values = $values;
    }

    public function toArray(): array {
        return $this->values;
    }
}

$transport = (new \ReflectionClass(MailboxWebklexTransport::class))->newInstanceWithoutConstructor();
$addressText = new \ReflectionMethod($transport, 'addressText');
$addresses = new \ReflectionMethod($transport, 'addresses');

$attribute = new MailboxFakeWebklexAttribute([
    (object) ['personal' => 'Seek Support', 'mailbox' => 'support', 'host' => 'seek.example', 'mail' => '', 'full' => ''],
    (object) ['personal' => '=?UTF-8?B?0KLQtdGB0YI=?=', 'mail' => 'test@example.com', 'mailbox' => '', 'host' => '', 'full' => ''],
]);
$normalized = $addresses->invoke($transport, $attribute);
if($normalized !== [
    ['name' => 'Seek Support', 'email' => 'support@seek.example'],
    ['name' => 'Тест', 'email' => 'test@example.com'],
]) throw new \RuntimeException('Webklex Attribute address expansion failed.');

$text = $addressText->invoke($transport, $attribute);
if($text !== 'Seek Support <support@seek.example>, Тест <test@example.com>') throw new \RuntimeException('Webklex address display text failed: ' . $text);

$arrayAddress = $addressText->invoke($transport, ['personal' => 'Array Sender', 'mailbox' => 'array', 'host' => 'example.com']);
if($arrayAddress !== 'Array Sender <array@example.com>') throw new \RuntimeException('Associative address fallback failed.');

fwrite(STDOUT, "Mailbox Webklex address smoke tests passed.\n");

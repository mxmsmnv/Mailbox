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

    class RedactionMailbox extends Mailbox {
        public function getMessage(string $folder, int $uid): array {
            $url = 'https://confirm.example.com/activate/secret-path-token?token=secret-query-token';
            return [
                'uid' => $uid,
                'body' => "Open {$url} to confirm.",
                'html' => '<a href="' . $url . '">Confirm</a>',
                'raw' => "X-Secret: secret-query-token\r\n\r\nbody",
                'links' => [[
                    'hash' => hash('sha256', $url),
                    'url' => $url,
                    'host' => 'confirm.example.com',
                    'path' => '/activate/secret-path-token',
                    'label' => 'Confirm',
                    'scheme' => 'https',
                    'confirmation_candidate' => true,
                ]],
                'attachments' => [],
            ];
        }
    }

    $message = (new RedactionMailbox())->getAgentMessage('INBOX', 42);
    $encoded = json_encode($message, JSON_UNESCAPED_SLASHES);
    if(strpos($encoded, 'secret-path-token') !== false || strpos($encoded, 'secret-query-token') !== false) {
        throw new \RuntimeException('Agent DTO leaked a confirmation token.');
    }
    if(isset($message['links'][0]['url']) || isset($message['links'][0]['path'])) {
        throw new \RuntimeException('Agent DTO retained an executable URL or raw path.');
    }
    if(isset($message['html']) || isset($message['raw'])) throw new \RuntimeException('Agent DTO retained HTML or raw MIME source.');
    if(strpos($message['body'], '[mailbox-link:') === false) {
        throw new \RuntimeException('Agent body did not receive a link hash marker.');
    }
    fwrite(STDOUT, "Mailbox agent redaction smoke tests passed.\n");
}

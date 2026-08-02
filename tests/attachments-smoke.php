<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    require_once dirname(__DIR__) . '/src/MailboxAttachmentConcern.php';

    final class AttachmentHarness {
        use MailboxAttachmentConcern;
        private $settings = ['maxAttachmentBytes' => 65536];
        protected function accountSetting(string $name) { return $this->settings[$name] ?? null; }
        public function normalize(array $attachment): array { return $this->normalizeAttachmentResult($attachment); }
    }

    $harness = new AttachmentHarness();
    $attachment = $harness->normalize([
        'part' => '2.1',
        'name' => '../../unsafe' . "\0" . '.txt',
        'type' => 'Text/Plain',
        'content' => 'hello',
    ]);
    if($attachment['part'] !== '2.1' || $attachment['name'] !== 'unsafe_.txt' || $attachment['type'] !== 'text/plain' || $attachment['bytes'] !== 5) throw new \RuntimeException('Attachment normalization failed.');
    if(isset($attachment['path'])) throw new \RuntimeException('Attachment path leaked into the DTO.');

    foreach([
        ['part' => '../2', 'name' => 'x', 'type' => 'text/plain', 'content' => 'x'],
        ['part' => '2', 'name' => 'x', 'type' => "text/plain\r\nX-Test: 1", 'content' => 'x'],
        ['part' => '2', 'name' => 'large.bin', 'type' => 'application/octet-stream', 'content' => str_repeat('x', 65537)],
    ] as $invalid) {
        try {
            $normalized = $harness->normalize($invalid);
            if($invalid['part'] !== '2' || strpos($invalid['type'], "\r") === false) throw new \RuntimeException('Invalid attachment was accepted.');
            if($normalized['type'] !== 'application/octet-stream') throw new \RuntimeException('Unsafe MIME type was not replaced.');
        } catch(WireException $error) {}
    }
    fwrite(STDOUT, "Mailbox attachment boundary smoke tests passed.\n");
}

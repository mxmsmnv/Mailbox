<?php

declare(strict_types=1);

namespace PHPMailer\PHPMailer {
    interface OAuthTokenProvider { public function getOauth64(); }
}

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class WirePermissionException extends WireException {}

    require_once dirname(__DIR__) . '/src/MailboxOAuthTokenProvider.php';
    require_once dirname(__DIR__) . '/src/MailboxSendingConcern.php';

    final class SendingHarness {
        use MailboxSendingConcern;
        public $enableMailSending = 1;
        private $settings = [
            'smtpHost' => 'smtp.example.com',
            'smtpPort' => 587,
            'smtpEncryption' => 'tls',
            'smtpValidateCertificate' => 1,
            'smtpFromAddress' => '',
            'smtpFromName' => 'Mailbox Robot',
        ];
        protected function accountSetting(string $name) { return $this->settings[$name] ?? null; }
        protected function storedCredentials(bool $resolveEnvironment = true): ?array { return ['username' => 'robot@example.com', 'password' => 'secret']; }
        protected function isLoopbackHost(string $host): bool { return in_array($host, ['localhost', '127.0.0.1', '::1'], true); }
        public function normalize(array $message): array { return $this->normalizeOutgoingMessage($message); }
        public function forwardText(string $note, string $header, string $body): string { return $this->boundedForwardText($note, $header, $body); }
    }

    $provider = new MailboxOAuthTokenProvider('robot@example.com', 'access-token');
    $decoded = base64_decode($provider->getOauth64(), true);
    if($decoded !== "user=robot@example.com\x01auth=Bearer access-token\x01\x01") throw new \RuntimeException('XOAUTH2 SMTP payload is invalid.');

    $harness = new SendingHarness();
    $message = $harness->normalize([
        'to' => [
            ['email' => 'USER@example.com', 'name' => 'User'],
            ['email' => 'user@example.com', 'name' => 'Duplicate'],
        ],
        'subject' => 'SMTP smoke test',
        'body' => "Plain text\nbody",
    ]);
    if(count($message['to']) !== 1 || $message['from']['email'] !== 'robot@example.com') throw new \RuntimeException('Outgoing address normalization failed.');

    $lineBreaks = $harness->normalize([
        'to' => ['user@example.com'],
        'subject' => 'Line breaks',
        'body' => "\nCRLF\r\nCR\rLF\nVT\x0BFF\x0CNEL\xC2\x85LS\xE2\x80\xA8PS\xE2\x80\xA9\n",
    ]);
    if($lineBreaks['body'] !== "\nCRLF\nCR\nLF\nVT\nFF\nNEL\nLS\nPS\n\n") throw new \RuntimeException('Plain-text line break normalization failed.');

    $withAttachment = $harness->normalize([
        'to' => ['user@example.com'], 'subject' => 'Attachment', 'body' => 'See attachment.',
        'attachments' => [['name' => 'report.txt', 'type' => 'text/plain', 'content_base64' => base64_encode('bounded attachment')]],
    ]);
    if(($withAttachment['attachments'][0]['content'] ?? '') !== 'bounded attachment' || ($withAttachment['attachments'][0]['bytes'] ?? 0) !== 18) throw new \RuntimeException('Outgoing attachment normalization failed.');
    try {
        $harness->normalize(['to' => ['user@example.com'], 'subject' => 'Unsafe', 'body' => 'Text', 'attachments' => [['name' => '../secret.txt', 'content' => 'x']]]);
        throw new \RuntimeException('Unsafe outgoing attachment name was accepted.');
    } catch(WireException $error) {}
    try {
        $harness->normalize(['to' => ['user@example.com'], 'subject' => 'Large', 'body' => 'Text', 'attachments' => [['name' => 'large.bin', 'content' => str_repeat('x', 10485761)]]]);
        throw new \RuntimeException('Oversized outgoing attachment was accepted.');
    } catch(WireException $error) {}

    try {
        $harness->normalize(['to' => ['user@example.com'], 'subject' => "Injected\r\nBcc: target@example.com", 'body' => 'Text']);
        throw new \RuntimeException('Header injection was accepted.');
    } catch(WireException $error) {
        if(strpos($error->getMessage(), 'Subject') === false) throw $error;
    }

    $forwarded = $harness->forwardText('Note', "\nHeaders\n", str_repeat('x', 1100000));
    if(strlen($forwarded) > 1048576 || strpos($forwarded, '[Forwarded message body truncated by Mailbox]') === false) throw new \RuntimeException('Forward body bound failed.');

    try {
        $harness->normalize(['to' => ['invalid'], 'subject' => 'Test', 'body' => 'Text']);
        throw new \RuntimeException('Invalid recipient was accepted.');
    } catch(WireException $error) {
        if(strpos($error->getMessage(), 'recipient') === false) throw $error;
    }

    $smtpSource = file_get_contents(dirname(__DIR__) . '/src/MailboxSmtpTransport.php');
    $sendingSource = file_get_contents(dirname(__DIR__) . '/src/MailboxSendingConcern.php');
    if(strpos((string) $smtpSource, 'addStringAttachment') === false || strpos((string) $sendingSource, 'appendSentCopy') === false || strpos((string) $sendingSource, "unset(\$result['_mime'])") === false) throw new \RuntimeException('Attachment or redacted Sent-copy boundary is missing.');
    $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
    $lock = (string) file_get_contents(dirname(__DIR__) . '/composer.lock');
    if(($composer['require']['phpmailer/phpmailer'] ?? '') !== '^6.12' || strpos($lock, 'phpmailer/phpmailer') === false || strpos($lock, 'v6.12.0') === false) throw new \RuntimeException('Locked PHPMailer runtime dependency is missing.');

    fwrite(STDOUT, "Mailbox SMTP sending smoke tests passed.\n");
}

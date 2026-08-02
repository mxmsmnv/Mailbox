<?php namespace ProcessWire;

/** TLS-only SMTP transport backed by PHPMailer >=6.12. */
final class MailboxSmtpTransport {

    public static function load(?string $siteRoot = null, ?string $runtimeRoot = null): bool {
        if(class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return self::assertSupportedVersion();
        $candidates = [];
        if($runtimeRoot) $candidates[] = rtrim($runtimeRoot, '/\\') . '/vendor/autoload.php';
        $candidates[] = dirname(__DIR__) . '/vendor/autoload.php';
        if($siteRoot) $candidates[] = rtrim($siteRoot, '/\\') . '/vendor/autoload.php';
        foreach($candidates as $autoload) {
            if(is_file($autoload)) require_once $autoload;
            if(class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return self::assertSupportedVersion();
        }
        return false;
    }

    private static function assertSupportedVersion(): bool {
        $installed = 'Composer\\InstalledVersions';
        if(!class_exists($installed) || !$installed::isInstalled('phpmailer/phpmailer')) {
            throw new WireException('Unable to verify the installed phpmailer/phpmailer version. Install version 6.12 or newer.');
        }
        $version = (string) ($installed::getVersion('phpmailer/phpmailer') ?: '0');
        if(version_compare($version, '6.12.0.0', '<')) {
            throw new WireException('SMTP sending requires phpmailer/phpmailer 6.12 or newer.');
        }
        return true;
    }

    public static function parseAddresses(string $value): array {
        if(!self::load()) return [];
        $class = 'PHPMailer\\PHPMailer\\PHPMailer';
        $parsed = $class::parseAddresses($value, function_exists('imap_rfc822_parse_adrlist'));
        $result = [];
        foreach($parsed as $address) {
            $email = trim((string) ($address['address'] ?? ''));
            if($email === '') continue;
            $result[] = ['email' => $email, 'name' => trim((string) ($address['name'] ?? ''))];
        }
        return $result;
    }

    private $settings;
    private $username;
    private $secret;
    private $oauth;

    public function __construct(array $settings, string $username, string $secret, bool $oauth) {
        $this->settings = $settings;
        $this->username = $username;
        $this->secret = $secret;
        $this->oauth = $oauth;
    }

    public function send(array $message): array {
        $mailer = $this->mailer();
        try {
            $mailer->setFrom((string) $message['from']['email'], (string) $message['from']['name'], false);
            foreach($message['to'] as $address) $mailer->addAddress($address['email'], $address['name']);
            foreach($message['cc'] as $address) $mailer->addCC($address['email'], $address['name']);
            foreach($message['bcc'] as $address) $mailer->addBCC($address['email'], $address['name']);
            foreach($message['reply_to'] as $address) $mailer->addReplyTo($address['email'], $address['name']);
            foreach($message['attachments'] as $attachment) {
                $mailer->addStringAttachment(
                    (string) $attachment['content'],
                    (string) $attachment['name'],
                    $class::ENCODING_BASE64,
                    (string) $attachment['type'],
                    'attachment'
                );
            }
            $mailer->Subject = (string) $message['subject'];
            $mailer->Body = (string) $message['body'];
            $mailer->isHTML(false);
            if((string) $message['in_reply_to'] !== '') {
                $mailer->addCustomHeader('In-Reply-To', '<' . $message['in_reply_to'] . '>');
                $mailer->addCustomHeader('References', '<' . $message['in_reply_to'] . '>');
            }
            $mailer->send();
            return [
                'sent' => true,
                'message_id' => trim((string) $mailer->getLastMessageID(), '<>'),
                'recipients' => count($message['to']) + count($message['cc']) + count($message['bcc']),
                'attachments' => count($message['attachments']),
                '_mime' => (string) $mailer->getSentMIMEMessage(),
            ];
        } finally {
            try { $mailer->smtpClose(); } catch(\Throwable $error) {}
        }
    }

    public function test(): array {
        $mailer = $this->mailer();
        try {
            if(!$mailer->smtpConnect()) throw new WireException('Unable to connect or authenticate to the SMTP server.');
            return ['ok' => true, 'authenticated' => true];
        } finally {
            try { $mailer->smtpClose(); } catch(\Throwable $error) {}
        }
    }

    private function mailer() {
        $class = 'PHPMailer\\PHPMailer\\PHPMailer';
        $mailer = new $class(true);
        $mailer->isSMTP();
        $mailer->Host = (string) $this->settings['smtpHost'];
        $mailer->Port = (int) $this->settings['smtpPort'];
        $mailer->SMTPAuth = true;
        $mailer->SMTPAutoTLS = false;
        $mailer->SMTPSecure = (string) $this->settings['smtpEncryption'] === 'ssl'
            ? $class::ENCRYPTION_SMTPS
            : $class::ENCRYPTION_STARTTLS;
        $mailer->Timeout = max(1, min(300, (int) ($this->settings['writeTimeout'] ?? 30)));
        $mailer->CharSet = $class::CHARSET_UTF8;
        $mailer->Encoding = $class::ENCODING_QUOTED_PRINTABLE;
        $mailer->Username = $this->username;
        $verifyCertificate = !empty($this->settings['smtpValidateCertificate']);
        $mailer->SMTPOptions = ['ssl' => [
            'verify_peer' => $verifyCertificate,
            'verify_peer_name' => $verifyCertificate,
            'allow_self_signed' => !$verifyCertificate,
        ]];
        if($this->oauth) {
            if(!interface_exists('PHPMailer\\PHPMailer\\OAuthTokenProvider')) throw new WireException('PHPMailer OAuth support is unavailable.');
            require_once __DIR__ . '/MailboxOAuthTokenProvider.php';
            $mailer->AuthType = 'XOAUTH2';
            $mailer->setOAuth(new MailboxOAuthTokenProvider($this->username, $this->secret));
        } else {
            $mailer->Password = $this->secret;
        }
        return $mailer;
    }
}

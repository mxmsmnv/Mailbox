<?php namespace ProcessWire;

/** Minimal PHPMailer XOAUTH2 provider for an already refreshed access token. */
final class MailboxOAuthTokenProvider implements \PHPMailer\PHPMailer\OAuthTokenProvider {

    private $username;
    private $accessToken;

    public function __construct(string $username, string $accessToken) {
        $this->username = $username;
        $this->accessToken = $accessToken;
    }

    public function getOauth64() {
        return base64_encode('user=' . $this->username . "\x01auth=Bearer " . $this->accessToken . "\x01\x01");
    }
}

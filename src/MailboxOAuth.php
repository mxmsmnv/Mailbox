<?php namespace ProcessWire;

/** OAuth 2.0 Authorization Code + PKCE client for IMAP providers. */
final class MailboxOAuth extends Wire {

    private const SESSION_KEY = 'MailboxOAuthStates';
    private const MAX_RESPONSE_BYTES = 1048576;

    /** @var Mailbox */
    private $mailbox;

    public function __construct(Mailbox $mailbox) {
        $this->mailbox = $mailbox;
    }

    public static function providers(): array {
        return [
            'google' => [
                'label' => 'Google / Gmail',
                'authorization' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token' => 'https://oauth2.googleapis.com/token',
                'scopes' => ['openid', 'email', 'https://mail.google.com/'],
            ],
            'microsoft' => [
                'label' => 'Microsoft 365 / Outlook.com',
                'authorization' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
                'token' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
                'scopes' => ['offline_access', 'openid', 'email', 'https://outlook.office.com/IMAP.AccessAsUser.All'],
            ],
        ];
    }

    public function authorizationUrl(int $accountId, string $redirectUri): string {
        $account = $this->mailbox->getAccount($accountId);
        $settings = $account['settings'];
        $provider = $this->provider($settings);
        $clientId = $this->clientId($settings);
        $identity = $this->credentialStore($accountId)->get();
        if(!$identity || trim((string) $identity['username']) === '') throw new WireException('Set the encrypted OAuth mailbox username before connecting.');
        $redirectUri = $this->redirectUri($redirectUri);
        $state = bin2hex(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(64));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $states = $this->sessionStates();
        while(count($states) >= 10) array_shift($states);
        $states[hash('sha256', $state)] = [
            'account_id' => $accountId,
            'provider' => (string) $settings['oauthProvider'],
            'verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'expires_at' => time() + 600,
        ];
        $this->saveSessionStates($states);
        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => implode(' ', $this->providerScopes($provider, $settings)),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        if($settings['oauthProvider'] === 'google') {
            $params['access_type'] = 'offline';
            $params['prompt'] = 'consent';
        }
        return $this->endpoint($provider['authorization'], $settings) . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function complete(string $state, string $code, string $redirectUri): array {
        if(!preg_match('/^[a-f0-9]{64}$/', $state) || $code === '') throw new WireException('Invalid OAuth callback.');
        $states = $this->sessionStates();
        $key = hash('sha256', $state);
        $pending = $states[$key] ?? null;
        unset($states[$key]);
        $this->saveSessionStates($states);
        if(!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < time()) throw new WireException('OAuth state is invalid or expired.');
        $redirectUri = $this->redirectUri($redirectUri);
        if(!hash_equals((string) $pending['redirect_uri'], $redirectUri)) throw new WireException('OAuth redirect URI mismatch.');
        $accountId = (int) $pending['account_id'];
        $account = $this->mailbox->getAccount($accountId);
        $settings = $account['settings'];
        if(!hash_equals((string) $pending['provider'], (string) $settings['oauthProvider'])) throw new WireException('OAuth provider changed during authorization.');
        $provider = $this->provider($settings);
        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId($settings),
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => (string) $pending['verifier'],
        ];
        $secret = $this->clientSecret($accountId);
        if($secret !== '') $params['client_secret'] = $secret;
        $tokens = $this->tokenRequest($this->endpoint($provider['token'], $settings), $params);
        $stored = $this->credentialStore($accountId)->get();
        $username = $stored ? trim((string) $stored['username']) : '';
        if($username === '') throw new WireException('Set the encrypted OAuth mailbox username before connecting.');
        $payload = $this->normalizeTokens($tokens, null, (string) $settings['oauthProvider']);
        $this->credentialStore($accountId)->save($username, $this->encodeTokens($payload));
        return $this->status($accountId, $payload);
    }

    public function accessToken(int $accountId): string {
        $stored = $this->credentialStore($accountId)->get();
        if(!$stored) throw new WireException('OAuth credentials are not connected.');
        $tokens = $this->decodeTokens((string) $stored['password']);
        if((int) ($tokens['expires_at'] ?? 0) > time() + 60 && !empty($tokens['access_token'])) return (string) $tokens['access_token'];
        $refreshToken = (string) ($tokens['refresh_token'] ?? '');
        if($refreshToken === '') throw new WireException('OAuth refresh token is unavailable; reconnect the account.');
        $account = $this->mailbox->getAccount($accountId);
        $settings = $account['settings'];
        $provider = $this->provider($settings);
        $params = [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId($settings),
            'refresh_token' => $refreshToken,
        ];
        $secret = $this->clientSecret($accountId);
        if($secret !== '') $params['client_secret'] = $secret;
        $fresh = $this->tokenRequest($this->endpoint($provider['token'], $settings), $params);
        $tokens = $this->normalizeTokens($fresh, $tokens, (string) $settings['oauthProvider']);
        $this->credentialStore($accountId)->save((string) $stored['username'], $this->encodeTokens($tokens));
        return (string) $tokens['access_token'];
    }

    public function disconnect(int $accountId): void {
        $this->credentialStore($accountId)->delete();
    }

    /** Store only the encrypted mailbox identity until authorization supplies tokens. */
    public function prepareIdentity(int $accountId, string $username, string $provider): void {
        $username = trim($username);
        if($username === '' || strlen($username) > 320 || preg_match('/[\x00-\x20]/', $username)) throw new WireException('Invalid OAuth mailbox username.');
        if(!isset(self::providers()[$provider])) throw new WireException('Invalid OAuth provider.');
        $this->credentialStore($accountId)->save($username, $this->encodeTokens([
            'provider' => $provider,
            'connected' => false,
            'access_token' => '',
            'refresh_token' => '',
            'expires_at' => 0,
        ]));
    }

    private function provider(array $settings): array {
        $name = (string) ($settings['oauthProvider'] ?? '');
        $providers = self::providers();
        if(($settings['authentication'] ?? '') !== 'oauth' || !isset($providers[$name])) throw new WireException('OAuth provider is not configured for this account.');
        return $providers[$name];
    }

    private function providerScopes(array $provider, array $settings): array {
        $scopes = $provider['scopes'];
        if((string) ($settings['oauthProvider'] ?? '') === 'microsoft' && (bool) $this->mailbox->enableMailSending) {
            $scopes[] = 'https://outlook.office.com/SMTP.Send';
        }
        return array_values(array_unique($scopes));
    }

    private function credentialStore(int $accountId): MailboxCredentials {
        return $this->wire(new MailboxCredentials($accountId));
    }

    private function encodeTokens(array $tokens): string {
        $json = json_encode($tokens, JSON_UNESCAPED_SLASHES);
        if(!is_string($json)) throw new WireException('Unable to encode OAuth tokens.');
        return 'oauth:v1:' . base64_encode($json);
    }

    private function decodeTokens(string $stored): array {
        if(strpos($stored, 'oauth:v1:') !== 0) throw new WireException('Account does not contain OAuth credentials.');
        $json = base64_decode(substr($stored, 9), true);
        $tokens = is_string($json) ? json_decode($json, true) : null;
        if(!is_array($tokens) || empty($tokens['provider'])) throw new WireException('OAuth credential payload is invalid.');
        return $tokens;
    }

    private function endpoint(string $template, array $settings): string {
        $tenant = trim((string) ($settings['oauthTenant'] ?? 'common'));
        if($tenant === '') $tenant = 'common';
        if(!preg_match('/^(common|organizations|consumers|[a-f0-9-]{36}|[A-Za-z0-9.-]{1,253})$/', $tenant)) throw new WireException('Invalid Microsoft OAuth tenant.');
        return str_replace('{tenant}', rawurlencode($tenant), $template);
    }

    private function clientId(array $settings): string {
        $clientId = trim((string) ($settings['oauthClientId'] ?? ''));
        if($clientId === '' || strlen($clientId) > 255 || preg_match('/[\x00-\x20]/', $clientId)) throw new WireException('OAuth client ID is not configured.');
        return $clientId;
    }

    private function clientSecret(int $accountId): string {
        $config = $this->wire('config');
        $provider = $config->mailboxOAuthClientSecretProvider ?? null;
        if(is_callable($provider)) $secret = (string) $provider($accountId);
        $secrets = $config->mailboxOAuthClientSecrets ?? [];
        if(!isset($secret)) $secret = is_array($secrets) && isset($secrets[$accountId]) ? (string) $secrets[$accountId] : '';
        if(strlen($secret) > 4096 || strpos($secret, "\0") !== false) throw new WireException('Invalid OAuth client secret.');
        return $secret;
    }

    private function tokenRequest(string $url, array $params): array {
        if(!function_exists('curl_init')) throw new WireException('PHP cURL is required for OAuth token exchange.');
        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$body): int {
                if(strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        unset($curl);
        if($ok === false || strlen($body) > self::MAX_RESPONSE_BYTES) throw new WireException('OAuth provider response failed or exceeded the size limit.');
        $json = json_decode($body, true);
        if($status < 200 || $status >= 300 || !is_array($json) || empty($json['access_token'])) {
            $code = is_array($json) ? preg_replace('/[^a-z0-9_.-]/i', '', (string) ($json['error'] ?? 'token_error')) : 'transport_error';
            throw new WireException('OAuth token exchange failed (' . ($code ?: 'token_error') . ($error !== '' ? ', transport' : '') . ').');
        }
        return $json;
    }

    private function normalizeTokens(array $fresh, ?array $previous, string $provider): array {
        foreach(['access_token' => 16384, 'refresh_token' => 16384, 'token_type' => 64, 'scope' => 8192] as $field => $limit) {
            if(isset($fresh[$field]) && (!is_string($fresh[$field]) || strlen($fresh[$field]) > $limit || strpos($fresh[$field], "\0") !== false)) throw new WireException('OAuth provider returned an invalid token payload.');
        }
        $refresh = (string) ($fresh['refresh_token'] ?? ($previous['refresh_token'] ?? ''));
        if($refresh === '' || strlen($refresh) > 16384 || strpos($refresh, "\0") !== false) throw new WireException('OAuth provider did not return a valid refresh token. Reconnect with offline access consent.');
        return [
            'provider' => $provider,
            'access_token' => (string) $fresh['access_token'],
            'refresh_token' => $refresh,
            'token_type' => (string) ($fresh['token_type'] ?? 'Bearer'),
            'scope' => (string) ($fresh['scope'] ?? ($previous['scope'] ?? '')),
            'expires_at' => time() + max(60, min(86400, (int) ($fresh['expires_in'] ?? 3600))),
        ];
    }

    private function status(int $accountId, array $tokens): array {
        return ['connected' => true, 'account_id' => $accountId, 'provider' => (string) $tokens['provider'], 'expires_at' => (int) $tokens['expires_at'], 'has_refresh_token' => !empty($tokens['refresh_token'])];
    }

    private function redirectUri(string $value): string {
        $parts = parse_url($value);
        if(!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new WireException('OAuth redirect URI must be an absolute HTTPS URL without credentials or fragment.');
        }
        return $value;
    }

    private function sessionStates(): array {
        $states = $this->wire('session')->get(self::SESSION_KEY);
        if(!is_array($states)) return [];
        return array_filter($states, static function($state): bool { return is_array($state) && (int) ($state['expires_at'] ?? 0) >= time(); });
    }

    private function saveSessionStates(array $states): void {
        $this->wire('session')->set(self::SESSION_KEY, $states);
    }

    private function base64Url(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

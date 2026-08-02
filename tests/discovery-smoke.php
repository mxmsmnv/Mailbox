<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    require_once dirname(__DIR__) . '/src/MailboxDiscovery.php';

    $xml = <<<'XML'
<?xml version="1.0"?>
<clientConfig><emailProvider id="example.test">
  <incomingServer type="imap"><hostname>imap.example.test</hostname><port>993</port><socketType>SSL</socketType><username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication></incomingServer>
  <outgoingServer type="smtp"><hostname>smtp.example.test</hostname><port>587</port><socketType>STARTTLS</socketType><username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication></outgoingServer>
</emailProvider></clientConfig>
XML;
    $queries = [];
    $resolver = static function(string $name) use (&$queries): array {
        $queries[] = $name;
        if($name === '_imaps._tcp.example.test') return [['target' => 'mail.example.test.', 'port' => 993, 'pri' => 10, 'weight' => 0]];
        if($name === '_submissions._tcp.example.test') return [['target' => 'mail.example.test.', 'port' => 465, 'pri' => 10, 'weight' => 0]];
        return [];
    };
    $fetcher = static function(string $url, int $limit) use ($xml): ?string {
        if($limit !== 131072) throw new \RuntimeException('Autoconfig XML limit changed unexpectedly.');
        return strpos($url, 'autoconfig.example.test') !== false ? $xml : null;
    };
    $presets = [
        'gmail' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'validateCertificate' => 1, 'authentication' => 'oauth', 'oauthProvider' => 'google', 'smtpHost' => 'smtp.gmail.com', 'smtpPort' => 587, 'smtpEncryption' => 'tls', 'smtpValidateCertificate' => 1],
    ];
    $discovery = new MailboxDiscovery($presets, $resolver, $fetcher);
    $result = $discovery->discover('User@Example.Test');
    if($result['credentials_used'] !== false || $result['applied'] !== false || $result['email'] !== 'User@example.test') throw new \RuntimeException('Discovery safety metadata is invalid.');
    if(count($result['candidates']) !== 2) throw new \RuntimeException('Expected distinct SRV and autoconfig candidates.');
    $srv = $result['candidates'][0];
    if($srv['source'] !== 'dns-srv' || $srv['settings']['host'] !== 'mail.example.test' || $srv['settings']['smtpPort'] !== 465) throw new \RuntimeException('RFC SRV discovery failed.');
    $auto = $result['candidates'][1];
    if($auto['source'] !== 'provider-autoconfig' || $auto['settings']['host'] !== 'imap.example.test' || $auto['settings']['smtpEncryption'] !== 'tls' || $auto['username'] !== 'User@example.test') throw new \RuntimeException('Thunderbird autoconfig parsing failed.');
    if(!in_array('_imaps._tcp.example.test', $queries, true) || !in_array('_submissions._tcp.example.test', $queries, true)) throw new \RuntimeException('Required secure SRV records were not queried.');

    $gmail = $discovery->discover('person@gmail.com');
    if(($gmail['candidates'][0]['settings']['preset'] ?? '') !== 'gmail' || ($gmail['candidates'][0]['settings']['authentication'] ?? '') !== 'oauth') throw new \RuntimeException('Hosted provider mapping failed.');

    foreach(['invalid', 'user@127.0.0.1', "user@example.com\0.test"] as $invalid) {
        try { $discovery->discover($invalid); throw new \RuntimeException('Invalid discovery identity was accepted.'); } catch(WireException $error) {}
    }

    $dangerousXml = '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><clientConfig>&e;</clientConfig>';
    $dangerous = new MailboxDiscovery([], static function(string $name): array { return []; }, static function(string $url, int $limit) use ($dangerousXml): string { return $dangerousXml; });
    if($dangerous->discover('user@example.test')['candidates'] !== []) throw new \RuntimeException('XML entity payload was accepted.');

    $source = file_get_contents(dirname(__DIR__) . '/src/MailboxDiscovery.php');
    foreach(['CURLOPT_RESOLVE', "CURLOPT_PROXY => ''", 'CURLOPT_FOLLOWLOCATION => false', 'LIBXML_NONET', 'credentials_used'] as $needle) if(strpos((string) $source, $needle) === false) throw new \RuntimeException('Missing discovery security boundary: ' . $needle);
    fwrite(STDOUT, "Mailbox autodiscovery smoke tests passed.\n");
}

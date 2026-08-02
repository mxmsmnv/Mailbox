<?php namespace ProcessWire;

/** Editable hosted and self-hosted IMAP preset catalogue. */
trait MailboxPresetsConcern {
    public function getPresets(): array {
        $common = [
            'port' => 993,
            'encryption' => 'ssl',
            'imapTransport' => 'auto',
            'validateCertificate' => 1,
            'secureAuthentication' => 0,
            'disableAuthenticator' => '',
            'defaultFolder' => 'INBOX',
            'folderPattern' => '*',
            'authentication' => 'password',
            'oauthProvider' => '',
            'smtpHost' => 'mail.example.com',
            'smtpPort' => 587,
            'smtpEncryption' => 'tls',
            'smtpValidateCertificate' => 1,
            'supported' => true,
        ];

        $presets = [
            'custom' => $common + [
                'label' => 'Custom IMAP server',
                'group' => 'Custom',
                'host' => '',
                'smtpHost' => '',
                'note' => 'Enter the settings supplied by the server administrator.',
            ],
            'gmail' => $common + [
                'label' => 'Gmail / Google Workspace',
                'group' => 'Hosted',
                'host' => 'imap.gmail.com',
                'authentication' => 'oauth',
                'oauthProvider' => 'google',
                'smtpHost' => 'smtp.gmail.com',
                'note' => 'OAuth2/XOAUTH2 is recommended. Register an OAuth client, save its client ID and mailbox address, then connect the account.',
            ],
            'microsoft365' => $common + [
                'label' => 'Microsoft 365 / Outlook.com',
                'group' => 'Hosted',
                'host' => 'outlook.office365.com',
                'authentication' => 'oauth',
                'oauthProvider' => 'microsoft',
                'smtpHost' => 'smtp.office365.com',
                'note' => 'Microsoft requires OAuth2/Modern Auth. Register an OAuth client with delegated IMAP permission, then connect the account.',
            ],
            'icloud' => $common + [
                'label' => 'Apple iCloud Mail',
                'group' => 'Hosted',
                'host' => 'imap.mail.me.com',
                'smtpHost' => 'smtp.mail.me.com',
                'note' => 'Use an app-specific password. The username may be the local part or the full iCloud address.',
            ],
            'yahoo' => $common + [
                'label' => 'Yahoo Mail',
                'group' => 'Hosted',
                'host' => 'imap.mail.yahoo.com',
                'smtpHost' => 'smtp.mail.yahoo.com',
                'note' => 'Use the full email address and a Yahoo app password.',
            ],
            'fastmail' => $common + [
                'label' => 'Fastmail',
                'group' => 'Hosted',
                'host' => 'imap.fastmail.com',
                'smtpHost' => 'smtp.fastmail.com',
                'note' => 'Fastmail requires an app password and a plan that includes IMAP access.',
            ],
            'zoho' => $common + [
                'label' => 'Zoho Mail',
                'group' => 'Hosted',
                'host' => 'imap.zoho.com',
                'smtpHost' => 'smtp.zoho.com',
                'note' => 'Use the full email address. Some organizations or regions use a different Zoho hostname.',
            ],
            'dovecot' => $common + [
                'label' => 'Dovecot',
                'group' => 'Open source / self-hosted',
                'host' => 'mail.example.com',
                'note' => 'Template for implicit TLS on port 993. Replace the hostname with the deployed Dovecot endpoint.',
            ],
            'cyrus' => $common + [
                'label' => 'Cyrus IMAP',
                'group' => 'Open source / self-hosted',
                'host' => 'mail.example.com',
                'note' => 'Template for implicit TLS on port 993. Replace the hostname with the deployed Cyrus endpoint.',
            ],
            'stalwart' => $common + [
                'label' => 'Stalwart Mail Server',
                'group' => 'Open source / self-hosted',
                'host' => 'mail.example.com',
                'note' => 'Template for the standard Stalwart implicit-TLS IMAP listener on port 993.',
            ],
            'mailcow' => $common + [
                'label' => 'mailcow',
                'group' => 'Open source / self-hosted',
                'host' => 'mail.example.com',
                'note' => 'Use the mailcow server hostname and the complete mailbox address.',
            ],
            'mailu' => $common + [
                'label' => 'Mailu',
                'group' => 'Open source / self-hosted',
                'host' => 'mail.example.com',
                'note' => 'Use the public IMAP hostname configured for the Mailu deployment.',
            ],
            'docker-mailserver' => $common + [
                'label' => 'docker-mailserver',
                'group' => 'Open source / self-hosted',
                'host' => 'mail.example.com',
                'note' => 'Use the container deployment hostname exposed to the ProcessWire server.',
            ],
            'proton-bridge' => $common + [
                'label' => 'Proton Mail Bridge',
                'group' => 'Local bridge',
                'host' => '127.0.0.1',
                'smtpHost' => '127.0.0.1',
                'smtpPort' => 1025,
                'smtpValidateCertificate' => 0,
                'port' => 1143,
                'encryption' => 'tls',
                'validateCertificate' => 0,
                'disableAuthenticator' => 'GSSAPI',
                'note' => 'Bridge must run on the ProcessWire host or be securely reachable. Use the Bridge-generated IMAP username and password.',
            ],
            'hestiacp' => $common + [
                'label' => 'HestiaCP',
                'group' => 'Control panels / hosting',
                'host' => 'mail.example.com',
                'note' => 'Template for HestiaCP with Dovecot IMAPS. Use the mail server hostname shown for the account in Hestia and make sure its TLS certificate matches; sign in with the full email address.',
            ],
            'vestacp' => $common + [
                'label' => 'VestaCP',
                'group' => 'Control panels / hosting',
                'host' => 'example.com',
                'port' => 143,
                'encryption' => 'tls',
                'note' => 'VestaCP documents IMAP on port 143 with STARTTLS and a full email address as username. Replace the host with the certificate-matching value shown by the server.',
            ],
            'cyberpanel' => $common + [
                'label' => 'CyberPanel',
                'group' => 'Control panels / hosting',
                'host' => 'mail.example.com',
                'note' => 'CyberPanel recommends IMAPS on port 993 with SSL/TLS and the full email address as username. Use the hostname configured for the mail certificate.',
            ],
            'ispconfig' => $common + [
                'label' => 'ISPConfig',
                'group' => 'Control panels / hosting',
                'host' => 'mail.example.com',
                'note' => 'Template for a typical ISPConfig Dovecot/Courier deployment using IMAPS on port 993. ISPConfig installations vary, so verify the enabled IMAP service, hostname, port, and certificate in the server configuration.',
            ],
            'cpanel' => $common + [
                'label' => 'cPanel / WHM',
                'group' => 'Control panels / hosting',
                'host' => 'mail.example.com',
                'note' => 'cPanel recommends IMAPS on port 993. Use the incoming server shown under Mail Client Manual Settings because cPanel may select the account domain or server hostname according to certificate status.',
            ],
            'plesk' => $common + [
                'label' => 'Plesk',
                'group' => 'Control panels / hosting',
                'host' => 'mail.example.com',
                'note' => 'Template for Plesk IMAP over SSL on port 993. Use the incoming server shown in Mail Client Setup; Linux/Windows mail stacks and certificate hostnames may differ.',
            ],
            'directadmin' => $common + [
                'label' => 'DirectAdmin',
                'group' => 'Control panels / hosting',
                'host' => 'mail.example.com',
                'note' => 'DirectAdmin documents IMAPS on port 993 with SSL/TLS, normal-password authentication, and the full email address. Use the hostname supplied by the host so TLS validation succeeds.',
            ],
            'poste-io' => $common + [
                'label' => 'Poste.io',
                'group' => 'Mail platforms / appliances',
                'host' => 'mail.example.com',
                'note' => 'Poste.io recommends IMAPS on port 993 with TLS and the full email address as username. Replace the hostname with the deployed Poste.io mail hostname.',
            ],
            'iredmail' => $common + [
                'label' => 'iRedMail',
                'group' => 'Mail platforms / appliances',
                'host' => 'mail.example.com',
                'note' => 'iRedMail supports IMAPS on port 993 with SSL or IMAP on port 143 with STARTTLS. This template selects port 993; use the full email address and the certificate-matching mail hostname.',
            ],
            'mail-in-a-box' => $common + [
                'label' => 'Mail-in-a-Box',
                'group' => 'Mail platforms / appliances',
                'host' => 'box.example.com',
                'note' => 'Template for Mail-in-a-Box IMAPS on port 993. Replace the host with the primary box hostname and use the complete email address.',
            ],
            'modoboa' => $common + [
                'label' => 'Modoboa',
                'group' => 'Mail platforms / appliances',
                'host' => 'mail.example.com',
                'note' => 'Template for a secured Modoboa deployment backed by Dovecot IMAPS on port 993. Modoboa can use customized IMAP settings, so verify the external listener and certificate hostname.',
            ],
        ];

        $presets['proton-bridge']['port'] = 1143;
        $presets['proton-bridge']['encryption'] = 'tls';
        $presets['proton-bridge']['validateCertificate'] = 0;
        $presets['proton-bridge']['disableAuthenticator'] = 'GSSAPI';
        $presets['vestacp']['port'] = 143;
        $presets['vestacp']['encryption'] = 'tls';
        return $presets;
    }
}

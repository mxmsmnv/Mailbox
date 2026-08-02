<?php namespace ProcessWire;

/** Bounded installer for the exact Composer runtime recorded in composer.lock. */
final class MailboxDependencyInstaller {
    private $moduleRoot;
    private $runtimeRoot;
    private $configuredComposer;
    private const TIMEOUT_SECONDS = 55;
    private const MAX_METADATA_BYTES = 2097152;

    public function __construct(string $moduleRoot, ?string $configuredComposer = null, ?string $runtimeRoot = null) {
        $realRoot = realpath($moduleRoot);
        if($realRoot === false || !is_dir($realRoot)) throw new WireException('Mailbox module directory is unavailable.');
        $this->moduleRoot = $realRoot;
        $this->runtimeRoot = $runtimeRoot === null ? $realRoot : rtrim($runtimeRoot, '/\\');
        if($this->runtimeRoot === '' || $this->runtimeRoot[0] !== '/') throw new WireException('Mailbox runtime directory must be an absolute path.');
        $this->configuredComposer = $configuredComposer;
    }

    public function status(): array {
        $this->prepareRuntime();
        $version = $this->installedPhpMailerVersion();
        $locked = $this->lockedPhpMailerVersion();
        $webklexVersion = $this->installedPackageVersion('webklex/php-imap');
        $lockedWebklex = $this->lockedPackageVersion('webklex/php-imap');
        $composer = $this->composerPath();
        $writable = is_dir($this->runtimeRoot . '/vendor') ? is_writable($this->runtimeRoot . '/vendor') : is_writable($this->runtimeRoot);
        $ready = $version !== null && version_compare(ltrim($version, 'v'), '6.12.0', '>=')
            && $webklexVersion !== null && version_compare(ltrim($webklexVersion, 'v'), '5.3.0', '>=');
        return [
            'runtime_ready' => $ready,
            'phpmailer_version' => $version,
            'locked_version' => $locked,
            'webklex_version' => $webklexVersion,
            'locked_webklex_version' => $lockedWebklex,
            'runtime_path' => $this->runtimeRoot,
            'composer_available' => $composer !== null && function_exists('proc_open'),
            'runtime_writable' => $writable,
            'can_install' => $composer !== null && function_exists('proc_open') && $writable && $locked !== null && $lockedWebklex !== null,
        ];
    }

    public function install(): array {
        $before = $this->status();
        if(empty($before['can_install'])) throw new WireException('Composer, composer.lock, proc_open, and a writable Mailbox runtime directory are required.');
        $composer = $this->composerPath();
        if($composer === null) throw new WireException('Composer executable is unavailable.');
        $lockPath = rtrim(sys_get_temp_dir(), '/\\') . '/processwire-mailbox-' . hash('sha256', $this->runtimeRoot) . '.lock';
        $lock = @fopen($lockPath, 'c');
        if(!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) throw new WireException('Another Mailbox runtime installation is already running.');
        try {
            $command = [$composer, 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--optimize-autoloader', '--no-plugins', '--no-scripts'];
            if(substr(strtolower($composer), -5) === '.phar') array_unshift($command, PHP_BINARY);
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = @proc_open($command, $descriptors, $pipes, $this->runtimeRoot, null, ['bypass_shell' => true]);
            if(!is_resource($process)) throw new WireException('Composer could not be started by the PHP runtime.');
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $started = microtime(true);
            $exitCode = null;
            do {
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                $processStatus = proc_get_status($process);
                if(!$processStatus['running']) {
                    $exitCode = (int) $processStatus['exitcode'];
                    break;
                }
                if(microtime(true) - $started > self::TIMEOUT_SECONDS) {
                    proc_terminate($process);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    throw new WireException('Composer installation exceeded the 55-second safety timeout. Run composer install from the server shell to continue.');
                }
                usleep(100000);
            } while(true);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedCode = proc_close($process);
            if($exitCode === null || $exitCode < 0) $exitCode = $closedCode;
            if($exitCode !== 0) throw new WireException('Composer installation failed. Run composer install from the persistent Mailbox runtime directory for diagnostic output.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        clearstatcache(true, $this->runtimeRoot . '/vendor/composer/installed.json');
        $after = $this->status();
        if(empty($after['runtime_ready'])) throw new WireException('Composer completed, but PHPMailer 6.12+ and Webklex PHP-IMAP 5.3+ were not both detected.');
        return $after;
    }

    private function composerPath(): ?string {
        $candidates = [];
        if(is_string($this->configuredComposer) && $this->configuredComposer !== '') $candidates[] = $this->configuredComposer;
        $candidates = array_merge($candidates, [
            $this->moduleRoot . '/composer.phar',
            '/opt/homebrew/bin/composer',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ]);
        foreach(array_unique($candidates) as $candidate) {
            if($candidate === '' || $candidate[0] !== '/') continue;
            $real = realpath($candidate);
            $isPhar = $real !== false && substr(strtolower($real), -5) === '.phar';
            if($real !== false && is_file($real) && (is_executable($real) || ($isPhar && is_readable($real) && is_executable(PHP_BINARY)))) return $real;
        }
        return null;
    }

    private function installedPhpMailerVersion(): ?string {
        return $this->installedPackageVersion('phpmailer/phpmailer');
    }

    private function installedPackageVersion(string $packageName): ?string {
        $path = $this->runtimeRoot . '/vendor/composer/installed.json';
        $data = $this->readJson($path);
        if($data === null) return null;
        $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
        foreach($packages as $package) {
            if(is_array($package) && ($package['name'] ?? '') === $packageName) return (string) ($package['pretty_version'] ?? $package['version'] ?? '');
        }
        return null;
    }

    private function lockedPhpMailerVersion(): ?string {
        return $this->lockedPackageVersion('phpmailer/phpmailer');
    }

    private function lockedPackageVersion(string $packageName): ?string {
        $data = $this->readJson($this->moduleRoot . '/composer.lock');
        if($data === null) return null;
        foreach((array) ($data['packages'] ?? []) as $package) {
            if(is_array($package) && ($package['name'] ?? '') === $packageName) return (string) ($package['version'] ?? '');
        }
        return null;
    }

    private function prepareRuntime(): void {
        if($this->runtimeRoot === $this->moduleRoot) return;
        if(!is_dir($this->runtimeRoot) && !@mkdir($this->runtimeRoot, 0700, true) && !is_dir($this->runtimeRoot)) {
            throw new WireException('Unable to create the persistent Mailbox runtime directory.');
        }
        foreach(['composer.json', 'composer.lock'] as $manifest) {
            $source = $this->moduleRoot . '/' . $manifest;
            if(!is_file($source)) throw new WireException('Mailbox runtime manifest is missing: ' . $manifest . '.');
            $content = file_get_contents($source);
            if(!is_string($content) || $content === '') throw new WireException('Unable to read Mailbox runtime manifest: ' . $manifest . '.');
            $this->writeAtomic($this->runtimeRoot . '/' . $manifest, $content, 0600);
        }
        $this->writeAtomic($this->runtimeRoot . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n", 0600);
        $this->writeAtomic($this->runtimeRoot . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n", 0600);
        $this->writeAtomic($this->runtimeRoot . '/index.html', '', 0600);

        $legacyVendor = $this->moduleRoot . '/vendor';
        $persistentVendor = $this->runtimeRoot . '/vendor';
        if(!is_dir($persistentVendor) && is_file($legacyVendor . '/autoload.php')) {
            if(!@rename($legacyVendor, $persistentVendor)) throw new WireException('Unable to migrate the existing Mailbox vendor directory into persistent site assets.');
        }
    }

    private function writeAtomic(string $path, string $content, int $mode): void {
        if(is_file($path)) {
            $existing = file_get_contents($path);
            if(is_string($existing) && hash_equals(hash('sha256', $content), hash('sha256', $existing))) return;
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if(file_put_contents($temporary, $content, LOCK_EX) === false || !@chmod($temporary, $mode) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new WireException('Unable to update the persistent Mailbox runtime metadata.');
        }
    }

    private function readJson(string $path): ?array {
        if(!is_file($path)) return null;
        $size = filesize($path);
        if($size === false || $size < 2 || $size > self::MAX_METADATA_BYTES) return null;
        $json = file_get_contents($path);
        if(!is_string($json)) return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}

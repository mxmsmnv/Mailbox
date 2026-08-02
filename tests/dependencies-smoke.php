<?php

namespace ProcessWire {
    if(!class_exists(__NAMESPACE__ . '\\WireException')) {
        class WireException extends \RuntimeException {}
    }
    require_once dirname(__DIR__) . '/src/MailboxDependencyInstaller.php';

    $root = sys_get_temp_dir() . '/mailbox-dependencies-' . bin2hex(random_bytes(6));
    if(!mkdir($root . '/vendor/composer', 0700, true)) throw new \RuntimeException('Unable to create dependency smoke fixture.');
    try {
        $packages = [['name' => 'phpmailer/phpmailer', 'version' => 'v6.12.0', 'pretty_version' => 'v6.12.0'], ['name' => 'webklex/php-imap', 'version' => 'v5.5.0', 'pretty_version' => 'v5.5.0']];
        file_put_contents($root . '/composer.json', json_encode(['require' => ['phpmailer/phpmailer' => '^6.12', 'webklex/php-imap' => '^5.3']], JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/composer.lock', json_encode(['packages' => $packages], JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/vendor/autoload.php', '<?php return true;');
        $installer = new MailboxDependencyInstaller($root, '/definitely/not/composer');
        $status = $installer->status();
        if(empty($status['runtime_ready']) || ($status['phpmailer_version'] ?? '') !== 'v6.12.0' || ($status['webklex_version'] ?? '') !== 'v5.5.0' || ($status['locked_webklex_version'] ?? '') !== 'v5.5.0') throw new \RuntimeException('Locked dependency status detection failed.');

        $persistent = $root . '/site/assets/Mailbox/runtime';
        $persistentInstaller = new MailboxDependencyInstaller($root, '/definitely/not/composer', $persistent);
        $persistentStatus = $persistentInstaller->status();
        if(empty($persistentStatus['runtime_ready']) || ($persistentStatus['runtime_path'] ?? '') !== $persistent || is_dir($root . '/vendor') || !is_file($persistent . '/vendor/autoload.php')) throw new \RuntimeException('Legacy module vendor was not migrated to persistent runtime storage.');
        foreach(['composer.json', 'composer.lock', '.htaccess', 'web.config', 'index.html'] as $runtimeFile) if(!is_file($persistent . '/' . $runtimeFile)) throw new \RuntimeException('Persistent runtime protection or manifest is missing: ' . $runtimeFile);

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/MailboxDependencyInstaller.php');
        foreach(['--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', 'LOCK_EX | LOCK_NB', 'TIMEOUT_SECONDS = 55', 'proc_open($command', "version_compare(ltrim(\$version, 'v'), '6.12.0'", "version_compare(ltrim(\$webklexVersion, 'v'), '5.3.0'"] as $needle) {
            if(strpos($source, $needle) === false) throw new \RuntimeException('Bounded dependency installer safeguard is missing: ' . $needle);
        }
        foreach(['$_GET', 'wire()->input', 'shell_exec(', 'system(', 'passthru('] as $unsafe) {
            if(strpos($source, $unsafe) !== false) throw new \RuntimeException('Dependency installer accepts unsafe input or shell execution: ' . $unsafe);
        }
    } finally {
        $persistent = $root . '/site/assets/Mailbox/runtime';
        @unlink($persistent . '/vendor/composer/installed.json');
        @rmdir($persistent . '/vendor/composer');
        @unlink($persistent . '/vendor/autoload.php');
        @rmdir($persistent . '/vendor');
        foreach(['composer.json', 'composer.lock', '.htaccess', 'web.config', 'index.html'] as $runtimeFile) @unlink($persistent . '/' . $runtimeFile);
        @rmdir($persistent);
        @rmdir($root . '/site/assets/Mailbox');
        @rmdir($root . '/site/assets');
        @rmdir($root . '/site');
        @unlink($root . '/composer.lock');
        @unlink($root . '/composer.json');
        @rmdir($root);
    }

    fwrite(STDOUT, "Mailbox dependency installer smoke tests passed.\n");
}

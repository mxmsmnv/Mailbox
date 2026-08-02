<?php

declare(strict_types=1);

namespace ProcessWire {
    require_once dirname(__DIR__) . '/src/MailboxMessageView.php';

    $html = '<style>.card{color:#222;background-image:url(https://tracker.example.net/bg)}</style><div class="card" style="padding:10px;background-image:url(https://tracker.example.net/inline)" onclick="steal()"><script>alert(1)</script><form action="https://evil.test"><input></form><img src="https://tracker.example.net/pixel"><a href="https://confirm.test/token">Open</a><strong>Hello</strong></div>';
    $safe = MailboxMessageView::sanitizedDocument($html);
    foreach(['Content-Security-Policy', 'default-src', 'form-action', '<strong>Hello</strong>', 'padding:10px'] as $needle) {
        if(strpos($safe, $needle) === false) throw new \RuntimeException('Sanitized HTML is missing: ' . $needle);
    }
    foreach(['<script', '<form', '<input', 'onclick=', 'tracker.example.net', 'confirm.test/token'] as $needle) {
        if(stripos($safe, $needle) !== false) throw new \RuntimeException('Unsafe HTML survived sanitization: ' . $needle);
    }

    $remote = MailboxMessageView::sanitizedDocument($html, true);
    foreach(['img-src data: https:', 'https://tracker.example.net/pixel', 'https://tracker.example.net/bg', 'https://tracker.example.net/inline', 'referrerpolicy="no-referrer"', 'loading="lazy"'] as $needle) {
        if(strpos($remote, $needle) === false) throw new \RuntimeException('Acknowledged remote-image rendering is missing: ' . $needle);
    }
    foreach(['<script', '<form', '<input', 'onclick=', 'confirm.test/token'] as $needle) {
        if(stripos($remote, $needle) !== false) throw new \RuntimeException('Active HTML survived acknowledged image mode: ' . $needle);
    }

    $links = MailboxMessageView::sanitizedDocument('<a href="https://confirm.example.com/token?mail=1#step" ping="https://tracker.example.com/p" download>Confirm</a><a href="http://unsafe.example.com">HTTP</a><a href="https://127.0.0.1/a">Local</a><a href="javascript:alert(1)">Script</a>', false, true);
    foreach(['href="https://confirm.example.com/token?mail=1#step"', 'target="_blank"', 'rel="noopener noreferrer"', 'referrerpolicy="no-referrer"'] as $needle) {
        if(strpos($links, $needle) === false) throw new \RuntimeException('Acknowledged external-link rendering is missing: ' . $needle);
    }
    foreach(['ping=', ' download', 'http://unsafe.example.com', '127.0.0.1', 'javascript:'] as $needle) {
        if(stripos($links, $needle) !== false) throw new \RuntimeException('Unsafe external-link behavior survived: ' . $needle);
    }

    $blockedHosts = MailboxMessageView::sanitizedDocument('<img src="http://cdn.example.com/a"><img src="https://127.0.0.1/a"><img src="https://localhost/a"><img src="https://pixel.foo.local/a"><img src="https://cdn.example.com:8443/a">', true);
    foreach(['http://cdn.example.com', '127.0.0.1', 'localhost', 'foo.local', ':8443'] as $needle) if(strpos($blockedHosts, $needle) !== false) throw new \RuntimeException('Unsafe remote image host survived: ' . $needle);
    fwrite(STDOUT, "Mailbox message view smoke tests passed.\n");
}

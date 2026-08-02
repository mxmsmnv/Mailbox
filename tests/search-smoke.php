<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    require_once dirname(__DIR__) . '/src/MailboxSearchConcern.php';

    final class SearchHarness {
        use MailboxSearchConcern;
        public $maxSearchResults = 1000;
        public $maxSearchFolders = 100;
        public function normalize(array $filters): array { return $this->normalizeSearchFilters($filters); }
        public function criteria(array $filters): string { return $this->imapSearchCriteria($this->normalizeSearchFilters($filters)); }
    }

    $harness = new SearchHarness();
    $filters = $harness->normalize([
        'text' => 'invoice "A"',
        'from' => 'billing@example.com',
        'since' => '2026-01-01',
        'state' => 'unseen',
        'flagged' => 'yes',
        'min_bytes' => '100',
        'max_bytes' => 500000,
        'has_attachment' => 'yes',
    ]);
    $criteria = $harness->criteria($filters);
    foreach(['TEXT "invoice \\"A\\""', 'FROM "billing@example.com"', 'SINCE 01-Jan-2026', 'UNSEEN', 'FLAGGED', 'LARGER 100', 'SMALLER 500000'] as $expected) {
        if(strpos($criteria, $expected) === false) throw new \RuntimeException('Missing safe IMAP search criterion: ' . $expected);
    }
    if(strpos($criteria, 'has_attachment') !== false) throw new \RuntimeException('Non-IMAP attachment filter leaked into raw criteria.');

    foreach([
        ['text' => "bad\r\nALL"],
        ['since' => '01-01-2026'],
        ['state' => 'deleted'],
        ['min_bytes' => 20, 'max_bytes' => 10],
    ] as $invalid) {
        try {
            $harness->normalize($invalid);
            throw new \RuntimeException('Invalid search filter was accepted.');
        } catch(WireException $error) {}
    }
    fwrite(STDOUT, "Mailbox search filter smoke tests passed.\n");
}

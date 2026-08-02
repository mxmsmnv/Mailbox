<?php namespace ProcessWire;

/** Server-side folder and whole-account search with bounded result collection. */
trait MailboxSearchConcern {

    public function searchMessages(array $filters = [], ?string $folder = null, int $page = 1, int $limit = 30): array {
        $filters = $this->normalizeSearchFilters($filters);
        if($folder !== null) $this->validateFolderName($folder);
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $cap = max(100, min(10000, (int) $this->accountSetting('maxSearchResults')));
        $folders = $folder !== null ? [$folder] : $this->searchFolderNames();
        $collected = [];
        $matched = 0;
        $truncated = false;
        if($this->usesWebklexTransport()) {
            $result = $this->withWebklexTransport(function(MailboxWebklexTransport $transport) use ($folders, $filters, $cap): array {
                return $this->searchAcrossFolders($folders, $filters, $cap, function(string $name, array $criteria, int $remaining) use ($transport): array {
                    return $transport->search($name, $criteria, $remaining);
                });
            });
            $collected = $result['messages'];
            $matched = $result['matched'];
            $truncated = $result['truncated'];
        } else {
            $result = $this->searchAcrossFolders($folders, $filters, $cap, function(string $name, array $criteria, int $remaining): array {
                return $this->searchExtImapFolder($name, $criteria, $remaining);
            });
            $collected = $result['messages'];
            $matched = $result['matched'];
            $truncated = $result['truncated'];
        }
        usort($collected, static function(array $a, array $b): int {
            return $b['date'] <=> $a['date'] ?: ($b['uid'] <=> $a['uid']);
        });
        $total = count($collected);
        $offset = ($page - 1) * $limit;
        return [
            'messages' => array_slice($collected, $offset, $limit),
            'total' => $total,
            'matched' => $matched,
            'truncated' => $truncated,
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int) ceil($total / $limit)),
            'scope' => $folder === null ? 'all' : 'folder',
            'filters' => $filters,
        ];
    }

    protected function normalizeSearchFilters(array $filters): array {
        $result = [];
        foreach(['text', 'from', 'to', 'subject'] as $name) {
            if(!isset($filters[$name]) || $filters[$name] === '') continue;
            if(!is_string($filters[$name])) throw new WireException('Mailbox search text filters must be strings.');
            $value = trim($filters[$name]);
            if($value === '' || strlen($value) > 256 || preg_match('/[\r\n\0]/', $value)) throw new WireException('Invalid Mailbox search text filter.');
            $result[$name] = $value;
        }
        foreach(['since', 'before'] as $name) {
            if(empty($filters[$name])) continue;
            $value = (string) $filters[$name];
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if(!$date || $date->format('Y-m-d') !== $value) throw new WireException('Search dates must use YYYY-MM-DD.');
            $result[$name] = $value;
        }
        foreach(['state', 'flagged', 'answered', 'has_attachment'] as $name) {
            $allowed = $name === 'state' ? ['any', 'seen', 'unseen'] : ['any', 'yes', 'no'];
            $value = strtolower((string) ($filters[$name] ?? 'any'));
            if(!in_array($value, $allowed, true)) throw new WireException('Invalid Mailbox search state filter.');
            if($value !== 'any') $result[$name] = $value;
        }
        foreach(['min_bytes', 'max_bytes'] as $name) {
            if(!isset($filters[$name]) || $filters[$name] === '') continue;
            if(!is_int($filters[$name]) && !ctype_digit((string) $filters[$name])) throw new WireException('Search size filters must be non-negative integers.');
            $result[$name] = max(0, min(1073741824, (int) $filters[$name]));
        }
        if(isset($result['min_bytes'], $result['max_bytes']) && $result['min_bytes'] > $result['max_bytes']) throw new WireException('Search minimum size cannot exceed maximum size.');
        return $result;
    }

    private function searchAcrossFolders(array $folders, array $filters, int $cap, callable $search): array {
        $messages = [];
        $matched = 0;
        $truncated = false;
        foreach($folders as $folder) {
            $remaining = $cap - count($messages);
            if($remaining < 1) { $truncated = true; break; }
            $result = $search($folder, $filters, $remaining);
            $matched += (int) $result['matched'];
            $truncated = $truncated || !empty($result['truncated']);
            foreach($result['messages'] as $message) {
                $message['folder'] = $folder;
                $messages[] = $message;
            }
        }
        return ['messages' => $messages, 'matched' => $matched, 'truncated' => $truncated];
    }

    private function searchFolderNames(): array {
        $result = [];
        $maximum = max(1, min(500, (int) $this->accountSetting('maxSearchFolders')));
        foreach($this->listFolders() as $folder) {
            if(empty($folder['selectable'])) continue;
            $result[] = (string) $folder['name'];
            if(count($result) >= $maximum) break;
        }
        return $result;
    }

    private function searchExtImapFolder(string $folder, array $filters, int $limit): array {
        return $this->withMailbox($folder, function($connection) use ($folder, $filters, $limit): array {
            $criteria = $this->imapSearchCriteria($filters);
            $uids = @imap_search($connection, $criteria, SE_UID, 'UTF-8');
            if($uids === false) $uids = [];
            rsort($uids, SORT_NUMERIC);
            $matched = count($uids);
            $truncated = $matched > $limit;
            if(isset($filters['has_attachment'])) {
                $candidateLimit = min($limit, 250);
                $truncated = $truncated || $matched > $candidateLimit;
                $candidates = $this->searchOverviewSummaries($connection, array_slice($uids, 0, $candidateLimit), $folder);
                $messages = [];
                $budget = $this->maximumAttachmentBytes();
                foreach($candidates as $message) {
                    $size = (int) $message['size'];
                    if($size < 1) { $truncated = true; break; }
                    if($size > $budget) { $truncated = true; break; }
                    $budget -= $size;
                    $structure = @imap_fetchstructure($connection, (int) $message['uid'], FT_UID);
                    $has = $structure ? $this->structureHasAttachment($structure) : false;
                    if(($filters['has_attachment'] === 'yes') === $has) $messages[] = $message;
                }
                return ['messages' => $messages, 'matched' => $matched, 'truncated' => $truncated];
            }
            $uids = array_slice($uids, 0, $limit);
            return [
                'messages' => $this->searchOverviewSummaries($connection, $uids, $folder),
                'matched' => $matched,
                'truncated' => $truncated,
            ];
        });
    }

    private function imapSearchCriteria(array $filters): string {
        $parts = [];
        foreach(['text' => 'TEXT', 'from' => 'FROM', 'to' => 'TO', 'subject' => 'SUBJECT'] as $name => $criterion) {
            if(isset($filters[$name])) $parts[] = $criterion . ' "' . $this->imapSearchString($filters[$name]) . '"';
        }
        foreach(['since' => 'SINCE', 'before' => 'BEFORE'] as $name => $criterion) {
            if(isset($filters[$name])) $parts[] = $criterion . ' ' . gmdate('d-M-Y', strtotime($filters[$name] . ' UTC'));
        }
        if(($filters['state'] ?? '') === 'seen') $parts[] = 'SEEN';
        if(($filters['state'] ?? '') === 'unseen') $parts[] = 'UNSEEN';
        if(($filters['flagged'] ?? '') === 'yes') $parts[] = 'FLAGGED';
        if(($filters['flagged'] ?? '') === 'no') $parts[] = 'UNFLAGGED';
        if(($filters['answered'] ?? '') === 'yes') $parts[] = 'ANSWERED';
        if(($filters['answered'] ?? '') === 'no') $parts[] = 'UNANSWERED';
        if(isset($filters['min_bytes'])) $parts[] = 'LARGER ' . (int) $filters['min_bytes'];
        if(isset($filters['max_bytes'])) $parts[] = 'SMALLER ' . (int) $filters['max_bytes'];
        return $parts ? implode(' ', $parts) : 'ALL';
    }

    private function imapSearchString(string $value): string {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function searchOverviewSummaries($connection, array $uids, string $folder): array {
        $messages = [];
        foreach(array_chunk($uids, 100) as $chunk) {
            $rows = @imap_fetch_overview($connection, implode(',', $chunk), FT_UID);
            if(!is_array($rows)) continue;
            foreach($rows as $item) {
                $timestamp = isset($item->udate) ? (int) $item->udate : strtotime((string) ($item->date ?? ''));
                $messages[] = [
                    'uid' => (int) ($item->uid ?? 0),
                    'message_id' => trim((string) ($item->message_id ?? ''), '<>'),
                    'subject' => $this->decodeMimeHeader((string) ($item->subject ?? '(no subject)')),
                    'from' => $this->decodeMimeHeader((string) ($item->from ?? '')),
                    'date' => $timestamp > 0 ? $timestamp : 0,
                    'seen' => !empty($item->seen),
                    'answered' => !empty($item->answered),
                    'flagged' => !empty($item->flagged),
                    'size' => max(0, (int) ($item->size ?? 0)),
                    'folder' => $folder,
                ];
            }
        }
        return $messages;
    }

    private function structureHasAttachment($part): bool {
        $parameters = $this->partParameters($part);
        if(isset($parameters['filename']) || isset($parameters['name']) || strtolower((string) ($part->disposition ?? '')) === 'attachment') return true;
        foreach((array) ($part->parts ?? []) as $child) if($this->structureHasAttachment($child)) return true;
        return false;
    }
}

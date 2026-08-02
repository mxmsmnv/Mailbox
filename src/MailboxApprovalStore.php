<?php namespace ProcessWire;

/**
 * Small locked JSON store for confirmation workflow state.
 * Full URLs are deliberately not persisted.
 */
final class MailboxApprovalStore {

    /** @var string */
    private $file;

    public function __construct(string $file) {
        $this->file = $file;
    }

    public function create(string $folder, int $uid, array $link, string $actor, int $accountId = 1, array $workflow = []): array {
        return $this->mutate(function(array &$records) use ($folder, $uid, $link, $actor, $accountId, $workflow): array {
            $id = bin2hex(random_bytes(16));
            $now = time();
            $records[$id] = [
                'id' => $id,
                'status' => 'pending',
                'folder' => $folder,
                'uid' => $uid,
                'account_id' => max(1, $accountId),
                'url_hash' => (string) $link['hash'],
                'host' => (string) $link['host'],
                'path_hash' => hash('sha256', (string) $link['path']),
                'label' => function_exists('mb_substr') ? mb_substr((string) $link['label'], 0, 250) : substr((string) $link['label'], 0, 250),
                'workflow' => $workflow ?: ['mode' => 'get', 'max_steps' => 1],
                'requested_by' => $actor,
                'created_at' => $now,
                'expires_at' => $now + 86400,
                'approved_by' => null,
                'approved_at' => null,
                'executed_by' => null,
                'executed_at' => null,
                'http_status' => null,
            ];
            return $records[$id];
        });
    }

    public function get(string $id): array {
        $this->assertId($id);
        $records = $this->read();
        if(!isset($records[$id])) throw new WireException('Confirmation proposal not found.');
        return $records[$id];
    }

    public function all(): array {
        $records = array_values($this->read());
        usort($records, static function(array $a, array $b): int {
            return ((int) $b['created_at']) <=> ((int) $a['created_at']);
        });
        return $records;
    }

    public function approve(string $id, string $actor): array {
        $this->assertId($id);
        return $this->mutate(function(array &$records) use ($id, $actor): array {
            if(!isset($records[$id])) throw new WireException('Confirmation proposal not found.');
            if(($records[$id]['status'] ?? '') !== 'pending') throw new WireException('Only pending proposals can be approved.');
            if((int) ($records[$id]['expires_at'] ?? 0) < time()) throw new WireException('The confirmation proposal has expired.');
            if(hash_equals((string) ($records[$id]['requested_by'] ?? ''), $actor)) {
                throw new WireException('The requester may not approve their own confirmation proposal.');
            }
            $records[$id]['status'] = 'approved';
            $records[$id]['approved_by'] = $actor;
            $records[$id]['approved_at'] = time();
            return $records[$id];
        });
    }

    public function reject(string $id, string $actor): array {
        $this->assertId($id);
        return $this->mutate(function(array &$records) use ($id, $actor): array {
            if(!isset($records[$id])) throw new WireException('Confirmation proposal not found.');
            if(($records[$id]['status'] ?? '') !== 'pending') throw new WireException('Only pending proposals can be rejected.');
            $records[$id]['status'] = 'rejected';
            $records[$id]['approved_by'] = $actor;
            $records[$id]['approved_at'] = time();
            return $records[$id];
        });
    }

    public function markExecuted(string $id, string $actor, array $result): array {
        $this->assertId($id);
        return $this->mutate(function(array &$records) use ($id, $actor, $result): array {
            if(!isset($records[$id])) throw new WireException('Confirmation proposal not found.');
            if(($records[$id]['status'] ?? '') !== 'executing') throw new WireException('The proposal was not claimed for execution.');
            $records[$id]['status'] = 'executed';
            $records[$id]['executed_by'] = $actor;
            $records[$id]['executed_at'] = time();
            $records[$id]['http_status'] = (int) ($result['status'] ?? 0);
            return $records[$id];
        });
    }

    public function claimExecution(string $id, string $actor): array {
        $this->assertId($id);
        return $this->mutate(function(array &$records) use ($id, $actor): array {
            if(!isset($records[$id])) throw new WireException('Confirmation proposal not found.');
            if(($records[$id]['status'] ?? '') !== 'approved') throw new WireException('Only approved proposals can be executed.');
            if((int) ($records[$id]['expires_at'] ?? 0) < time()) throw new WireException('The confirmation proposal has expired.');
            $records[$id]['status'] = 'executing';
            $records[$id]['execution_claimed_by'] = $actor;
            $records[$id]['execution_claimed_at'] = time();
            return $records[$id];
        });
    }

    public function markExecutionFailed(string $id, string $actor, string $reason): array {
        $this->assertId($id);
        return $this->mutate(function(array &$records) use ($id, $actor, $reason): array {
            if(!isset($records[$id])) throw new WireException('Confirmation proposal not found.');
            if(($records[$id]['status'] ?? '') !== 'executing') throw new WireException('The proposal was not being executed.');
            $records[$id]['status'] = 'failed';
            $records[$id]['executed_by'] = $actor;
            $records[$id]['executed_at'] = time();
            $records[$id]['failure_code'] = substr(hash('sha256', $reason), 0, 16);
            return $records[$id];
        });
    }

    private function read(): array {
        if(!is_file($this->file)) return [];
        $handle = @fopen($this->file, 'rb');
        if(!$handle) throw new WireException('Unable to open the Mailbox proposal store.');
        try {
            if(!flock($handle, LOCK_SH)) throw new WireException('Unable to lock the Mailbox proposal store.');
            $json = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        if(trim((string) $json) === '') return [];
        $records = json_decode((string) $json, true);
        if(!is_array($records)) throw new WireException('The Mailbox proposal store is invalid JSON.');
        return $records;
    }

    private function assertId(string $id): void {
        if(!preg_match('/^[a-f0-9]{32}$/', $id)) throw new WireException('Invalid confirmation proposal ID.');
    }

    private function mutate(callable $callback): array {
        $directory = dirname($this->file);
        if(!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new WireException('Unable to create the Mailbox state directory.');
        }
        $handle = @fopen($this->file, 'c+b');
        if(!$handle) throw new WireException('Unable to open the Mailbox proposal store.');
        try {
            if(!flock($handle, LOCK_EX)) throw new WireException('Unable to lock the Mailbox proposal store.');
            rewind($handle);
            $stored = (string) stream_get_contents($handle);
            $records = trim($stored) === '' ? [] : json_decode($stored, true);
            if(!is_array($records)) throw new WireException('The Mailbox proposal store is invalid JSON.');
            $result = $callback($records);
            rewind($handle);
            if(!ftruncate($handle, 0)) throw new WireException('Unable to update the Mailbox proposal store.');
            $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if($json === false || fwrite($handle, $json . "\n") === false) throw new WireException('Unable to write the Mailbox proposal store.');
            fflush($handle);
            @chmod($this->file, 0600);
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }
}

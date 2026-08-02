<?php

declare(strict_types=1);

namespace ProcessWire {
    class WireException extends \RuntimeException {}
    class User {}
    class Wire {}
    class FakeFingerprintStore {
        public function sensitiveFingerprint(int $accountId, string $context, string $value): string { return hash_hmac('sha256', $context . "\0" . $value, 'test-key-' . $accountId); }
    }

    require_once dirname(__DIR__) . '/src/MailboxApprovalStore.php';
    require_once dirname(__DIR__) . '/src/MailboxConfirmationClient.php';
    require_once dirname(__DIR__) . '/src/MailboxConfirmationConcern.php';

    final class WorkflowHarness {
        use MailboxConfirmationConcern;
        public $enableAdvancedConfirmations = 1;
        private $body = 'Verification code: 123 456';
        public function workflow(array $workflow): array { return $this->normalizeConfirmationWorkflow($workflow, 'INBOX', 42); }
        public function setBody(string $body): void { $this->body = $body; }
        public function getMessage(string $folder, int $uid): array { return ['subject' => 'Verify account', 'body' => $this->body]; }
        protected function currentAccountId(): int { return 3; }
        public function indexStore(): FakeFingerprintStore { return new FakeFingerprintStore(); }
    }

    $harness = new WorkflowHarness();
    $workflow = $harness->workflow(['mode' => 'code_form', 'max_steps' => 9, 'code_field' => 'otp']);
    if($workflow['mode'] !== 'code_form' || $workflow['max_steps'] !== 3 || $workflow['code_field'] !== 'otp' || !preg_match('/^[a-f0-9]{64}$/', $workflow['code_fingerprint'])) throw new \RuntimeException('Code workflow normalization failed.');
    if(strpos(json_encode($workflow), '123456') !== false) throw new \RuntimeException('Confirmation code leaked into the stored workflow.');
    $harness->setBody("Code: 123456\nOTP: 654321");
    try { $harness->workflow(['mode' => 'code_form']); throw new \RuntimeException('Ambiguous codes were accepted.'); } catch(WireException $error) {}
    try { $harness->workflow(['mode' => ['form']]); throw new \RuntimeException('Non-scalar workflow mode was accepted.'); } catch(WireException $error) {}
    try { $harness->workflow(['mode' => 'form', 'fields' => ['danger' => 'value']]); throw new \RuntimeException('Arbitrary workflow fields were accepted.'); } catch(WireException $error) {}

    $client = new MailboxConfirmationClient(['confirm.example.com', 'other.example'], 2, 65536);
    $method = new \ReflectionMethod($client, 'confirmationForm');
    if(PHP_VERSION_ID < 80100) $method->setAccessible(true);
    $safe = '<form method="post" action="/verify"><input type="hidden" name="csrf" value="opaque"><input type="text" name="otp"><button type="submit" name="action" value="verify">Confirm account</button></form>';
    $form = $method->invoke($client, $safe, 'https://confirm.example.com/start', 'code_form', 'otp');
    if(!is_array($form) || $form['action'] !== 'https://confirm.example.com/verify' || $form['code_field'] !== 'otp' || ($form['fields']['csrf'] ?? '') !== 'opaque' || isset($form['fields']['otp'])) throw new \RuntimeException('Safe code form parsing failed.');
    $crossHost = '<form method="post" action="https://other.example/verify"><input type="hidden" name="token" value="secret"><button type="submit">Confirm</button></form>';
    try { $method->invoke($client, $crossHost, 'https://confirm.example.com/start', 'form', ''); throw new \RuntimeException('Cross-host form was accepted.'); } catch(WireException $error) {}
    $unsafe = '<form method="post" action="/verify"><input type="password" name="password"><button type="submit">Confirm</button></form>';
    try { $method->invoke($client, $unsafe, 'https://confirm.example.com/start', 'form', ''); throw new \RuntimeException('Password form was accepted.'); } catch(WireException $error) {}
    $multipart = '<form method="post" enctype="multipart/form-data"><input type="hidden" name="token" value="x"><button type="submit">Confirm</button></form>';
    try { $method->invoke($client, $multipart, 'https://confirm.example.com/start', 'form', ''); throw new \RuntimeException('Multipart form was accepted.'); } catch(WireException $error) {}
    $duplicate = '<form method="post"><input type="hidden" name="token" value="a"><input type="hidden" name="token" value="b"><button type="submit">Confirm</button></form>';
    try { $method->invoke($client, $duplicate, 'https://confirm.example.com/start', 'form', ''); throw new \RuntimeException('Duplicate hidden fields were accepted.'); } catch(WireException $error) {}
    $ambiguous = '<form method="post"><button type="submit">Confirm</button></form><form method="post"><button type="submit">Verify</button></form>';
    try { $method->invoke($client, $ambiguous, 'https://confirm.example.com/start', 'form', ''); throw new \RuntimeException('Ambiguous forms were accepted.'); } catch(WireException $error) {}

    $concernSource = file_get_contents(dirname(__DIR__) . '/src/MailboxConfirmationConcern.php');
    if(strpos((string) $concernSource, "unset(\$proposal['workflow']['code_fingerprint'])") === false) throw new \RuntimeException('Code fingerprint is not redacted from public proposal DTOs.');
    $clientSource = file_get_contents(dirname(__DIR__) . '/src/MailboxConfirmationClient.php');
    foreach(['CURLOPT_RESOLVE', "CURLOPT_PROXY => ''", 'CURLOPT_FOLLOWLOCATION => false', 'application/x-www-form-urlencoded'] as $needle) if(strpos((string) $clientSource, $needle) === false) throw new \RuntimeException('Missing advanced confirmation network boundary: ' . $needle);

    fwrite(STDOUT, "Mailbox advanced confirmation workflow smoke tests passed.\n");
}

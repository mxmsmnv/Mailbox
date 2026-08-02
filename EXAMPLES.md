# Mailbox Examples

## Authorized frontend inbox

```php
<?php namespace ProcessWire;

if(!$user->isLoggedin() || !$modules->isInstalled('Mailbox')) {
    throw new Wire404Exception();
}

/** @var Mailbox $mailbox */
$mailbox = $modules->get('Mailbox');
$api = $mailbox->api($user);
if(!$api->canRead()) throw new WirePermissionException();

$result = $api->messages('INBOX', 1, 20);
foreach($result['messages'] as $summary) {
    echo '<h2>' . $sanitizer->entities($summary['subject']) . '</h2>';
}
```

Grant `mailbox-api` only to roles that may read the configured shared mailbox. `isLoggedin()` by itself is not authorization.

## Review mail settings discovered for an address

```php
$api = $mailbox->api($user);
if(!$api->canRead()) throw new WirePermissionException();

$discovery = $api->discover('automation@example.com');
if($discovery['credentials_used'] || $discovery['applied']) {
    throw new WireException('Discovery crossed its review-only boundary.');
}
foreach($discovery['candidates'] as $candidate) {
    // Display source, confidence, warnings, and escaped settings for review.
    // Saving a candidate and testing credentials are separate admin actions.
}
```

## Authorized plain-text send

```php
<?php namespace ProcessWire;

$session->CSRF->validate();
$api = $mailbox->api($user);
if(!$api->canSend()) throw new WirePermissionException();

$result = $api->send([
    'to' => [['email' => 'recipient@example.com', 'name' => 'Recipient']],
    'subject' => 'Status update',
    'body' => "The job completed successfully.\n",
    'attachments' => [[
        'name' => 'report.txt',
        'type' => 'text/plain',
        'content' => "Bounded report contents\n", // REST/CLI use content_base64
    ]],
]);
```

Enable SMTP first and grant both `mailbox-api` and `mailbox-send`. Do not accept an arbitrary From address from request input; Mailbox uses the configured account identity or administrator-configured alias.

## Search and read a text attachment

```php
$api = $mailbox->api($user);
$result = $api->search([
    'from' => 'billing@example.com',
    'subject' => 'invoice',
    'since' => '2026-01-01',
    'has_attachment' => 'yes',
], null, 1, 25); // null searches all selectable folders

if($result['truncated']) {
    // Refine filters before treating absence as meaningful.
}

if($api->canReadAttachments()) {
    $text = $api->attachmentText('INBOX', 123, '2.1');
}
```

The role needs `mailbox-attachments` in addition to `mailbox-api`. `attachmentText()` accepts text-like MIME types only; use the forced-download endpoint for other bounded files.

## Observe indexed new-message notifications

```php
$wire->addHookAfter('Mailbox::messageIndexed', function(HookEvent $event) {
    $notification = $event->arguments(0);
    // Enqueue a small, idempotent site-owned job. Do not send externally here.
});

$api = $mailbox->api($user);
$notifications = $api->notifications(25, true);
```

Enable background synchronization first. Initial seeding intentionally emits no historical notifications. The hook payload contains mailbox metadata and must be treated as sensitive.

## Propose a confirmation link

Use a POST endpoint, validate CSRF, and pass the link hash rather than accepting an arbitrary URL:

```php
<?php namespace ProcessWire;

$session->CSRF->validate();
$folder = $sanitizer->text($input->post('folder'));
$uid = $input->post('uid', 'int');
$hash = $sanitizer->text($input->post('hash'));

$proposal = $mailbox->api($user)->proposeConfirmation($folder, $uid, $hash);
```

For a reviewed email-code form, enable advanced confirmations and declare only workflow shape—not the code or arbitrary fields:

```php
$proposal = $mailbox->api($user)->proposeConfirmation($folder, $uid, $hash, [
    'mode' => 'code_form',
    'max_steps' => 1,
    'code_field' => 'otp', // optional when the field has a recognized name
]);
```

Mailbox extracts exactly one code from the message, stores only an account-keyed fingerprint, and re-reads it during execution. A different authorized user must still approve the proposal. Never accept `workflow`, mode, or step count directly from an untrusted public request without a site-owned allowlist.

## Human approval endpoint

```php
$session->CSRF->validate();
$id = $sanitizer->text($input->post('proposal_id'));
$approved = $mailbox->api($user)->approveConfirmation($id);
```

Execution should normally be a separate action after review. It uses the same CSRF and permission boundary:

```php
$session->CSRF->validate();
$result = $mailbox->api($user)->executeConfirmation($id);
```

## Verk/Kontor adapter seam

```php
$mailbox->registerApprovalProvider('kontor', function(array $proposal, Mailbox $mailbox) use ($modules) {
    if(!$modules->isInstalled('KontorAI')) return;
    // Map the proposal into the exact installed Kontor approval API.
    // Do not approve it from this notification callback.
});
```

Feature-detect the target module and verify its installed API before implementing the adapter. Mailbox deliberately has no hard dependency on Verk or Kontor.

## Squad AI analysis and bounded agent

Squad support is separately opt-in. Use the permission-gated facade so the logged-in requester and selected account remain explicit:

```php
<?php namespace ProcessWire;

if($modules->isInstalled('Mailbox') && $modules->isInstalled('Squad')) {
    /** @var Mailbox $mailbox */
    $mailbox = $modules->get('Mailbox');
    $api = $mailbox->api($user, 1);

    if($api->canUseSquad()) {
        $analysis = $api->analyzeWithSquad('INBOX', 123, 'Summarize the request and list any deadlines.', [
            'provider' => 'openai',
            'model' => 'gpt-5.4-mini',
            'maxTokens' => 600,
        ]);
    }
}
```

For a bounded tool-use flow, call `$api->runSquadAgent('Find the newest account-verification email and create a confirmation proposal for human review.')`. The agent can list/read mail, inspect redacted link hashes, and create a pending proposal. It cannot approve or execute one. Do not bypass the facade for frontend code, and do not treat a successful Squad response as proof that a sender, link, or requested action is safe.

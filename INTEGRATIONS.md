# Mailbox integration framework

Mailbox is the IMAP provider. Its optional adapters call verified public APIs in independently installable Verk, Kontor, and Squad modules; no integration reaches into another module's SQL or admin routes.

## Stable workflow

1. Discover `mailbox.read`, `mailbox.settings.discover`, `mailbox.links.extract`, and `mailbox.links.confirm` through `Mailbox::capabilities()`.
2. Read through `Mailbox::api($user)` for a logged-in ProcessWire user, or through the local CLI for a host-local agent.
3. Select a `hash` returned by the message `links` array; never submit an arbitrary URL.
4. Create a pending proposal.
5. Send proposal metadata to an independently authorized review system.
6. Approve or reject as a different actor.
7. Execute the approved single-use proposal through Mailbox.

Mailbox preserves proposal ID, account ID, URL hash, mailbox UID, status, requester, reviewer, and timestamps. External workspaces receive only the proposal ID, account/folder/message references, and hostname; they must not copy the URL hash, full URL, message body, or mailbox credentials into tasks or audit records. Built-in adapters require the PHP agent API and confirmation workflow to be enabled.

## Kontor adapter

Select Kontor AI and configure an explicit organization UID. Mailbox calls `KontorAI::submitExternalApproval()` only after feature detection. Kontor stores a unique provider/reference mapping and a normal pending AI action containing bounded redacted metadata. Its decision route requires `kontor-ai-action-approve`, `mailbox-api`, and `mailbox-confirm-links`, forwards the decision to Mailbox first, then records local state. Mailbox remains authoritative.

Kontor Mail currently provides inbound storage/adapters but no production IMAP client. A separate adapter may map Mailbox message DTOs into Kontor's verified `InboundMailAdapterInterface`; deduplicate with folder plus UID and do not mutate IMAP flags.

## Verk adapter

Select Verk to call its idempotent `createExternalApproval()` API. Verk creates a review task plus an internal provider/reference mapping. A forged task description cannot become a Mailbox approval. A reviewer must also have `mailbox-api` and `mailbox-confirm-links`; Verk forwards approve/reject to Mailbox before changing task state. Mailbox's self-approval and proposal-state rules still apply.

## Squad adapter

Squad is an AI gateway, not an approval workspace. Enable it separately only after installing a Squad module that exposes `Squad::ask()` and `Squad::run()`, and enabling the Mailbox PHP agent API or local CLI. Mailbox feature-detects those methods and exposes two flows:

- analyze one selected message with a caller instruction;
- run a maximum six-step agent with list-message, read-message, read-link-hash, and create-pending-proposal tools.

Only `getAgentMessage()` DTOs cross the adapter boundary: HTML, raw MIME, attachment bytes, credentials, URL paths, and full URLs are absent. Link targets remain opaque SHA-256 hashes. Caller-supplied Squad keys, key indexes, history, system prompts, caching, prompt caching, and web search are rejected by omission; Mailbox supplies a fixed injection-resistant system prompt and bounded provider/model/token/temperature/timeout options. Agent tool output is capped at 1 MiB. Returned raw provider responses and model conversation/tool history are discarded.

The dedicated **AI model** settings section uses the same active-provider discovery pattern as Liora: leave **Provider and model** at **Use Squad default**, or select an active `provider|model` pair reported by Squad. Provider credentials are never copied into Mailbox. Token, temperature, timeout, and agent-step values are saved only as bounded defaults and can be overridden per request within the same hard limits.

The proposal tool is deliberately the only write-like tool. It may create a pending Mailbox confirmation proposal under the authenticated requester actor, but it cannot approve, reject, or execute it. The existing `mailbox-confirm-links`, allowlist, separation-of-duties, proposal-state, and reviewed execution boundaries remain authoritative. Calling Squad sends email text to the external provider configured in Squad and may incur provider cost.

## Tickets bridge

Tickets may optionally consume the hookable `Mailbox::messageIndexed(array $notification)` boundary, advertised as `mailbox.messages.indexed-hook@1.0.0`. The bridge is owned by Tickets and feature-detects Mailbox; Mailbox never installs, configures, or queries Tickets.

Automatic ingestion requires Mailbox background synchronization and starts only with messages discovered after the initial folder seed. The hook carries account, folder, UID, sender, subject, and date metadata; Tickets performs a bounded `getAgentMessage()` read for the selected account/folder, deduplicates the source, requires the configured support recipient by default, and stores only the ticket conversation plus a source linkage. HTML, raw MIME, executable link targets, credentials, and attachment bytes do not cross the bridge.

When separately enabled in both modules, Tickets may use Mailbox SMTP for reviewed staff notifications and continue a linked inbound thread through `replyMessage()`. Existing WireMail and Resend paths remain the default and continue to work when Mailbox is absent or the bridge is disabled.

## Registration timing

Configured adapters register during ProcessWire `ready`. Custom sinks may still be registered after feature detection:

```php
if($modules->isInstalled('Mailbox')) {
    $modules->get('Mailbox')->registerApprovalProvider('custom-review', $proposalSink);
}
```

The custom callback is a notification only. Built-in Verk/Kontor adapters are decision-aware through their verified APIs. External review metadata includes the confirmation workflow mode, approved step limit, and whether an email code is required, but never the code fingerprint or value. External writes are opt-in; failures are redacted in the audit log and leave the Mailbox proposal pending.

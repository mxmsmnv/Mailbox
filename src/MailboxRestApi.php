<?php namespace ProcessWire;

/** Same-origin, session-authenticated JSON transport for MailboxAgentApi. */
final class MailboxRestApi extends Wire {

    private const MAX_BODY_BYTES = 65536;
    private const MAX_MAIL_BODY_BYTES = 15728640;
    private const RATE_SESSION_KEY = 'MailboxRestRate';

    /** @var Mailbox */
    private $mailbox;

    public function __construct(Mailbox $mailbox) {
        $this->mailbox = $mailbox;
    }

    public function handle(string $resource): string {
        $normalizedResource = strtolower(trim($resource));
        if($normalizedResource === 'attachment' && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') return $this->attachmentDownload();
        $this->headers();
        try {
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $resource = strtolower(trim($resource));
            if(!preg_match('/^[a-z-]{2,32}$/', $resource)) return $this->response(404, null, 'Not found.');
            $this->rateLimit($method !== 'GET');
            if($resource === 'session') return $this->sessionResponse($method);

            $selectedAccount = (int) $this->wire()->input->get->account;
            $accountId = $selectedAccount > 0 ? $selectedAccount : null;
            $api = $this->mailbox->api($this->wire()->user, $accountId);
            if(!$api->canRead()) throw new WirePermissionException('Mailbox API access denied.');
            $mailResource = in_array($resource, ['send', 'reply', 'forward'], true);
            $body = $method === 'POST' ? $this->jsonBody($mailResource ? self::MAX_MAIL_BODY_BYTES : self::MAX_BODY_BYTES) : [];
            if($method === 'POST') $this->validateCsrf($body);

            switch($resource) {
                case 'accounts':
                    $this->allow($method, ['GET']);
                    $result = $api->accounts();
                    break;
                case 'discover':
                    $this->allow($method, ['POST']);
                    $result = $api->discover((string) ($body['email'] ?? ''));
                    break;
                case 'folders':
                    $this->allow($method, ['GET']);
                    $result = $api->folders();
                    break;
                case 'messages':
                    $this->allow($method, ['GET']);
                    $result = $api->messages($this->folder(), max(1, (int) $this->wire()->input->get->page), max(1, min(100, (int) ($this->wire()->input->get->limit ?: 30))));
                    break;
                case 'message':
                    $this->allow($method, ['GET']);
                    $result = $api->message($this->folder(), $this->uid());
                    break;
                case 'links':
                    $this->allow($method, ['GET']);
                    $result = $api->links($this->folder(), $this->uid());
                    break;
                case 'squad-analyze':
                    $this->allow($method, ['POST']);
                    if(isset($body['options']) && !is_array($body['options'])) throw new \InvalidArgumentException('options must be a JSON object.');
                    $result = $api->analyzeWithSquad($this->bodyFolder($body), $this->bodyUid($body), $this->instruction($body), $body['options'] ?? []);
                    break;
                case 'squad-agent':
                    $this->allow($method, ['POST']);
                    if(isset($body['options']) && !is_array($body['options'])) throw new \InvalidArgumentException('options must be a JSON object.');
                    $result = $api->runSquadAgent($this->instruction($body), $body['options'] ?? []);
                    break;
                case 'search':
                    $this->allow($method, ['POST']);
                    $scope = strtolower((string) ($body['scope'] ?? 'folder'));
                    if(!in_array($scope, ['folder', 'all'], true)) throw new \InvalidArgumentException('scope must be folder or all.');
                    $result = $api->search($this->searchFilters($body), $scope === 'all' ? null : $this->bodyFolder($body), max(1, (int) ($body['page'] ?? 1)), max(1, min(100, (int) ($body['limit'] ?? 30))));
                    break;
                case 'indexed-messages':
                    $this->allow($method, ['GET']);
                    $result = $api->indexedMessages(max(1, (int) $this->wire()->input->get->page), max(1, min(100, (int) ($this->wire()->input->get->limit ?: 30))));
                    break;
                case 'notifications':
                    $this->allow($method, ['GET']);
                    $result = $api->notifications(max(1, min(100, (int) ($this->wire()->input->get->limit ?: 50))), (string) $this->wire()->input->get->all !== '1');
                    break;
                case 'notification-read':
                    $this->allow($method, ['POST']);
                    $notificationId = (int) ($body['id'] ?? 0);
                    if($notificationId < 1) throw new \InvalidArgumentException('A positive notification ID is required.');
                    $result = ['updated' => $api->markNotificationRead($notificationId)];
                    break;
                case 'attachment-text':
                    $this->allow($method, ['GET']);
                    $result = $api->attachmentText($this->folder(), $this->uid(), $this->attachmentPart());
                    break;
                case 'attachment':
                    $this->allow($method, ['GET']);
                    $result = null;
                    break;
                case 'proposals':
                    $this->allow($method, ['GET']);
                    $result = $api->proposals();
                    break;
                case 'propose':
                    $this->allow($method, ['POST']);
                    if(isset($body['workflow']) && !is_array($body['workflow'])) throw new \InvalidArgumentException('workflow must be a JSON object.');
                    $result = $api->proposeConfirmation($this->bodyFolder($body), $this->bodyUid($body), $this->sha256($body['hash'] ?? ''), $body['workflow'] ?? []);
                    break;
                case 'approve':
                    $this->allow($method, ['POST']);
                    $result = $api->approveConfirmation($this->proposalId($body));
                    break;
                case 'reject':
                    $this->allow($method, ['POST']);
                    $result = $api->rejectConfirmation($this->proposalId($body));
                    break;
                case 'execute':
                    $this->allow($method, ['POST']);
                    $result = $api->executeConfirmation($this->proposalId($body));
                    break;
                case 'flags':
                    $this->allow($method, ['POST']);
                    if(!isset($body['flags']) || !is_array($body['flags']) || (isset($body['enabled']) && !is_bool($body['enabled']))) throw new \InvalidArgumentException('Flags must be an array and enabled must be a JSON boolean.');
                    $result = $api->setFlags($this->bodyFolder($body), $this->bodyUid($body), $body['flags'], $body['enabled'] ?? true);
                    break;
                case 'move':
                    $this->allow($method, ['POST']);
                    $expunge = ($body['expunge'] ?? false) === true;
                    if($expunge && ($body['confirm'] ?? '') !== 'EXPUNGE') throw new \InvalidArgumentException('Permanent expunge requires confirm="EXPUNGE".');
                    $result = $api->move($this->bodyFolder($body), $this->bodyUid($body), $this->bodyFolder(['folder' => $body['destination'] ?? '']), $expunge);
                    break;
                case 'delete':
                    $this->allow($method, ['POST']);
                    $expunge = ($body['expunge'] ?? false) === true;
                    if($expunge && ($body['confirm'] ?? '') !== 'EXPUNGE') throw new \InvalidArgumentException('Permanent expunge requires confirm="EXPUNGE".');
                    $result = $api->delete($this->bodyFolder($body), $this->bodyUid($body), $expunge);
                    break;
                case 'send':
                    $this->allow($method, ['POST']);
                    $result = $api->send($this->outgoingMessage($body));
                    break;
                case 'test-smtp':
                    $this->allow($method, ['POST']);
                    $result = $api->testSmtp();
                    break;
                case 'reply':
                    $this->allow($method, ['POST']);
                    if(isset($body['reply_all']) && !is_bool($body['reply_all'])) throw new \InvalidArgumentException('reply_all must be a JSON boolean.');
                    if(isset($body['attachments']) && !is_array($body['attachments'])) throw new \InvalidArgumentException('attachments must be a JSON array.');
                    $result = $api->reply($this->bodyFolder($body), $this->bodyUid($body), $this->bodyText($body), (bool) ($body['reply_all'] ?? false), $body['attachments'] ?? []);
                    break;
                case 'forward':
                    $this->allow($method, ['POST']);
                    $message = $this->outgoingMessage($body, false);
                    $result = $api->forward($this->bodyFolder($body), $this->bodyUid($body), $message['to'], (string) ($message['body'] ?? ''), $message['attachments']);
                    break;
                default:
                    return $this->response(404, null, 'Not found.');
            }
            return $this->response(200, $result);
        } catch(WirePermissionException $error) {
            return $this->response(403, null, $error->getMessage());
        } catch(MailboxRestException $error) {
            return $this->response($error->status(), null, $error->getMessage());
        } catch(\InvalidArgumentException $error) {
            return $this->response(400, null, $error->getMessage());
        } catch(WireException $error) {
            return $this->response(400, null, $error->getMessage());
        } catch(\Throwable $error) {
            $this->wire()->log->save('mailbox-actions', 'REST request failed (' . get_class($error) . ').');
            return $this->response(500, null, 'Mailbox request failed.');
        }
    }

    private function sessionResponse(string $method): string {
        $this->allow($method, ['GET']);
        $api = $this->mailbox->api($this->wire()->user);
        $authenticated = $this->wire()->user->isLoggedin();
        $result = ['isLogin' => (bool) $authenticated, 'canRead' => $api->canRead(), 'canWrite' => $api->canWrite(), 'canSend' => $api->canSend(), 'canReadAttachments' => $api->canReadAttachments(), 'canUseSquad' => $api->canUseSquad()];
        if($result['canRead']) {
            $token = $this->wire()->session->CSRF->getToken('mailbox-rest');
            $result['csrf'] = ['name' => $token['name'], 'value' => $token['value'], 'header' => 'X-' . $token['name']];
        }
        return $this->response(200, $result);
    }

    private function jsonBody(int $limit): array {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if($contentType !== 'application/json') throw new \InvalidArgumentException('Content-Type application/json is required.');
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if($length > $limit) throw new \InvalidArgumentException('JSON request body is too large.');
        $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
        if(!is_string($raw) || strlen($raw) > $limit) throw new \InvalidArgumentException('JSON request body is too large.');
        $body = json_decode($raw, true);
        if(!is_array($body)) throw new \InvalidArgumentException('A JSON object is required.');
        return $body;
    }

    private function validateCsrf(array $body): void {
        $token = $this->wire()->session->CSRF->getToken('mailbox-rest');
        $provided = (string) ($body[$token['name']] ?? ($_SERVER['HTTP_X_' . $token['name']] ?? ''));
        if($provided === '' || !hash_equals((string) $token['value'], $provided)) throw new WirePermissionException('Invalid CSRF token.');
    }

    private function rateLimit(bool $mutation): void {
        $session = $this->wire()->session;
        $state = $session->get(self::RATE_SESSION_KEY);
        $now = time();
        if(!is_array($state) || (int) ($state['started'] ?? 0) <= $now - 60) $state = ['started' => $now, 'reads' => 0, 'writes' => 0];
        $key = $mutation ? 'writes' : 'reads';
        $state[$key] = (int) $state[$key] + 1;
        $session->set(self::RATE_SESSION_KEY, $state);
        if($state[$key] > ($mutation ? 30 : 120)) throw new MailboxRestException('Mailbox API rate limit exceeded.', 429);
    }

    private function folder(): string {
        return $this->validFolder((string) ($this->wire()->input->get->folder ?: 'INBOX'));
    }

    private function bodyFolder(array $body): string {
        return $this->validFolder((string) ($body['folder'] ?? 'INBOX'));
    }

    private function validFolder(string $folder): string {
        $folder = trim($folder);
        if($folder === '' || strlen($folder) > 1024 || preg_match('/[\r\n\0]/', $folder)) throw new \InvalidArgumentException('Invalid folder.');
        return $folder;
    }

    private function uid(): int {
        $uid = (int) $this->wire()->input->get->uid;
        if($uid < 1) throw new \InvalidArgumentException('A positive message UID is required.');
        return $uid;
    }

    private function bodyUid(array $body): int {
        $uid = (int) ($body['uid'] ?? 0);
        if($uid < 1) throw new \InvalidArgumentException('A positive message UID is required.');
        return $uid;
    }

    private function sha256($value): string {
        $value = strtolower((string) $value);
        if(!preg_match('/^[a-f0-9]{64}$/', $value)) throw new \InvalidArgumentException('A SHA-256 link hash is required.');
        return $value;
    }

    private function proposalId(array $body): string {
        $id = strtolower((string) ($body['id'] ?? ''));
        if(!preg_match('/^[a-f0-9-]{16,64}$/', $id)) throw new \InvalidArgumentException('A valid proposal ID is required.');
        return $id;
    }

    private function instruction(array $body): string {
        if(!isset($body['instruction']) || !is_string($body['instruction'])) throw new \InvalidArgumentException('instruction must be a JSON string.');
        return $body['instruction'];
    }

    private function outgoingMessage(array $body, bool $requireSubject = true): array {
        foreach(['to', 'cc', 'bcc', 'reply_to'] as $key) {
            if(isset($body[$key]) && !is_array($body[$key])) throw new \InvalidArgumentException($key . ' must be a JSON array.');
        }
        if(isset($body['subject']) && !is_string($body['subject'])) throw new \InvalidArgumentException('subject must be a JSON string.');
        if(isset($body['body']) && !is_string($body['body'])) throw new \InvalidArgumentException('body must be a JSON string.');
        if(isset($body['attachments']) && !is_array($body['attachments'])) throw new \InvalidArgumentException('attachments must be a JSON array.');
        $message = [
            'to' => $body['to'] ?? [],
            'cc' => $body['cc'] ?? [],
            'bcc' => $body['bcc'] ?? [],
            'reply_to' => $body['reply_to'] ?? [],
            'subject' => (string) ($body['subject'] ?? ''),
            'body' => (string) ($body['body'] ?? ''),
            'attachments' => $body['attachments'] ?? [],
        ];
        if($requireSubject && $message['subject'] === '') throw new \InvalidArgumentException('subject is required.');
        return $message;
    }

    private function bodyText(array $body): string {
        if(isset($body['body']) && !is_string($body['body'])) throw new \InvalidArgumentException('body must be a JSON string.');
        $text = (string) ($body['body'] ?? '');
        if($text === '') throw new \InvalidArgumentException('body is required.');
        return $text;
    }

    private function searchFilters(array $source): array {
        $filters = [];
        foreach(['text', 'from', 'to', 'subject', 'since', 'before', 'state', 'flagged', 'answered', 'has_attachment', 'min_bytes', 'max_bytes'] as $name) {
            if(!isset($source[$name]) || $source[$name] === '') continue;
            if(!is_string($source[$name]) && !is_int($source[$name])) throw new \InvalidArgumentException('Invalid search filter type.');
            $filters[$name] = $source[$name];
        }
        return $filters;
    }

    private function attachmentPart(): string {
        $part = trim((string) $this->wire()->input->get->part);
        if(!preg_match('/^(?:0|[1-9][0-9]*(?:\.[1-9][0-9]*)*)$/', $part) || strlen($part) > 64) throw new \InvalidArgumentException('A valid attachment part is required.');
        return $part;
    }

    private function attachmentDownload(): string {
        try {
            $this->rateLimit(false);
            $selectedAccount = (int) $this->wire()->input->get->account;
            $api = $this->mailbox->api($this->wire()->user, $selectedAccount > 0 ? $selectedAccount : null);
            $attachment = $api->attachment($this->folder(), $this->uid(), $this->attachmentPart());
            $name = (string) $attachment['name'];
            $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'attachment';
            header('Content-Type: ' . (string) $attachment['type']);
            header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('Content-Length: ' . strlen((string) $attachment['content']));
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            return (string) $attachment['content'];
        } catch(WirePermissionException $error) {
            $this->headers();
            return $this->response(403, null, $error->getMessage());
        } catch(\InvalidArgumentException $error) {
            $this->headers();
            return $this->response(400, null, $error->getMessage());
        } catch(WireException $error) {
            $this->headers();
            return $this->response(400, null, $error->getMessage());
        } catch(\Throwable $error) {
            $this->headers();
            $this->wire()->log->save('mailbox-actions', 'Attachment download failed (' . get_class($error) . ').');
            return $this->response(500, null, 'Mailbox attachment request failed.');
        }
    }

    private function allow(string $method, array $allowed): void {
        if(!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new MailboxRestException('HTTP method not allowed.', 405);
        }
    }

    private function headers(): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    private function response(int $status, $result = null, ?string $error = null): string {
        http_response_code($status);
        $payload = $error === null ? ['ok' => true, 'result' => $result] : ['ok' => false, 'error' => $error];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : '{"ok":false,"error":"Encoding failed."}';
    }
}

final class MailboxRestException extends WireException {
    private $httpStatus;
    public function __construct(string $message, int $httpStatus) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
    public function status(): int { return $this->httpStatus; }
}

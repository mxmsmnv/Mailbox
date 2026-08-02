<?php namespace ProcessWire;

/** Bounded POST controller for admin message-list bulk actions. */
trait ProcessMailboxBulkConcern {

    public function ___executeBulkAction() {
        if(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw new Wire404Exception();
        if(!$this->wire()->session->CSRF->hasValidToken()) throw new WirePermissionException($this->_('Invalid security token.'));
        if(!$this->wire()->user->hasPermission(Mailbox::writePermission)) throw new WirePermissionException($this->_('Mailbox write permission is required.'));

        $redirect = (string) $this->wire()->page->url;
        try {
            $this->activeAccountId = $this->selectedAccountId();
            $folder = $this->decodeFolderToken((string) $this->wire()->input->post->folder);
            $action = strtolower((string) $this->wire()->input->post->bulk_action);
            $destination = (string) $this->wire()->input->post->destination;
            $uids = (array) $this->wire()->input->post->uids;
            $redirect = $this->url(['folder' => $this->encodeFolderToken($folder)]);
            $actor = 'user:' . (int) $this->wire()->user->id;
            $result = $this->mailbox->withAccount($this->activeAccountId, function() use ($folder, $uids, $action, $destination, $actor): array {
                return $this->mailbox->bulkMessageAction($folder, $uids, $action, $destination, $actor);
            });
            if($action === 'move') $redirect = $this->url(['folder' => $this->encodeFolderToken($destination)]);
            $labels = [
                'read' => $this->_('marked as read'),
                'unread' => $this->_('marked as unread'),
                'flag' => $this->_('flagged'),
                'unflag' => $this->_('unflagged'),
                'move' => $this->_('moved'),
                'delete' => $this->_('marked for deletion without permanent expunge'),
            ];
            $this->message(sprintf($this->_('%1$d messages %2$s.'), (int) $result['count'], $labels[$action] ?? $this->_('updated')));
        } catch(\Throwable $error) {
            $this->error($error->getMessage());
        }
        $this->wire()->session->redirect($redirect);
        return '';
    }
}

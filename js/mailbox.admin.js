/** Mailbox admin interactions, following the Collections sidebar/table pattern. */
(function() {
  'use strict';

  var storageKey = 'processmailbox_sidebar_collapsed';

  function saveSidebarState(collapsed) {
    try { localStorage.setItem(storageKey, collapsed ? '1' : '0'); } catch(error) {}
  }

  function initializeSidebar() {
    var sidebar = document.getElementById('MailboxSidebar');
    if(!sidebar) return;
    var toggle = sidebar.querySelector('[data-mailbox-sidebar-toggle]');
    if(!toggle) return;

    function sync() {
      toggle.setAttribute('aria-expanded', sidebar.classList.contains('is-collapsed') ? 'false' : 'true');
    }

    sync();
    toggle.addEventListener('click', function() {
      sidebar.classList.toggle('is-collapsed');
      saveSidebarState(sidebar.classList.contains('is-collapsed'));
      sync();
    });
  }

  function initializeMessageRows() {
    document.addEventListener('click', function(event) {
      var row = event.target.closest('[data-mailbox-href]');
      if(!row || event.target.closest('a,button,input,select,textarea,label')) return;
      window.location.href = row.dataset.mailboxHref;
    });
    document.addEventListener('keydown', function(event) {
      var row = event.target.closest('[data-mailbox-href]');
      if(!row || event.key !== 'Enter' || event.target.closest('a,button,input,select,textarea,label')) return;
      event.preventDefault();
      window.location.href = row.dataset.mailboxHref;
    });
  }

  function initializeDiscoveryCopy() {
    document.addEventListener('click', function(event) {
      var button = event.target.closest('[data-mailbox-copy-target]');
      if(!button) return;
      var source = document.getElementById(button.dataset.mailboxCopyTarget);
      if(!source) return;
      var text = source.textContent || '';
      var done = function() {
        var label = button.dataset.labelCopied || 'Copied';
        button.lastChild.textContent = label;
        window.setTimeout(function() { button.lastChild.textContent = button.dataset.labelDefault || 'Copy settings'; }, 1600);
      };
      if(navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(function() {});
        return;
      }
      var input = document.createElement('textarea');
      input.value = text;
      input.setAttribute('readonly', '');
      input.style.position = 'fixed';
      input.style.opacity = '0';
      document.body.appendChild(input);
      input.select();
      try { if(document.execCommand('copy')) done(); } catch(error) {}
      input.remove();
    });
  }

  function initializeAccountForms() {
    var presetNode = document.getElementById('MailboxAccountPresets');
    var presets = {};
    if(presetNode) {
      try { presets = JSON.parse(presetNode.textContent || '{}'); } catch(error) {}
    }
    document.querySelectorAll('[data-mailbox-account-form]').forEach(function(form) {
      var select = form.querySelector('[data-mailbox-auth-select]');
      if(!select) return;
      var sync = function() {
        form.querySelectorAll('[data-mailbox-auth-panel]').forEach(function(panel) {
          var visible = panel.dataset.mailboxAuthPanel === select.value;
          panel.hidden = !visible;
          panel.setAttribute('aria-hidden', visible ? 'false' : 'true');
        });
      };
      select.addEventListener('change', sync);
      var presetSelect = form.querySelector('[data-mailbox-preset-select]');
      var presetButton = form.querySelector('[data-mailbox-apply-preset]');
      var presetNote = form.querySelector('[data-mailbox-preset-note]');
      var showPresetNote = function() {
        var preset = presetSelect && presets[presetSelect.value];
        if(presetNote && preset) presetNote.textContent = preset.note || '';
      };
      if(presetSelect) presetSelect.addEventListener('change', showPresetNote);
      if(presetSelect && presetButton) presetButton.addEventListener('click', function() {
        var preset = presets[presetSelect.value];
        if(!preset) return;
        ['host', 'port', 'encryption', 'imapTransport', 'validateCertificate', 'defaultFolder', 'folderPattern', 'authentication', 'oauthProvider', 'smtpHost', 'smtpPort', 'smtpEncryption', 'smtpValidateCertificate'].forEach(function(name) {
          var field = form.querySelector('[name="' + name + '"]:not([type="hidden"])');
          if(!field || typeof preset[name] === 'undefined') return;
          if(field.type === 'checkbox') field.checked = !!Number(preset[name]);
          else field.value = preset[name];
          field.dispatchEvent(new Event('change', { bubbles: true }));
        });
        showPresetNote();
      });
      showPresetNote();
      sync();
    });
  }

  function initializeMessageReader() {
    document.querySelectorAll('[data-mailbox-message-views]').forEach(function(toolbar) {
      var reader = toolbar.closest('.Mailbox-message-reader');
      if(!reader) return;
      toolbar.addEventListener('click', function(event) {
        var button = event.target.closest('[data-mailbox-view]');
        if(!button) return;
        var view = button.dataset.mailboxView;
        toolbar.querySelectorAll('[data-mailbox-view]').forEach(function(candidate) {
          var active = candidate === button;
          candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
          candidate.classList.toggle('uk-button-primary', active);
          candidate.classList.toggle('uk-button-default', !active);
        });
        reader.querySelectorAll('[data-mailbox-view-panel]').forEach(function(panel) {
          panel.hidden = panel.dataset.mailboxViewPanel !== view;
        });
      });
    });
    document.querySelectorAll('form[data-mailbox-confirm]').forEach(function(form) {
      form.addEventListener('submit', function(event) {
        if(!window.confirm(form.dataset.mailboxConfirm || 'Continue?')) event.preventDefault();
      });
    });
  }

  function initializeMessageComposeDialogs() {
    var composers = Array.prototype.slice.call(document.querySelectorAll('[data-mailbox-compose]'));
    if(!composers.length) return;

    function closeComposer(composer, restoreFocus) {
      if(!composer.open) return;
      composer.removeAttribute('open');
      if(restoreFocus) {
        var summary = composer.querySelector('summary');
        if(summary) summary.focus();
      }
    }

    document.addEventListener('click', function(event) {
      var activeComposer = event.target.closest('[data-mailbox-compose]');
      if(activeComposer) {
        if(event.target.closest('summary')) composers.forEach(function(composer) {
          if(composer !== activeComposer) closeComposer(composer, false);
        });
        return;
      }
      composers.forEach(function(composer) { closeComposer(composer, false); });
    });

    document.addEventListener('keydown', function(event) {
      if(event.key !== 'Escape') return;
      var closed = false;
      composers.forEach(function(composer) {
        if(!composer.open) return;
        closeComposer(composer, !closed);
        closed = true;
      });
      if(closed) event.preventDefault();
    });
  }

  function mailboxAjax(form, endpoint) {
    return fetch(endpoint || form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(function(response) {
      return response.json().catch(function() { return {}; }).then(function(result) {
        if(!response.ok || !result.ok) throw new Error(result.message || 'Request failed.');
        return result;
      });
    });
  }

  function initializeMailboxAi() {
    document.querySelectorAll('form[data-mailbox-ai-summary]').forEach(function(form) {
      var panel = form.closest('[data-mailbox-ai]');
      var result = panel && panel.querySelector('[data-mailbox-ai-result]');
      var button = form.querySelector('button[type="submit"]');
      if(!result || !button || typeof window.fetch !== 'function') return;
      form.addEventListener('submit', function(event) {
        event.preventDefault();
        if(button.disabled) return;
        button.disabled = true;
        result.hidden = false;
        result.classList.add('is-loading');
        result.textContent = 'Analyzing message…';
        mailboxAjax(form).then(function(response) {
          result.classList.remove('is-loading', 'is-error');
          result.textContent = response.content || '';
        }).catch(function(error) {
          result.classList.remove('is-loading');
          result.classList.add('is-error');
          result.textContent = error.message || 'AI request failed.';
        }).then(function() { button.disabled = false; });
      });
    });

    document.querySelectorAll('form[data-mailbox-reply-form]').forEach(function(form) {
      var mode = form.querySelector('[data-mailbox-reply-mode]');
      var controls = form.querySelector('[data-mailbox-ai-reply-controls]');
      var draftButton = form.querySelector('[data-mailbox-ai-draft]');
      var draftStatus = form.querySelector('[data-mailbox-ai-draft-status]');
      var body = form.querySelector('[data-mailbox-reply-body]');
      var sendButton = form.querySelector('button[type="submit"]');
      var sendStatus = form.querySelector('[data-mailbox-reply-status]');
      if(!mode || !controls || !body || !sendButton || typeof window.fetch !== 'function') return;

      function syncMode() { controls.hidden = mode.value !== 'ai'; }
      mode.addEventListener('change', syncMode);
      syncMode();

      if(draftButton && draftStatus) draftButton.addEventListener('click', function() {
        draftButton.disabled = true;
        draftStatus.className = 'is-loading';
        draftStatus.textContent = 'Generating draft…';
        mailboxAjax(form, controls.dataset.endpoint).then(function(response) {
          body.value = response.content || '';
          body.dispatchEvent(new Event('input', {bubbles: true}));
          body.focus();
          draftStatus.className = 'is-ready';
          draftStatus.textContent = 'Draft ready. Review it before sending.';
        }).catch(function(error) {
          draftStatus.className = 'is-error';
          draftStatus.textContent = error.message || 'AI draft failed.';
        }).then(function() { draftButton.disabled = false; });
      });

      form.addEventListener('submit', function(event) {
        event.preventDefault();
        if(sendButton.disabled || !body.value.trim()) return;
        sendButton.disabled = true;
        if(sendStatus) { sendStatus.className = 'is-loading'; sendStatus.textContent = 'Sending…'; }
        mailboxAjax(form).then(function(response) {
          if(sendStatus) { sendStatus.className = 'is-ready'; sendStatus.textContent = response.message || 'Reply sent.'; }
          body.setAttribute('readonly', 'readonly');
          mode.disabled = true;
          if(draftButton) draftButton.disabled = true;
        }).catch(function(error) {
          if(sendStatus) { sendStatus.className = 'is-error'; sendStatus.textContent = error.message || 'Reply failed.'; }
          sendButton.disabled = false;
        });
      });
    });
  }

  function initializeBulkActions() {
    document.querySelectorAll('[data-mailbox-bulk-form]').forEach(function(form) {
      var messages = Array.prototype.slice.call(form.querySelectorAll('[data-mailbox-select]'));
      var selectAll = form.querySelector('[data-mailbox-select-all]');
      var bar = form.querySelector('[data-mailbox-bulk-bar]');
      var count = form.querySelector('[data-mailbox-bulk-count]');
      var action = form.querySelector('[data-mailbox-bulk-action]');
      var destination = form.querySelector('[data-mailbox-bulk-destination]');
      var clear = form.querySelector('[data-mailbox-bulk-clear]');
      if(!selectAll || !bar || !count || !action || !clear) return;

      function syncDestination() {
        if(!destination) return;
        var moving = action.value === 'move';
        destination.hidden = !moving;
        destination.disabled = !moving;
        destination.required = moving;
        if(!moving) destination.value = '';
      }

      function syncSelection() {
        var selected = messages.filter(function(input) { return input.checked; });
        count.textContent = String(selected.length);
        bar.hidden = selected.length === 0;
        selectAll.checked = messages.length > 0 && selected.length === messages.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < messages.length;
        messages.forEach(function(input) {
          var row = input.closest('tr');
          if(row) row.classList.toggle('is-selected', input.checked);
        });
      }

      selectAll.addEventListener('change', function() {
        messages.forEach(function(input) { input.checked = selectAll.checked; });
        syncSelection();
      });
      messages.forEach(function(input) { input.addEventListener('change', syncSelection); });
      action.addEventListener('change', syncDestination);
      clear.addEventListener('click', function() {
        messages.forEach(function(input) { input.checked = false; });
        action.value = '';
        syncDestination();
        syncSelection();
      });
      form.addEventListener('submit', function(event) {
        if(!messages.some(function(input) { return input.checked; })) {
          event.preventDefault();
          return;
        }
        if(action.value === 'delete' && !window.confirm(form.dataset.mailboxBulkDeleteConfirm || 'Continue?')) event.preventDefault();
      });
      syncDestination();
      syncSelection();
    });
  }

  function initializeSeenReceipt() {
    var form = document.querySelector('form[data-mailbox-seen]');
    if(!form || typeof window.fetch !== 'function') return;
    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(function(response) {
      if(!response.ok) throw new Error('seen_failed');
      return response.json();
    }).then(function(result) {
      if(result.ok) form.remove();
    }).catch(function() {});
  }

  if(document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { initializeSidebar(); initializeMessageRows(); initializeDiscoveryCopy(); initializeAccountForms(); initializeMessageReader(); initializeMessageComposeDialogs(); initializeMailboxAi(); initializeBulkActions(); initializeSeenReceipt(); });
  } else {
    initializeSidebar();
    initializeMessageRows();
    initializeDiscoveryCopy();
    initializeAccountForms();
    initializeMessageReader();
    initializeMessageComposeDialogs();
    initializeMailboxAi();
    initializeBulkActions();
    initializeSeenReceipt();
  }
})();

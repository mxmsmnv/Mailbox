/** Progressive enhancements for the native ProcessWire Mailbox config form. */
(function() {
  'use strict';

  function initializeMailboxConfig() {
    var form = document.getElementById('ModuleEditForm');
    if(!form || !document.getElementById('Inputfield_mailboxOverview')) return;
    form.classList.add('MailboxConfigForm');

    function revealSection(target, smooth) {
      if(!target) return;
      if(target.classList.contains('InputfieldStateCollapsed')) {
        var header = target.querySelector(':scope > .InputfieldHeader');
        if(header) header.click();
      }
      target.classList.add('MailboxConfig-target');
      window.setTimeout(function() { target.classList.remove('MailboxConfig-target'); }, 1800);
      window.requestAnimationFrame(function() {
        target.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
      });
    }

    form.addEventListener('click', function(event) {
      var link = event.target.closest('.MailboxConfig-nav a[href^="#"]');
      if(!link) return;
      var target = document.querySelector(link.getAttribute('href'));
      if(!target) return;
      event.preventDefault();
      revealSection(target, true);
    });

    if(window.location.hash && /^#Inputfield_[A-Za-z0-9_-]+$/.test(window.location.hash)) {
      window.setTimeout(function() { revealSection(document.querySelector(window.location.hash), false); }, 80);
    }
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeMailboxConfig);
  else initializeMailboxConfig();
})();

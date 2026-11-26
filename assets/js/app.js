/* Ty Kolle Hockey Development – Registration System (no dependencies) */
(function () {
  'use strict';

  // Mobile nav
  var toggle = document.querySelector('[data-nav-toggle]');
  var nav = document.querySelector('[data-nav]');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Confirm dangerous actions: <button data-confirm="...">
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

  // Step 2: show parent/guardian fields only for players under 18
  var adultForm = document.querySelector('[data-adult-form]');
  if (adultForm) {
    var parent = adultForm.querySelector('[data-parent-fields]');
    var sync = function () {
      var v = adultForm.querySelector('input[name="is_adult"]:checked');
      parent.hidden = !v || v.value === '1';
    };
    adultForm.addEventListener('change', sync);
    sync();
  }

  // Step 3: switch between profile URL and "no profile" explanation
  var profileForm = document.querySelector('[data-profile-form]');
  if (profileForm) {
    var urlField = profileForm.querySelector('[data-url-field]');
    var reasonField = profileForm.querySelector('[data-reason-field]');
    var urlInput = urlField.querySelector('input');
    var placeholders = {
      eliteprospects: 'https://www.eliteprospects.com/player/123456/first-last',
      league: 'https://ontariohockeyleague.com/players/12345',
      other: 'https://…'
    };
    var syncProfile = function () {
      var v = profileForm.querySelector('input[name="profile_source"]:checked');
      var none = v && v.value === 'none';
      urlField.hidden = none;
      reasonField.hidden = !none;
      if (v && placeholders[v.value]) urlInput.placeholder = placeholders[v.value];
    };
    profileForm.addEventListener('change', syncProfile);
    syncProfile();
  }

  // Step 4: live total of selected camps
  var campForm = document.querySelector('[data-camp-form]');
  if (campForm) {
    var currency = campForm.getAttribute('data-currency') || '$';
    var totalEl = campForm.querySelector('[data-total]');
    var countEl = campForm.querySelector('[data-count]');
    var fmt = function (n) {
      return currency + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var recalc = function () {
      var total = 0, count = 0;
      campForm.querySelectorAll('input[name="camps[]"]:checked').forEach(function (cb) {
        total += parseFloat(cb.getAttribute('data-price')) || 0;
        count++;
      });
      totalEl.textContent = fmt(total);
      countEl.textContent = count;
    };
    campForm.addEventListener('change', recalc);
    recalc();
  }
  // Email templates / compose: click a placeholder to insert it where the cursor was
  var lastField = null;
  document.querySelectorAll('[data-insert-target]').forEach(function (f) {
    f.addEventListener('focus', function () { lastField = f; });
  });
  document.querySelectorAll('[data-insert]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var f = lastField || document.querySelector('textarea[data-insert-target]');
      if (!f) return;
      var text = btn.getAttribute('data-insert');
      var start = f.selectionStart || 0, end = f.selectionEnd || 0;
      f.value = f.value.slice(0, start) + text + f.value.slice(end);
      f.focus();
      f.selectionStart = f.selectionEnd = start + text.length;
    });
  });

  // Compose: load a template's subject/body; show the right audience fields
  var compose = document.querySelector('[data-compose]');
  if (compose) {
    var tpls = JSON.parse(compose.getAttribute('data-templates') || '{}');
    var tplSel = compose.querySelector('[data-template-select]');
    tplSel.addEventListener('change', function () {
      var t = tpls[tplSel.value];
      var subj = compose.querySelector('[name=subject]'), body = compose.querySelector('[name=body]');
      if ((subj.value || body.value) && !window.confirm('Replace the current subject and message with this template?')) return;
      subj.value = t ? t.subject : '';
      body.value = t ? t.body : '';
    });
    var aud = compose.querySelector('[data-audience]');
    var syncAud = function () {
      compose.querySelectorAll('[data-show-for]').forEach(function (el) {
        el.hidden = el.getAttribute('data-show-for') !== aud.value;
      });
    };
    aud.addEventListener('change', syncAud);
    syncAud();
  }

  // Player decision: offer the accepted / denied email for those statuses
  var notify = document.querySelector('[data-notify]');
  if (notify) {
    var form = notify.closest('form');
    var syncNotify = function () {
      var v = form.querySelector('input[name=verification_status]:checked');
      var val = v ? v.value : '';
      notify.hidden = val !== 'verified' && val !== 'rejected';
      notify.querySelectorAll('[data-notify-label]').forEach(function (l) {
        l.hidden = l.getAttribute('data-notify-label') !== val;
      });
    };
    form.addEventListener('change', syncNotify);
    syncNotify();
  }
})();

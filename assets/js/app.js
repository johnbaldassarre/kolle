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
})();

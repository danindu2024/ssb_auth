/* Sign-in form behaviour. The form works without this script (server-side checks remain);
   this adds: validation on submit only (never on blur), errors clear as the person types,
   never-disabled submit that focuses the first problem, eye-icon password reveal,
   Caps Lock hint, inline "Signing in…" state. */
(function () {
  'use strict';
  var form = document.getElementById('signin-form');
  if (!form) return;

  var btn = document.getElementById('signin-btn');
  var label = btn.querySelector('.btn-label');
  var fields = ['login_id', 'password'].map(function (id) {
    var el = document.getElementById(id);
    return { el: el, err: document.getElementById(id + '-error'), invalid: false };
  });

  function valid(f) { return f.el.value.trim() !== ''; }
  function show(f) { f.invalid = true; f.el.setAttribute('aria-invalid', 'true'); f.err.hidden = false; }
  function clear(f) { f.invalid = false; f.el.removeAttribute('aria-invalid'); f.err.hidden = true; }

  var scripted = false;                                   // true while WE move focus (keep the error we just showed)
  fields.forEach(function (f) {
    f.el.addEventListener('focus', function () { if (f.invalid && !scripted) clear(f); });   // the person engaging clears the error
    f.el.addEventListener('input', function () { if (f.invalid) clear(f); });   // first corrective keystroke too
  });

  // Reveal password with an eye icon (a functional control, so it is the one allowed adornment)
  var pw = document.getElementById('password');
  var toggle = document.getElementById('pw-toggle');
  var eye = toggle.querySelector('.icon-eye');
  var eyeOff = toggle.querySelector('.icon-eye-off');
  toggle.hidden = false;
  toggle.addEventListener('click', function () {
    var reveal = pw.type === 'password';
    pw.type = reveal ? 'text' : 'password';
    eye.toggleAttribute('hidden', reveal);          // SVG elements have no .hidden property
    eyeOff.toggleAttribute('hidden', !reveal);
    var text = reveal ? 'Hide password' : 'Show password';
    toggle.setAttribute('aria-label', text);
    toggle.setAttribute('title', text);
    toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
    pw.focus();
  });

  // Caps Lock hint while typing a password
  var caps = document.getElementById('caps-hint');
  function checkCaps(e) {
    if (typeof e.getModifierState === 'function') caps.hidden = !e.getModifierState('CapsLock');
  }
  pw.addEventListener('keydown', checkCaps);
  pw.addEventListener('keyup', checkCaps);
  pw.addEventListener('blur', function () { caps.hidden = true; });

  // Submit: never disabled beforehand. Incomplete -> inline errors + focus the first problem.
  var submitting = false;
  form.addEventListener('submit', function (e) {
    if (submitting) { e.preventDefault(); return; }
    var first = null;
    fields.forEach(function (f) {
      if (!valid(f)) { show(f); first = first || f; }
    });
    if (first) {
      e.preventDefault();
      scripted = true;
      first.el.focus();
      scripted = false;
      first.el.scrollIntoView({ block: 'center' });
      return;
    }
    submitting = true;
    btn.setAttribute('aria-busy', 'true');
    window.setTimeout(function () {                      // no loader for fast responses
      if (!submitting) return;
      btn.classList.add('is-loading');
      label.textContent = 'Signing in…';
    }, 100);
  });

  // Back/forward cache: restore a usable form
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    submitting = false;
    btn.removeAttribute('aria-busy');
    btn.classList.remove('is-loading');
    label.textContent = 'Sign in';
  });
})();

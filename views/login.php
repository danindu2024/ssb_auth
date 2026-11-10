<?php use Auth\{Http, Session}; ?>
<section class="card auth-card" aria-labelledby="signin-title">
  <h1 id="signin-title">Sign in</h1>
  <?php if ($error): ?><p class="form-alert" role="alert"><?= Http::e($error) ?></p><?php endif; ?>
  <form method="post" action="/login" id="signin-form" autocomplete="on" novalidate>
    <input type="hidden" name="csrf" value="<?= Http::e(Session::csrf()) ?>">

    <div class="field">
      <label for="login_id">Username</label>
      <input id="login_id" name="login_id" value="<?= Http::e($loginId) ?>" maxlength="64"
             autocomplete="username" autocapitalize="none" spellcheck="false"
             aria-describedby="login_id-error" required <?= $loginId === '' ? 'autofocus' : '' ?>>
      <p class="field-error" id="login_id-error" hidden>Enter your username.</p>
    </div>

    <div class="field">
      <label for="password">Password</label>
      <div class="pw">
        <input id="password" name="password" type="password" autocomplete="current-password"
               aria-describedby="password-error caps-hint" required <?= $loginId !== '' ? 'autofocus' : '' ?>>
        <button type="button" class="pw-toggle" id="pw-toggle" aria-controls="password" aria-pressed="false" aria-label="Show password" title="Show password" hidden>
          <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="icon-eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden><path d="M17.9 17.9A10.9 10.9 0 0 1 12 19c-6.4 0-10-7-10-7a18.7 18.7 0 0 1 4.1-5.1M9.9 5.2A9.9 9.9 0 0 1 12 5c6.4 0 10 7 10 7a18.6 18.6 0 0 1-2.2 3.2M1 1l22 22"/><path d="M14.1 14.1a3 3 0 1 1-4.2-4.2"/></svg>
        </button>
      </div>
      <p class="field-error" id="password-error" hidden>Enter your password.</p>
      <p class="field-hint" id="caps-hint" role="status" hidden>Caps Lock is on.</p>
    </div>

    <button type="submit" class="btn-submit" id="signin-btn"><span class="btn-label">Sign in</span></button>
  </form>
  <p class="help">Forgot password? Contact head office IT division</p>
</section>
<script src="/assets/login.js" defer></script>

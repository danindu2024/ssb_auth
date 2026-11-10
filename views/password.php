<?php use Auth\{Http, Session, Password}; ?>
<section class="card narrow">
  <h1><?= $forced ? 'Set a new password' : 'Change password' ?></h1>
  <?php if ($forced): ?><p class="muted">You must choose a new password before continuing.</p><?php endif; ?>
  <?php if ($error): ?><p class="flash err" role="alert"><?= Http::e($error) ?></p><?php endif; ?>
  <form method="post" action="/password">
    <input type="hidden" name="csrf" value="<?= Http::e(Session::csrf()) ?>">
    <label for="current">Current password</label>
    <input id="current" name="current" type="password" autocomplete="current-password" required>
    <label for="new">New password</label>
    <input id="new" name="new" type="password" autocomplete="new-password" minlength="<?= Password::MIN ?>" required>
    <p class="muted small">At least <?= Password::MIN ?> characters. Avoid common words and your Login ID.</p>
    <label for="confirm">Repeat new password</label>
    <input id="confirm" name="confirm" type="password" autocomplete="new-password" required>
    <button type="submit">Save password</button>
  </form>
</section>

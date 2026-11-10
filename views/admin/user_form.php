<?php use Auth\{Http, Session}; $action = $isNew ? '/admin/users/new' : '/admin/users/' . (int)$uid; ?>
<section class="card">
  <h1><?= $isNew ? 'New user' : 'Edit user' ?></h1>
  <?php if ($err): ?><p class="flash err" role="alert"><?= Http::e($err) ?></p><?php endif; ?>
  <form method="post" action="<?= Http::e($action) ?>">
    <input type="hidden" name="csrf" value="<?= Http::e(Session::csrf()) ?>">
    <label for="login_id">Login ID</label>
    <input id="login_id" name="login_id" value="<?= Http::e($f['login_id']) ?>" maxlength="64" required>
    <label for="full_name">Full name</label>
    <input id="full_name" name="full_name" value="<?= Http::e($f['full_name']) ?>" maxlength="150" required>
    <label for="email">Email (optional)</label>
    <input id="email" name="email" type="email" value="<?= Http::e($f['email']) ?>" maxlength="190">
    <fieldset><legend>Can sign in to</legend>
      <?php foreach ($clients as $c): ?>
        <label class="check"><input type="checkbox" name="apps[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], array_map('intval', $f['apps']), true) ? 'checked' : '' ?>> <?= Http::e($c['name']) ?></label>
      <?php endforeach; ?>
      <?php if (!$clients): ?><p class="muted">Register an application first.</p><?php endif; ?>
      <p class="muted small">The role inside each application is set in that application.</p>
    </fieldset>
    <label class="check"><input type="checkbox" name="is_admin" <?= $f['is_admin'] ? 'checked' : '' ?>> Administrator of this sign-in service</label>
    <?php if (!$isNew): ?>
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="disabled" <?= $f['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
      </select>
    <?php endif; ?>
    <div class="row"><button type="submit"><?= $isNew ? 'Create user' : 'Save changes' ?></button><a href="/admin/users">Cancel</a></div>
    <?php if ($isNew): ?><p class="muted small">A random temporary password is generated and shown once.</p><?php endif; ?>
  </form>
</section>

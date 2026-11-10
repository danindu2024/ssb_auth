<?php use Auth\{Http, Session}; $action = $isNew ? '/admin/clients/new' : '/admin/clients/' . (int)$cid; ?>
<section class="card">
  <h1><?= $isNew ? 'Register application' : 'Edit application' ?></h1>
  <?php if ($err): ?><p class="flash err" role="alert"><?= Http::e($err) ?></p><?php endif; ?>
  <form method="post" action="<?= Http::e($action) ?>">
    <input type="hidden" name="csrf" value="<?= Http::e(Session::csrf()) ?>">
    <label for="client_id">Client ID</label>
    <input id="client_id" name="client_id" value="<?= Http::e($f['client_id']) ?>" <?= $isNew ? 'required' : 'readonly' ?> maxlength="64">
    <label for="name">Name</label>
    <input id="name" name="name" value="<?= Http::e($f['name']) ?>" maxlength="120" required>
    <label for="redirect_uris">Redirect URIs (one per line, exact match)</label>
    <textarea id="redirect_uris" name="redirect_uris" rows="3" required><?= Http::e($f['redirect_uris']) ?></textarea>
    <label for="logout_uris">Post-logout URIs (one per line)</label>
    <textarea id="logout_uris" name="logout_uris" rows="2"><?= Http::e($f['logout_uris']) ?></textarea>
    <label for="launch_url">Launch URL (tile on the applications page)</label>
    <input id="launch_url" name="launch_url" value="<?= Http::e($f['launch_url']) ?>" maxlength="255">
    <?php if (!$isNew): ?>
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="active" <?= $f['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="disabled" <?= $f['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
      </select>
      <label class="check"><input type="checkbox" name="can_provision" value="1" <?= !empty($f['can_provision']) ? 'checked' : '' ?>>
        Allow this application to create users and change their passwords through the API</label>
    <?php endif; ?>
    <div class="row"><button type="submit"><?= $isNew ? 'Register' : 'Save changes' ?></button><a href="/admin/clients">Cancel</a></div>
  </form>
</section>

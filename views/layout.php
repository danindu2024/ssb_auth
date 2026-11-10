<?php
use Auth\{Http, Session};
$nav = $me ?? $user ?? null;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= Http::e($title) ?> · SSB Management</title>
<link rel="icon" type="image/png" href="/assets/logo.png">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="<?= $brand ? 'brand' : 'plain' ?>">
<header class="top">
  <?php if ($nav && $brand): ?>
  <?php $who = trim((string)($nav['full_name'] ?? '')) ?: (string)$nav['login_id']; ?>
  <div class="userbar">
    <?php if ((int)$nav['is_admin'] === 1): ?><a class="userbar-admin" href="/admin/users">Admin</a><?php endif; ?>
    <span class="userbar-user">
      <span class="avatar" aria-hidden="true"><?= Http::e(mb_strtoupper(mb_substr($who, 0, 1))) ?></span>
      <span class="userbar-name" title="<?= Http::e($who) ?>"><?= Http::e($who) ?></span>
    </span>
    <form method="post" action="/logout" class="inline">
      <input type="hidden" name="csrf" value="<?= Http::e(Session::csrf()) ?>">
      <button class="signout" type="submit">Sign out</button>
    </form>
  </div>
  <?php elseif ($nav): ?>
  <nav>
    <a href="/">Applications</a>
    <?php if ((int)$nav['is_admin'] === 1): ?>
      <a href="/admin/users">Users</a><a href="/admin/clients">Apps</a><a href="/admin/audit">Audit</a>
    <?php endif; ?>
    <a href="/password">Password</a>
    <form method="post" action="/logout" class="inline">
      <input type="hidden" name="csrf" value="<?= Http::e(Session::csrf()) ?>">
      <button class="link" type="submit">Sign out (<?= Http::e($nav['login_id']) ?>)</button>
    </form>
  </nav>
  <?php endif; ?>
</header>
<main class="<?= $brand ? 'hero' : '' ?>">
<?php if ($brand): ?>
  <div class="brandmark">
    <div class="logo"><img src="/assets/logo.png" alt="" width="124" height="124"></div>
    <p class="company">SSB Management Portal</p>
  </div>
<?php endif; ?>
<?php foreach (Session::pullFlashes() as [$kind, $msg]): ?>
  <p class="flash <?= Http::e($kind) ?>" role="status"><?= Http::e($msg) ?></p>
<?php endforeach; ?>
<?= $content ?>
</main>
<?php if ($brand): ?><footer class="foot">&copy; <?= date('Y') ?> SSB Management. All rights reserved.</footer><?php endif; ?>
</body>
</html>

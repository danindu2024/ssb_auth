<?php use Auth\{Http, Session}; ?>
<div class="row"><h1>Users</h1><a class="btn" href="/admin/users/new">New user</a></div>
<div class="scroll"><table>
<thead><tr><th>Login ID</th><th>Name</th><th>Email</th><th>Apps</th><th>Status</th><th>Last sign-in (UTC)</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= Http::e($r['login_id']) ?><?= (int)$r['is_admin'] ? ' <span class="badge">admin</span>' : '' ?></td>
  <td><?= Http::e($r['full_name']) ?></td>
  <td><?= Http::e($r['email']) ?></td>
  <td><?= Http::e($r['apps']) ?></td>
  <td><span class="badge <?= $r['status'] === 'active' ? 'ok' : 'off' ?>"><?= Http::e($r['status']) ?></span></td>
  <td><?= Http::e($r['last_login_at'] ?? '—') ?></td>
  <td class="actions">
    <a href="/admin/users/<?= (int)$r['id'] ?>">Edit</a>
    <form method="post" action="/admin/users/<?= (int)$r['id'] ?>/reset" class="inline">
      <input type="hidden" name="csrf" value="<?= Http::e(Session::csrf()) ?>">
      <button class="link" type="submit">Reset password</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

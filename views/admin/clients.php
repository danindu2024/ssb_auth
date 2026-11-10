<?php use Auth\{Http, Session}; ?>
<div class="row"><h1>Applications</h1><a class="btn" href="/admin/clients/new">Register application</a></div>
<div class="scroll"><table>
<thead><tr><th>Name</th><th>Client ID</th><th>Launch URL</th><th>Status</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= Http::e($r['name']) ?></td><td><code><?= Http::e($r['client_id']) ?></code></td><td><?= Http::e($r['launch_url']) ?></td>
  <td><span class="badge <?= $r['status'] === 'active' ? 'ok' : 'off' ?>"><?= Http::e($r['status']) ?></span></td>
  <td class="actions">
    <a href="/admin/clients/<?= (int)$r['id'] ?>">Edit</a>
    <form method="post" action="/admin/clients/<?= (int)$r['id'] ?>/rotate" class="inline">
      <input type="hidden" name="csrf" value="<?= Http::e(Session::csrf()) ?>">
      <button class="link" type="submit">Rotate secret</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>

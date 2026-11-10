<?php use Auth\Http; ?>
<div class="row"><h1>Audit log</h1>
  <form method="get" action="/admin/audit" class="inline"><input name="login_id" value="<?= Http::e($q) ?>" placeholder="Filter by Login ID" aria-label="Filter by Login ID"><button type="submit">Filter</button></form></div>
<div class="scroll"><table>
<thead><tr><th>Time (UTC)</th><th>Event</th><th>Login ID</th><th>App</th><th>IP</th><th>Detail</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr><td><?= Http::e($r['created_at']) ?></td><td><?= Http::e($r['event']) ?></td><td><?= Http::e($r['login_id']) ?></td>
<td><?= Http::e($r['client_id']) ?></td><td><?= Http::e($r['ip']) ?></td><td><?= Http::e($r['detail']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>

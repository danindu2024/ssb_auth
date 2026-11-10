<?php use Auth\Http; ?>
<section class="card">
  <h1><?= Http::e($heading) ?></h1>
  <p class="flash warn"><?= Http::e($note) ?></p>
  <dl class="kv">
  <?php foreach ($lines as $k => $v): ?>
    <dt><?= Http::e($k) ?></dt><dd><code><?= Http::e($v) ?></code></dd>
  <?php endforeach; ?>
  </dl>
  <p><a class="btn" href="<?= Http::e($back) ?>">Done</a></p>
</section>

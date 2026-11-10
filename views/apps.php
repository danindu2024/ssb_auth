<?php
use Auth\Http;
/** Tile image: public/assets/apps/<client_id>.(png|jpg|jpeg|webp|svg), if one exists. */
$tileImage = static function (array $a): ?string {
    $cid = (string)($a['client_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $cid)) return null;
    foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
        if (is_file(AUTH_ROOT . "/public/assets/apps/$cid.$ext")) return "/assets/apps/$cid.$ext";
    }
    return null;
};
?>
<p class="muted">Your applications</p>
<?php if (!$apps): ?>
  <p class="card narrow">No applications are assigned to your account yet. Contact your system administrator.</p>
<?php else: ?>
  <ul class="tiles">
  <?php foreach ($apps as $i => $a): ?>
    <li><a class="app c<?= $i % 6 ?>" href="<?= Http::e($a['launch_url']) ?>" rel="noopener">
      <?php $img = $tileImage($a); ?>
      <span class="circle<?= $img ? ' has-img img-' . Http::e((string)$a['client_id']) : '' ?>" aria-hidden="true">
        <?php if ($img): ?><img src="<?= Http::e($img) ?>" alt="" width="120" height="120"><?php else: ?>
        <b><?= Http::e(strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $a['name']), 0, 3))) ?></b><?php endif; ?>
      </span>
      <span class="app-name"><?= Http::e($a['name']) ?></span>
    </a></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php use Auth\Http; ?>
<section class="card narrow">
  <h1><?= Http::e($title) ?></h1>
  <p><?= Http::e($message) ?></p>
  <p><a href="/">Back to sign-in</a></p>
</section>

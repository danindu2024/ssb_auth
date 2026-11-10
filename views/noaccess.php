<?php use Auth\Http; ?>
<section class="card narrow">
  <h1>No access to <?= Http::e($app) ?></h1>
  <p>Your account (<?= Http::e($user['login_id']) ?>) has not been given access to this application. Ask your system administrator to add you.</p>
  <p><a href="/apps">Back to your applications</a></p>
</section>

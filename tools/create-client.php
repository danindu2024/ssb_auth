<?php
declare(strict_types=1);
// Usage: php tools/create-client.php <client_id> "<Name>" <redirect_uri> [launch_url] [logout_uri]
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/bootstrap.php';
use Auth\{Clients, Db, Audit};

[$self, $cid, $name, $redirect] = array_pad($argv, 4, '');
$launch = $argv[4] ?? '';
$logout = $argv[5] ?? '';
if ($cid === '' || $name === '' || $redirect === '') { fwrite(STDERR, "Usage: php tools/create-client.php <client_id> \"<Name>\" <redirect_uri> [launch_url] [logout_uri]\n"); exit(1); }
$err = Clients::validate($cid, $name, [$redirect], $logout !== '' ? [$logout] : [], $launch);
if ($err === null && Db::one('SELECT 1 AS x FROM clients WHERE client_id = ?', [$cid])) { $err = 'Client already exists.'; }
if ($err !== null) { fwrite(STDERR, "$err\n"); exit(1); }
[, $secret] = Clients::create($cid, $name, [$redirect], $logout !== '' ? [$logout] : [], $launch);
Audit::log('client_created', null, null, $cid, 'cli');
echo "client_id:     $cid\nclient_secret: $secret\n(store the secret server-side; it cannot be shown again)\n";

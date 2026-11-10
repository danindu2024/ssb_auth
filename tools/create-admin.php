<?php
declare(strict_types=1);
// Usage: php tools/create-admin.php <login_id> "<Full Name>" [email]
// Prompts for the password (not echoed). Creates the first administrator of the sign-in service.
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/bootstrap.php';
use Auth\{Password, Users, Audit};

[$self, $login, $name] = array_pad($argv, 3, '');
$email = $argv[3] ?? '';
if ($login === '' || $name === '') { fwrite(STDERR, "Usage: php tools/create-admin.php <login_id> \"<Full Name>\" [email]\n"); exit(1); }
if (($e = Users::validate($login, $name, $email)) !== null) { fwrite(STDERR, "$e\n"); exit(1); }

$pw = getenv('AUTH_ADMIN_PASSWORD') ?: '';
if ($pw === '') {
    fwrite(STDOUT, 'Password: ');
    @system('stty -echo');
    $pw = rtrim((string)fgets(STDIN), "\r\n");
    @system('stty echo');
    fwrite(STDOUT, "\n");
}
if (($e = Password::policyError($pw, $login)) !== null) { fwrite(STDERR, "$e\n"); exit(1); }
$id = Users::create($login, $name, $email ?: null, $pw, true, false);
Audit::log('user_created', $id, $login, null, 'cli admin');
echo "Administrator '$login' created (id $id).\n";

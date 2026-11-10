<?php
declare(strict_types=1);
// End-to-end test against the built-in PHP server. WIPES the database named by AUTH_DB_*.
// Usage: AUTH_DB_HOST=127.0.0.1 AUTH_DB_PORT=33077 AUTH_DB_NAME=ssb_central_auth_test AUTH_DB_USER=t AUTH_DB_PASS=t php tests/flow_test.php
require __DIR__ . '/../src/bootstrap.php';
use Auth\{Db, Users, Clients, Password};

$port = 8099; $base = "http://127.0.0.1:$port";
putenv('AUTH_BASE_URL=' . $base);
putenv('AUTH_PORTAL_URL');                      // not set: the front door is the default landing page
$pdo = Db::pdo();
foreach (['audit_log','login_attempts','auth_codes','user_apps','clients','users'] as $t) { $pdo->exec("DROP TABLE IF EXISTS $t"); }
$pdo->exec((string)file_get_contents(__DIR__ . '/../database/schema.sql'));

$adminId = Users::create('admin', 'Admin', null, 'Admin-pass-9x', true, false);
$alice   = Users::create('1001', 'Alice A', 'alice@example.org', 'Temp-Alice-1', false, true);
$bob     = Users::create('1002', 'Bob B', null, 'Temp-Bob-0001', false, false);
$dave    = Users::create('1003', 'Dave D', null, 'Dave-pass-123x', false, false);
[$cpk, $secret] = Clients::create('fundflow', 'FundFlow', ['http://127.0.0.1:9001/auth/callback'], ['http://127.0.0.1:9001/logged-out'], 'http://127.0.0.1:9001/');
[$cpk2, $secret2] = Clients::create('mpcs', 'MPCS', ['http://127.0.0.1:9002/cb'], [], null);
Users::setApps($alice, [$cpk]); Users::setApps($dave, [$cpk]); Users::setApps($bob, [$cpk2]); Users::setApps($adminId, [$cpk, $cpk2]);

$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../public', __DIR__ . '/../public/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, getenv());
register_shutdown_function(static function () use ($proc) { proc_terminate($proc); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? "  ok   " : "  FAIL ") . $name . (!$ok && $extra ? "  [$extra]" : '') . "\n"; }

final class Browser {
    public string $jar;
    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    /** @return array{0:int,1:string,2:string} status, body, Location */
    public function req(string $method, string $url, array $post = [], array $opts = []): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method] + $opts);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string)curl_exec($ch);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $h = substr($raw, 0, $hs); $loc = preg_match('/^Location:\s*(.+?)\r?$/mi', $h, $m) ? trim($m[1]) : '';
        return [$code, substr($raw, $hs), $loc];
    }
    public function csrf(string $page): string { [, $b] = $this->req('GET', $page); preg_match('/name="csrf" value="([0-9a-f]+)"/', $b, $m); return $m[1] ?? ''; }
    public function login(string $id, string $pw): array { global $base; return $this->req('POST', "$base/login", ['csrf' => $this->csrf("$base/login"), 'login_id' => $id, 'password' => $pw]); }
}
function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function authUrl(string $cid, string $redir, string $verifier, string $state = 'st123'): string {
    global $base;
    return "$base/authorize?" . http_build_query(['response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => $redir, 'state' => $state,
        'code_challenge' => b64u(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256']);
}
function token(string $cid, string $sec, string $code, string $redir, string $verifier): array {
    global $base; $b = new Browser();
    [$st, $body] = $b->req('POST', "$base/token", ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redir, 'code_verifier' => $verifier, 'client_id' => $cid, 'client_secret' => $sec]);
    return [$st, json_decode($body, true) ?? []];
}
function codeFrom(string $loc): string { parse_str((string)parse_url($loc, PHP_URL_QUERY), $q); return $q['code'] ?? ''; }

$R = 'http://127.0.0.1:9001/auth/callback'; $V = b64u(random_bytes(32));

echo "authorize validation\n";
$b = new Browser();
[$st,, $loc] = $b->req('GET', authUrl('fundflow', 'https://evil.example/cb', $V));
check('unregistered redirect_uri -> error page, no redirect', $st === 400 && $loc === '');
[$st,, $loc] = $b->req('GET', authUrl('nope', $R, $V));
check('unknown client -> error page, no redirect', $st === 400 && $loc === '');
[$st] = $b->req('GET', "$base/authorize?response_type=code&client_id=fundflow&redirect_uri=" . urlencode($R) . "&state=x");
check('missing PKCE -> 400', $st === 400);
[$st,, $loc] = $b->req('GET', authUrl('fundflow', $R, $V));
check('unauthenticated -> /login', $st === 302 && $loc === '/login');

echo "login\n";
[$st, $bodyBad] = $b->req('POST', "$base/login", ['login_id' => '1001', 'password' => 'x']);
check('POST without CSRF rejected', $st === 400);
[$st, $b1] = $b->login('1001', 'wrong-password');
[, $b2] = (new Browser())->login('no-such-user', 'wrong-password');
$msg = fn(string $h) => preg_match('/role="alert">(.*?)<\/p>/s', $h, $m) ? $m[1] : '';
check('wrong password -> generic error', $st === 200 && str_contains($b1, "Username or password isn&#039;t correct."));
check('unknown user gives identical message', $msg($b1) === $msg($b2) && $msg($b1) !== '');
[$st,, $loc] = $b->login('1001', 'Temp-Alice-1');
check('correct temp password -> /password (resume kept)', $st === 302 && $loc === '/password');
[$st,, $loc] = $b->req('GET', authUrl('fundflow', $R, $V));
check('forced password change blocks /authorize', $st === 302 && $loc === '/password');
$tok = $b->csrf("$base/password");
[$st, $body] = $b->req('POST', "$base/password", ['csrf' => $tok, 'current' => 'Temp-Alice-1', 'new' => 'short', 'confirm' => 'short']);
check('weak new password rejected', $st === 200 && str_contains($body, 'at least 10'));
[$st, $body] = $b->req('POST', "$base/password", ['csrf' => $tok, 'current' => 'Temp-Alice-1', 'new' => 'password123', 'confirm' => 'password123']);
check('common password rejected', $st === 200 && str_contains($body, 'too easy'));
[$st,, $loc] = $b->req('POST', "$base/password", ['csrf' => $tok, 'current' => 'Temp-Alice-1', 'new' => 'Alice-new-pass-77', 'confirm' => 'Alice-new-pass-77']);
check('password changed -> resumes /authorize request', $st === 302 && str_starts_with($loc, '/authorize?'), $loc);

echo "authorization code + token\n";
[$st,, $loc] = $b->req('GET', authUrl('fundflow', $R, $V));
check('authorize -> redirect to app with code+state', $st === 302 && str_starts_with($loc, $R . '?') && str_contains($loc, 'state=st123') && codeFrom($loc) !== '', $loc);
$code = codeFrom($loc);
[$st] = token('fundflow', 'wrong-secret', $code, $R, $V);
check('bad client secret -> 401', $st === 401);
[$st] = token('mpcs', $secret2, $code, $R, $V);
check('code is bound to client (other client rejected)', $st === 400);
[$st] = token('fundflow', $secret, $code, 'http://127.0.0.1:9001/other', $V);
check('redirect_uri mismatch -> 400', $st === 400);
[$st,, ] = [0];
[$st, $j] = token('fundflow', $secret, $code, $R, $V);
check('code already consumed by failed attempt (single use)', $st === 400 && ($j['error'] ?? '') === 'invalid_grant');
[, , $loc] = $b->req('GET', authUrl('fundflow', $R, $V));
[$st, $j] = token('fundflow', $secret, codeFrom($loc), $R, b64u(random_bytes(32)));
check('wrong PKCE verifier -> 400', $st === 400);
[, , $loc] = $b->req('GET', authUrl('fundflow', $R, $V));
$code = codeFrom($loc);
[$st, $j] = token('fundflow', $secret, $code, $R, $V);
check('valid exchange -> identity', $st === 200 && ($j['sub'] ?? '') === (string)$alice && ($j['login_id'] ?? '') === '1001' && ($j['name'] ?? '') === 'Alice A', json_encode($j));
check('response has no password/hash', !str_contains(json_encode($j), 'pass'));
[$st] = token('fundflow', $secret, $code, $R, $V);
check('replay of used code -> 400', $st === 400);
$pdo->exec("UPDATE auth_codes SET expires_at = '2000-01-01 00:00:00'");
[, , $loc] = $b->req('GET', authUrl('fundflow', $R, $V));
$c3 = codeFrom($loc); $pdo->exec("UPDATE auth_codes SET expires_at = '2000-01-01 00:00:00' WHERE used_at IS NULL");
[$st] = token('fundflow', $secret, $c3, $R, $V);
check('expired code -> 400', $st === 400);
[$st] = (new Browser())->req('GET', "$base/token");
check('GET /token not allowed', $st === 405);

echo "single sign-on and access control\n";
[$st,, $loc] = $b->req('GET', authUrl('mpcs', 'http://127.0.0.1:9002/cb', $V));
check('user without access to app is refused (403)', $st === 403 && $loc === '');
$b2 = new Browser(); $b2->login('1002', 'Temp-Bob-0001'); $t = $b2->csrf("$base/password");
$b2->req('POST', "$base/password", ['csrf' => $t, 'current' => 'Temp-Bob-0001', 'new' => 'Bob-new-pass-55', 'confirm' => 'Bob-new-pass-55']);
$b2 = new Browser(); [$st,, $loc] = $b2->login('1002', 'Bob-new-pass-55');
check('bob (no force change) signs in', $st === 302);
[$st,, $loc] = $b2->req('GET', authUrl('mpcs', 'http://127.0.0.1:9002/cb', $V));
check('same account, second app: SSO without second prompt', $st === 302 && str_starts_with($loc, 'http://127.0.0.1:9002/cb?code='), $loc);
[, $page] = $b->req('GET', "$base/apps");
check('app list shows only permitted apps', str_contains($page, 'FundFlow') && !str_contains($page, '>MPCS<'));
[$st] = $b->req('GET', "$base/admin/users");
check('non-admin gets 403 on /admin', $st === 403);

echo "throttling\n";
$t = new Browser();
for ($i = 0; $i < 5; $i++) { $t->login('1003', 'bad-guess-' . $i); }
[, $body] = $t->login('1003', 'Dave-pass-123x');
check('5 failures from one IP lock that account+IP (even correct password)', str_contains($body, 'Too many sign-in attempts'));
$pdo->exec('DELETE FROM login_attempts');

echo "disable takes effect immediately\n";
$d = new Browser(); [$st,, $loc] = $d->login('1003', 'Dave-pass-123x');
[$st] = $d->req('GET', "$base/apps"); check('dave signed in', $st === 302 || $st === 200);
$pdo->exec("UPDATE users SET must_change_password = 0 WHERE id = $dave");
[$st] = $d->req('GET', "$base/apps"); check('dave sees apps', $st === 200);
$pdo->exec("UPDATE users SET status = 'disabled' WHERE id = $dave");
[$st,, $loc] = $d->req('GET', "$base/apps");
check('disabled user kicked out on next request', $st === 302 && $loc === '/login');
[, $body] = (new Browser())->login('1003', 'Dave-pass-123x');
check('disabled user cannot sign in (generic error)', str_contains($body, "Username or password isn&#039;t correct."));

echo "admin\n";
$a = new Browser(); $a->login('admin', 'Admin-pass-9x');
[$st, $body] = $a->req('GET', "$base/admin/users"); check('admin sees users', $st === 200 && str_contains($body, 'Alice A'));
[$st] = $a->req('POST', "$base/admin/users/$alice/reset"); check('admin POST without CSRF rejected', $st === 400);
[$st, $body] = $a->req('POST', "$base/admin/users/$alice/reset", ['csrf' => $a->csrf("$base/admin/users")]);
check('admin reset shows one-time temp password', $st === 200 && str_contains($body, 'Temporary password'));
[$st, $body] = $a->req('POST', "$base/admin/users/new", ['csrf' => $a->csrf("$base/admin/users/new"), 'login_id' => '1001', 'full_name' => 'Dup']);
check('duplicate login id rejected', str_contains($body, 'already in use'));
[$st, $body] = $a->req('POST', "$base/admin/users/$adminId", ['csrf' => $a->csrf("$base/admin/users/$adminId"), 'login_id' => 'admin', 'full_name' => 'Admin', 'status' => 'disabled', 'is_admin' => 'on']);
check('admin cannot disable self', str_contains($body, 'cannot disable'));
[$st, $body] = $a->req('POST', "$base/admin/clients/new", ['csrf' => $a->csrf("$base/admin/clients/new"), 'client_id' => 'bad', 'name' => 'X', 'redirect_uris' => 'javascript:alert(1)']);
check('javascript: redirect URI rejected', str_contains($body, 'Invalid URL'));
check('client secret stored hashed only', (string)Db::one('SELECT secret_hash FROM clients WHERE client_id = ?', ['fundflow'])['secret_hash'] !== $secret);

echo "logout\n";
[$st,, $loc] = $b->req('GET', "$base/logout?client_id=fundflow&return_to=" . urlencode('http://127.0.0.1:9001/logged-out'));
check('logout returns to registered URI', $st === 302 && $loc === 'http://127.0.0.1:9001/logged-out', $loc);
[$st,, $loc] = $b->req('GET', "$base/apps");
check('session ended', $loc === '/login');
[$st,, $loc] = $b->req('GET', "$base/logout?client_id=fundflow&return_to=" . urlencode('https://evil.example/'));
check('logout to unregistered URI falls back to the front door', $loc === '/', $loc);
[$st,, $loc] = $b->req('GET', "$base/logout?client_id=fundflow&return_to=" . urlencode("$base/"));
check('front door URL is an accepted logout target for any app', $loc === "$base/", $loc);
[$st,, $loc] = $b->req('GET', "$base/logout?client_id=fundflow&return_to=" . urlencode("$base/evil"));
check('other lookalike URLs are not accepted', $loc === '/', $loc);

echo "front door\n";
[$st, $body] = (new Browser())->req('GET', "$base/");
check('anonymous visitor sees the sign-in form at /', $st === 200 && str_contains($body, 'name="login_id"') && str_contains($body, 'logo.png'));
$a2 = new Browser(); $a2->login('1003x', 'x');
$d2 = new Browser(); $pdo->exec("UPDATE users SET status='active', must_change_password=0 WHERE id=$dave"); $d2->login('1003', 'Dave-pass-123x');
[$st, $body] = $d2->req('GET', "$base/");
check('signed-in user sees their application tiles at /', $st === 200 && str_contains($body, 'FundFlow') && !str_contains($body, 'name="login_id"'));
[$st, $body] = (new Browser())->req('GET', "$base/fundflow_ssb/config/database.php");
check('unknown paths (e.g. sibling folders) answer 404', $st === 404);

echo "headers\n";
[$st] = (new Browser())->req('GET', "$base/login", [], [CURLOPT_HEADER => true]);
$ch = curl_init("$base/login"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]); $raw = (string)curl_exec($ch);
check('security headers present', str_contains($raw, 'X-Frame-Options: DENY') && str_contains($raw, 'Content-Security-Policy') && str_contains($raw, 'no-store'));
check('session cookie HttpOnly + SameSite', (bool)preg_match('/Set-Cookie: ssb_idp=.*HttpOnly.*SameSite=Lax/i', $raw));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

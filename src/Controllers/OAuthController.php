<?php
declare(strict_types=1);

namespace Auth\Controllers;

use Auth\{Audit, Clients, Config, Db, Http, Session, Users};

/**
 * Authorization-code flow with mandatory PKCE (S256) and a back-channel token exchange.
 *   GET  /authorize  client_id, redirect_uri, response_type=code, state, code_challenge, code_challenge_method=S256
 *   POST /token      grant_type=authorization_code, code, redirect_uri, code_verifier, client_id, client_secret
 */
final class OAuthController
{
    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function fail(string $msg): never
    {
        Http::render('error', ['title' => 'Cannot sign in', 'message' => $msg], 400);
    }

    public static function authorize(): never
    {
        $q = $_GET;
        $client = Clients::byClientId((string)($q['client_id'] ?? ''));
        $redirect = (string)($q['redirect_uri'] ?? '');
        // Until client + redirect_uri are proven valid we NEVER redirect (no open redirector).
        if ($client === null || !Clients::allows($client, 'redirect_uris', $redirect)) {
            self::fail('This application is not registered for sign-in. Contact the system administrator.');
        }
        $state = (string)($q['state'] ?? '');
        $challenge = (string)($q['code_challenge'] ?? '');
        if (($q['response_type'] ?? '') !== 'code' || ($q['code_challenge_method'] ?? '') !== 'S256'
            || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) || $state === '' || strlen($state) > 200) {
            self::fail('The sign-in request from the application is malformed.');
        }

        $user = Users::current();
        if (!$user) {
            Session::set('_resume', $_SERVER['REQUEST_URI'] ?? '/authorize');
            Http::redirect('/login');
        }
        if ((int)$user['must_change_password'] === 1) {
            Session::set('_resume', $_SERVER['REQUEST_URI'] ?? '/authorize');
            Http::redirect('/password');
        }
        if (!Users::hasApp((int)$user['id'], (int)$client['id'])) {
            Audit::log('app_denied', (int)$user['id'], $user['login_id'], $client['client_id']);
            Http::render('noaccess', ['title' => 'No access', 'app' => $client['name'], 'user' => $user], 403);
        }

        $code = self::b64url(random_bytes(32));
        Db::run(
            'INSERT INTO auth_codes (code_hash, client_pk, user_id, redirect_uri, code_challenge, auth_time, expires_at) VALUES (?,?,?,?,?,?,?)',
            [
                hash('sha256', $code), $client['id'], $user['id'], $redirect, $challenge,
                gmdate('Y-m-d H:i:s', (int)Session::get('auth_time', time())),
                gmdate('Y-m-d H:i:s', time() + max(10, (int)Config::get('code_ttl_seconds', 60))),
            ]
        );
        Audit::log('app_authorized', (int)$user['id'], $user['login_id'], $client['client_id']);
        $sep = str_contains($redirect, '?') ? '&' : '?';
        Http::redirect($redirect . $sep . http_build_query(['code' => $code, 'state' => $state]));
    }

    public static function token(): never
    {
        header('Cache-Control: no-store');
        if (Http::method() !== 'POST') {
            Http::json(['error' => 'invalid_request'], 405);
        }
        $cid = (string)($_POST['client_id'] ?? '');
        $secret = (string)($_POST['client_secret'] ?? '');
        if (isset($_SERVER['PHP_AUTH_USER'])) {                       // HTTP Basic also accepted
            $cid = (string)$_SERVER['PHP_AUTH_USER'];
            $secret = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
        }
        $client = Clients::byClientId($cid);
        if ($client === null || $secret === '' || !Clients::checkSecret($client, $secret)) {
            Audit::log('token_bad_client', null, null, $cid !== '' ? substr($cid, 0, 64) : null);
            Http::json(['error' => 'invalid_client'], 401);
        }
        if (($_POST['grant_type'] ?? '') !== 'authorization_code') {
            Http::json(['error' => 'unsupported_grant_type'], 400);
        }
        $code = (string)($_POST['code'] ?? '');
        $verifier = (string)($_POST['code_verifier'] ?? '');
        $redirect = (string)($_POST['redirect_uri'] ?? '');
        if ($code === '' || strlen($code) > 100 || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            Http::json(['error' => 'invalid_request'], 400);
        }

        // Atomically claim the code: only one caller can flip used_at from NULL.
        $claimed = Db::run(
            'UPDATE auth_codes SET used_at = ? WHERE code_hash = ? AND client_pk = ? AND used_at IS NULL AND expires_at >= ?',
            [Db::now(), hash('sha256', $code), $client['id'], Db::now()]
        );
        $row = Db::one('SELECT * FROM auth_codes WHERE code_hash = ? AND client_pk = ?', [hash('sha256', $code), $client['id']]);
        if ($claimed !== 1 || $row === null) {
            Audit::log('token_bad_code', null, null, $client['client_id'], $row && $row['used_at'] ? 'reuse' : 'unknown_or_expired');
            Http::json(['error' => 'invalid_grant'], 400);
        }
        $pkce = hash_equals($row['code_challenge'], self::b64url(hash('sha256', $verifier, true)));
        if (!$pkce || !hash_equals($row['redirect_uri'], $redirect)) {
            Audit::log('token_bad_code', (int)$row['user_id'], null, $client['client_id'], 'pkce_or_redirect_mismatch');
            Http::json(['error' => 'invalid_grant'], 400);
        }
        $u = Users::byId((int)$row['user_id']);
        if ($u === null || $u['status'] !== 'active' || !Users::hasApp((int)$u['id'], (int)$client['id'])) {
            Http::json(['error' => 'access_denied'], 403);
        }
        Audit::log('token_issued', (int)$u['id'], $u['login_id'], $client['client_id']);
        Http::json([
            'sub'       => (string)$u['id'],
            'login_id'  => $u['login_id'],
            'name'      => $u['full_name'],
            'email'     => $u['email'],
            'auth_time' => strtotime($row['auth_time'] . ' UTC'),
        ]);
    }
}

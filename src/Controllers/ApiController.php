<?php
declare(strict_types=1);

namespace Auth\Controllers;

use Auth\{Audit, Clients, Db, Http, Password, Throttle, Users};

/**
 * Machine-to-machine API for registered applications (server to server, JSON, no session, no CSRF).
 * Every call authenticates the CALLING APPLICATION with its client_id + client_secret and requires
 * clients.can_provision = 1. The application that is granted / affected is always the authenticated
 * client - never a value taken from the request body.
 */
final class ApiController
{
    /** POST /api/v1/users - create a user (new login ID) or link an existing one to the calling app. */
    public static function create(): never
    {
        $client = self::client();
        $in = self::body();
        $loginId = trim((string)($in['login_id'] ?? ''));
        $name = trim((string)($in['full_name'] ?? ''));
        $email = trim((string)($in['email'] ?? ''));
        $password = (string)($in['initial_password'] ?? '');

        $existing = $loginId !== '' ? Users::byLogin($loginId) : null;
        if ($existing !== null) {
            // Link only: grant this app. Name, email, password and other access are never touched.
            if ($existing['status'] !== 'active') {
                self::fail(403, 'user_disabled', 'That SSO account is disabled.');
            }
            Users::grantApp((int)$existing['id'], (int)$client['id']);
            Audit::log('app_linked', (int)$existing['id'], $existing['login_id'], $client['client_id']);
            Http::json(['created' => false, 'sub' => (string)$existing['id'], 'login_id' => $existing['login_id'], 'name' => $existing['full_name']]);
        }

        if ($password === '') {
            self::fail(422, 'initial_password_required', 'initial_password is required.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && Db::one('SELECT id FROM users WHERE email = ?', [$email])) {
            self::fail(409, 'email_in_use', 'That email belongs to a different SSO user.');
        }
        $err = Users::validate($loginId, $name, $email) ?? Password::policyError($password, $loginId);
        if ($err !== null) {
            self::fail(422, 'invalid', $err);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $id = Users::create($loginId, $name, $email, $password, false, false);
            Users::grantApp($id, (int)$client['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('ssb_auth api create: ' . $e);
            self::fail(409, 'conflict', 'Could not create the user (it may have just been created).');
        }
        Audit::log('user_provisioned', $id, $loginId, $client['client_id']);
        Http::json(['created' => true, 'sub' => (string)$id, 'login_id' => $loginId, 'name' => $name], 201);
    }

    /** POST /api/v1/users/password - a user changes their own password (current password required). */
    public static function changePassword(): never
    {
        $client = self::client();
        $in = self::body();
        $loginId = trim((string)($in['login_id'] ?? ''));
        $cur = (string)($in['current_password'] ?? '');
        $new = (string)($in['new_password'] ?? '');
        $key = 'client-' . (int)$client['id'];            // all calls come from the app server, so throttle per app + account

        if ($loginId === '' || $cur === '' || $new === '') {
            self::fail(422, 'invalid', 'login_id, current_password and new_password are required.');
        }
        if (Throttle::blocked($loginId, $key)) {
            self::fail(429, 'too_many_attempts', 'Too many attempts. Try again in 15 minutes.');
        }
        $u = Users::byLogin($loginId);
        $ok = $u !== null && $u['status'] === 'active' && Users::hasApp((int)$u['id'], (int)$client['id']);
        // Always verify something so timing does not reveal whether the account exists.
        $match = Password::verify($cur, $ok ? (string)$u['password_hash'] : Password::dummy());
        if (!$ok || !$match) {
            Throttle::fail($loginId, $key);
            Audit::log('password_change_failed', $u ? (int)$u['id'] : null, $loginId, $client['client_id'], 'api');
            self::fail(403, 'invalid_credentials', 'Current password is incorrect.');
        }
        if ($new === $cur) {
            self::fail(422, 'same_password', 'Choose a password different from the current one.');
        }
        if (($e = Password::policyError($new, $u['login_id'])) !== null) {
            self::fail(422, 'weak_password', $e);
        }
        Users::setPassword((int)$u['id'], $new, false);
        Throttle::clear($loginId, $key);
        Audit::log('password_changed', (int)$u['id'], $u['login_id'], $client['client_id'], 'api');
        Http::json(['ok' => true]);
    }

    /**
     * POST /api/v1/users/password-reset - an application admin resets a user's password.
     * Allowed only when the calling app is that user's ONLY app, so one system's admin cannot take over an
     * account that also reaches other systems (the SSO admin resets those in /admin/users).
     */
    public static function resetPassword(): never
    {
        $client = self::client();
        $in = self::body();
        $loginId = trim((string)($in['login_id'] ?? ''));
        $new = (string)($in['new_password'] ?? '');
        if ($loginId === '' || $new === '') {
            self::fail(422, 'invalid', 'login_id and new_password are required.');
        }
        $u = Users::byLogin($loginId);
        if ($u === null || $u['status'] !== 'active' || !Users::hasApp((int)$u['id'], (int)$client['id']) || Users::appCount((int)$u['id']) !== 1) {
            Audit::log('password_reset_denied', $u ? (int)$u['id'] : null, $loginId, $client['client_id'], 'api');
            self::fail(403, 'not_allowed', 'This account cannot be reset by this application. Ask the SSO administrator.');
        }
        if (($e = Password::policyError($new, $u['login_id'])) !== null) {
            self::fail(422, 'weak_password', $e);
        }
        Users::setPassword((int)$u['id'], $new, false);
        Audit::log('password_reset', (int)$u['id'], $u['login_id'], $client['client_id'], 'api');
        Http::json(['ok' => true]);
    }

    // ------------------------------------------------------------------ helpers

    /** Authenticates the calling application (HTTP Basic or JSON body) and requires can_provision. */
    private static function client(): array
    {
        header('Cache-Control: no-store');
        if (Http::method() !== 'POST') {
            self::fail(405, 'method_not_allowed', 'POST only.');
        }
        $in = self::body();
        $cid = (string)($in['client_id'] ?? '');
        $secret = (string)($in['client_secret'] ?? '');
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $cid = (string)$_SERVER['PHP_AUTH_USER'];
            $secret = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
        }
        $throttleKey = substr('client:' . $cid, 0, 64);
        if (Throttle::blocked($throttleKey, Http::ip())) {
            self::fail(429, 'too_many_attempts', 'Too many failed attempts.');
        }
        $client = Clients::byClientId($cid);
        if ($client === null || $secret === '' || !Clients::checkSecret($client, $secret)) {
            Throttle::fail($throttleKey, Http::ip());
            Audit::log('api_bad_client', null, null, $cid !== '' ? substr($cid, 0, 64) : null);
            self::fail(401, 'invalid_client', 'Invalid client credentials.');
        }
        if ((int)($client['can_provision'] ?? 0) !== 1) {
            Audit::log('api_not_allowed', null, null, $client['client_id']);
            self::fail(403, 'provisioning_disabled', 'This application is not allowed to use the user API.');
        }
        return $client;
    }

    private static function body(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, 16384);
        $data = json_decode($raw, true);
        return $cache = is_array($data) ? $data : [];
    }

    private static function fail(int $status, string $code, string $message): never
    {
        Http::json(['error' => $code, 'message' => $message], $status);
    }
}

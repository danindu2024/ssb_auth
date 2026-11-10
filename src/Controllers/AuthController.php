<?php
declare(strict_types=1);

namespace Auth\Controllers;

use Auth\{Audit, Clients, Config, Db, Http, Password, Session, Throttle, Users};

final class AuthController
{
    private const GENERIC = "Username or password isn't correct. Check both and try again.";

    /** Where to go after sign-in: the pending /authorize request, else the app list. */
    private static function resume(): never
    {
        $to = Http::safePath(Session::get('_resume'), '/');
        Session::forget('_resume');
        Http::redirect($to);
    }

    /** The front door: the sign-in form when signed out, the person's applications when signed in. */
    public static function home(): never
    {
        Users::current() ? self::apps() : self::login();
    }

    public static function login(): never
    {
        if (Users::current()) {
            self::resume();
        }
        $error = null;
        $loginId = '';
        if (Http::method() === 'POST') {
            Session::checkCsrf();
            $loginId = trim((string)($_POST['login_id'] ?? ''));
            $pw = (string)($_POST['password'] ?? '');
            $ip = Http::ip();
            $error = self::GENERIC;

            if ($loginId === '' || strlen($loginId) > 64 || strlen($pw) > 1024) {
                // fall through with the generic error
            } elseif (Throttle::blocked($loginId, $ip)) {
                Audit::log('login_blocked', null, $loginId);
                $error = 'Too many sign-in attempts. Try again in 15 minutes.';
            } else {
                $u = Users::byLogin($loginId);
                $ok = Password::verify($pw, $u['password_hash'] ?? Password::dummy());   // constant work for unknown users
                if ($u !== null && $ok && $u['status'] === 'active') {
                    $resume = Session::get('_resume');
                    Throttle::clear($loginId, $ip);
                    Session::fresh();
                    Session::set('uid', (int)$u['id']);
                    Session::set('auth_time', time());
                    if ($resume !== null) {
                        Session::set('_resume', $resume);
                    }
                    if (Password::needsRehash((string)$u['password_hash'])) {
                        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [Password::hash($pw), $u['id']]);
                    }
                    Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', [Db::now(), $u['id']]);
                    Audit::log('login_success', (int)$u['id'], $u['login_id']);
                    if ((int)$u['must_change_password'] === 1) {
                        Http::redirect('/password');
                    }
                    self::resume();
                }
                Throttle::fail($loginId, $ip);
                Audit::log('login_failure', $u ? (int)$u['id'] : null, $loginId, null, $u && $u['status'] !== 'active' ? 'disabled' : null);
            }
        }
        Http::render('login', ['title' => 'Sign in', 'error' => $error, 'loginId' => $loginId]);
    }

    /** POST (from our own pages) or GET with a registered client + logout URI (RP-initiated logout). */
    public static function logout(): never
    {
        $user = Users::current();
        $home = rtrim((string)Config::get('base_url', ''), '/') . '/';      // the front door of this service
        $configured = (string)Config::get('portal_url', '');                 // optional extra landing URL
        $target = $configured !== '' ? $configured : '/';                    // after signing out, people land on the front door
        if (Http::method() === 'POST') {
            Session::checkCsrf();
        } else {
            $c = Clients::byClientId((string)($_GET['client_id'] ?? ''));
            $ret = (string)($_GET['return_to'] ?? '');
            $okReturn = $c !== null && $ret !== '' && ($ret === $home || $ret === $configured || Clients::allows($c, 'logout_uris', $ret));
            if ($c !== null && $okReturn) {
                $target = $ret;
            }
        }
        if ($user) {
            Audit::log('logout', (int)$user['id'], $user['login_id']);
        }
        Session::destroy();
        Http::redirect($target);
    }

    public static function password(): never
    {
        $u = Users::current();
        if (!$u) {
            Session::set('_resume', '/password');
            Http::redirect('/login');
        }
        $forced = (int)$u['must_change_password'] === 1;
        $error = null;
        if (Http::method() === 'POST') {
            Session::checkCsrf();
            $cur = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $conf = (string)($_POST['confirm'] ?? '');
            if (Throttle::blocked($u['login_id'], Http::ip())) {
                $error = 'Too many sign-in attempts. Try again in 15 minutes.';
            } elseif (!Password::verify($cur, (string)$u['password_hash'])) {
                Throttle::fail($u['login_id'], Http::ip());
                Audit::log('password_change_failed', (int)$u['id'], $u['login_id']);
                $error = 'Current password is incorrect.';
            } elseif ($new !== $conf) {
                $error = 'New passwords do not match.';
            } elseif ($new === $cur) {
                $error = 'Choose a password different from the current one.';
            } elseif (($e = Password::policyError($new, $u['login_id'])) !== null) {
                $error = $e;
            } else {
                Users::setPassword((int)$u['id'], $new, false);
                Audit::log('password_changed', (int)$u['id'], $u['login_id']);
                $resume = Session::get('_resume');
                $uid = (int)$u['id'];
                $at = (int)Session::get('auth_time', time());
                Session::fresh();                               // new session id after a credential change
                Session::set('uid', $uid);
                Session::set('auth_time', $at);
                Session::flash('Password updated.');
                if ($resume !== null) {
                    Session::set('_resume', $resume);
                }
                self::resume();
            }
        }
        Http::render('password', ['title' => 'Change password', 'error' => $error, 'forced' => $forced, 'user' => $u]);
    }

    /** Launcher listing the apps this user may open. */
    public static function apps(): never
    {
        $u = Users::current();
        if (!$u) {
            Session::set('_resume', '/apps');
            Http::redirect('/login');
        }
        if ((int)$u['must_change_password'] === 1) {
            Http::redirect('/password');
        }
        $apps = Db::all(
            "SELECT c.name, c.client_id, c.launch_url FROM clients c JOIN user_apps ua ON ua.client_pk = c.id
              WHERE ua.user_id = ? AND c.status = 'active' AND c.launch_url IS NOT NULL ORDER BY c.name",
            [$u['id']]
        );
        Http::render('apps', ['title' => 'Your applications', 'user' => $u, 'apps' => $apps]);
    }
}

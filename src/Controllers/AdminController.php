<?php
declare(strict_types=1);

namespace Auth\Controllers;

use Auth\{Audit, Clients, Db, Http, Password, Session, Users};

/** Administration of THIS service only (users, app registrations, audit). Not an app-role editor. */
final class AdminController
{
    private static function guard(): array
    {
        $u = Users::current();
        if (!$u) {
            Session::set('_resume', $_SERVER['REQUEST_URI'] ?? '/admin/users');
            Http::redirect('/login');
        }
        if ((int)$u['must_change_password'] === 1) {
            Http::redirect('/password');
        }
        if ((int)$u['is_admin'] !== 1) {
            Http::render('error', ['title' => 'Forbidden', 'message' => 'You do not have permission to open this page.'], 403);
        }
        return $u;
    }

    private static function post(): void
    {
        if (Http::method() !== 'POST') {
            Http::render('error', ['title' => 'Not allowed', 'message' => 'Use the form on the page.'], 405);
        }
        Session::checkCsrf();
    }

    public static function index(): never
    {
        self::guard();
        Http::redirect('/admin/users');
    }

    // ---------------------------------------------------------------- users
    public static function users(): never
    {
        $me = self::guard();
        $rows = Db::all(
            "SELECT u.*, (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') FROM user_apps ua
                           JOIN clients c ON c.id = ua.client_pk WHERE ua.user_id = u.id) AS apps
               FROM users u ORDER BY u.login_id"
        );
        Http::render('admin/users', ['title' => 'Users', 'me' => $me, 'rows' => $rows]);
    }

    public static function userNew(): never
    {
        $me = self::guard();
        $err = null;
        $f = ['login_id' => '', 'full_name' => '', 'email' => '', 'is_admin' => 0, 'apps' => []];
        if (Http::method() === 'POST') {
            Session::checkCsrf();
            $f = [
                'login_id' => trim((string)($_POST['login_id'] ?? '')),
                'full_name' => trim((string)($_POST['full_name'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'is_admin' => isset($_POST['is_admin']) ? 1 : 0,
                'apps' => (array)($_POST['apps'] ?? []),
            ];
            $err = Users::validate($f['login_id'], $f['full_name'], $f['email']);
            if ($err === null) {
                $temp = Password::generate();
                $id = Users::create($f['login_id'], $f['full_name'], $f['email'] ?: null, $temp, (bool)$f['is_admin'], true);
                Users::setApps($id, $f['apps']);
                Audit::log('user_created', $id, $f['login_id'], null, 'by ' . $me['login_id']);
                Http::render('admin/secret', [
                    'title' => 'User created', 'heading' => 'User created',
                    'lines' => ['Login ID' => $f['login_id'], 'Temporary password' => $temp],
                    'note' => 'Give these to the user through a private channel. The password is shown only once; the user must change it at first sign-in.',
                    'back' => '/admin/users',
                ]);
            }
        }
        Http::render('admin/user_form', ['title' => 'New user', 'me' => $me, 'f' => $f, 'err' => $err, 'isNew' => true,
            'clients' => Db::all('SELECT id, name FROM clients ORDER BY name'), 'uid' => 0]);
    }

    public static function userEdit(int $id): never
    {
        $me = self::guard();
        $u = Users::byId($id) ?? Http::render('error', ['title' => 'Not found', 'message' => 'No such user.'], 404);
        $err = null;
        $f = [
            'login_id' => $u['login_id'], 'full_name' => $u['full_name'], 'email' => (string)$u['email'],
            'is_admin' => (int)$u['is_admin'], 'status' => $u['status'],
            'apps' => array_column(Db::all('SELECT client_pk FROM user_apps WHERE user_id = ?', [$id]), 'client_pk'),
        ];
        if (Http::method() === 'POST') {
            Session::checkCsrf();
            $f['login_id'] = trim((string)($_POST['login_id'] ?? ''));
            $f['full_name'] = trim((string)($_POST['full_name'] ?? ''));
            $f['email'] = trim((string)($_POST['email'] ?? ''));
            $f['is_admin'] = isset($_POST['is_admin']) ? 1 : 0;
            $f['status'] = ($_POST['status'] ?? '') === 'disabled' ? 'disabled' : 'active';
            $f['apps'] = (array)($_POST['apps'] ?? []);
            $err = Users::validate($f['login_id'], $f['full_name'], $f['email'], $id);
            if ($err === null && $id === (int)$me['id'] && ($f['status'] !== 'active' || !$f['is_admin'])) {
                $err = 'You cannot disable or demote your own account.';
            }
            if ($err === null && $u['is_admin'] && $u['status'] === 'active' && ($f['status'] !== 'active' || !$f['is_admin'])
                && (int)Db::one("SELECT COUNT(*) AS n FROM users WHERE is_admin = 1 AND status = 'active' AND id <> ?", [$id])['n'] < 1) {
                $err = 'At least one active administrator must remain.';
            }
            if ($err === null) {
                Db::run('UPDATE users SET login_id=?, full_name=?, email=?, is_admin=?, status=?, updated_at=? WHERE id=?',
                    [$f['login_id'], $f['full_name'], $f['email'] ?: null, $f['is_admin'], $f['status'], Db::now(), $id]);
                Users::setApps($id, $f['apps']);
                Audit::log('user_updated', $id, $f['login_id'], null, 'by ' . $me['login_id'] . '; status=' . $f['status'] . '; admin=' . $f['is_admin']);
                Session::flash('User saved.');
                Http::redirect('/admin/users');
            }
        }
        Http::render('admin/user_form', ['title' => 'Edit user', 'me' => $me, 'f' => $f, 'err' => $err, 'isNew' => false,
            'clients' => Db::all('SELECT id, name FROM clients ORDER BY name'), 'uid' => $id]);
    }

    public static function userReset(int $id): never
    {
        $me = self::guard();
        self::post();
        $u = Users::byId($id) ?? Http::render('error', ['title' => 'Not found', 'message' => 'No such user.'], 404);
        $temp = Password::generate();
        Users::setPassword($id, $temp, true);
        Audit::log('password_reset', $id, $u['login_id'], null, 'by ' . $me['login_id']);
        Http::render('admin/secret', [
            'title' => 'Password reset', 'heading' => 'Password reset',
            'lines' => ['Login ID' => $u['login_id'], 'Temporary password' => $temp],
            'note' => 'Shown only once. The user must choose a new password at next sign-in.',
            'back' => '/admin/users',
        ]);
    }

    // -------------------------------------------------------------- clients
    public static function clients(): never
    {
        $me = self::guard();
        Http::render('admin/clients', ['title' => 'Applications', 'me' => $me, 'rows' => Db::all('SELECT * FROM clients ORDER BY name')]);
    }

    public static function clientNew(): never
    {
        $me = self::guard();
        $err = null;
        $f = ['client_id' => '', 'name' => '', 'redirect_uris' => '', 'logout_uris' => '', 'launch_url' => ''];
        if (Http::method() === 'POST') {
            Session::checkCsrf();
            foreach ($f as $k => $_) {
                $f[$k] = trim((string)($_POST[$k] ?? ''));
            }
            $err = Clients::validate($f['client_id'], $f['name'], Http::lines($f['redirect_uris']), Http::lines($f['logout_uris']), $f['launch_url']);
            if ($err === null && Db::one('SELECT 1 AS x FROM clients WHERE client_id = ?', [$f['client_id']])) {
                $err = 'That client ID already exists.';
            }
            if ($err === null) {
                [, $secret] = Clients::create($f['client_id'], $f['name'], Http::lines($f['redirect_uris']), Http::lines($f['logout_uris']), $f['launch_url']);
                Audit::log('client_created', null, null, $f['client_id'], 'by ' . $me['login_id']);
                Http::render('admin/secret', [
                    'title' => 'Application registered', 'heading' => 'Application registered',
                    'lines' => ['Client ID' => $f['client_id'], 'Client secret' => $secret],
                    'note' => 'Store the secret in the application\'s server-side config (outside the web root). It is shown only once; only its hash is kept here.',
                    'back' => '/admin/clients',
                ]);
            }
        }
        Http::render('admin/client_form', ['title' => 'Register application', 'me' => $me, 'f' => $f, 'err' => $err, 'isNew' => true, 'cid' => 0]);
    }

    public static function clientEdit(int $id): never
    {
        $me = self::guard();
        $c = Db::one('SELECT * FROM clients WHERE id = ?', [$id]) ?? Http::render('error', ['title' => 'Not found', 'message' => 'No such application.'], 404);
        $err = null;
        $f = ['client_id' => $c['client_id'], 'name' => $c['name'], 'redirect_uris' => $c['redirect_uris'],
              'logout_uris' => $c['logout_uris'], 'launch_url' => (string)$c['launch_url'], 'status' => $c['status'],
              'can_provision' => (int)($c['can_provision'] ?? 0)];
        if (Http::method() === 'POST') {
            Session::checkCsrf();
            foreach (['name', 'redirect_uris', 'logout_uris', 'launch_url'] as $k) {
                $f[$k] = trim((string)($_POST[$k] ?? ''));
            }
            $f['status'] = ($_POST['status'] ?? '') === 'disabled' ? 'disabled' : 'active';
            $f['can_provision'] = !empty($_POST['can_provision']) ? 1 : 0;
            $err = Clients::validate($c['client_id'], $f['name'], Http::lines($f['redirect_uris']), Http::lines($f['logout_uris']), $f['launch_url']);
            if ($err === null) {
                Db::run('UPDATE clients SET name=?, redirect_uris=?, logout_uris=?, launch_url=?, status=?, can_provision=? WHERE id=?',
                    [$f['name'], implode("\n", Http::lines($f['redirect_uris'])), implode("\n", Http::lines($f['logout_uris'])), $f['launch_url'] ?: null, $f['status'], $f['can_provision'], $id]);
                Audit::log('client_updated', null, null, $c['client_id'], 'by ' . $me['login_id'] . '; status=' . $f['status'] . '; can_provision=' . $f['can_provision']);
                Session::flash('Application saved.');
                Http::redirect('/admin/clients');
            }
        }
        Http::render('admin/client_form', ['title' => 'Edit application', 'me' => $me, 'f' => $f, 'err' => $err, 'isNew' => false, 'cid' => $id]);
    }

    public static function clientRotate(int $id): never
    {
        $me = self::guard();
        self::post();
        $c = Db::one('SELECT * FROM clients WHERE id = ?', [$id]) ?? Http::render('error', ['title' => 'Not found', 'message' => 'No such application.'], 404);
        $secret = Clients::rotateSecret($id);
        Audit::log('client_secret_rotated', null, null, $c['client_id'], 'by ' . $me['login_id']);
        Http::render('admin/secret', [
            'title' => 'New client secret', 'heading' => 'New client secret',
            'lines' => ['Client ID' => $c['client_id'], 'Client secret' => $secret],
            'note' => 'The old secret no longer works. Update the application config now.',
            'back' => '/admin/clients',
        ]);
    }

    // ---------------------------------------------------------------- audit
    public static function audit(): never
    {
        $me = self::guard();
        $q = trim((string)($_GET['login_id'] ?? ''));
        $rows = $q === ''
            ? Db::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 300')
            : Db::all('SELECT * FROM audit_log WHERE login_id = ? ORDER BY id DESC LIMIT 300', [$q]);
        Http::render('admin/audit', ['title' => 'Audit log', 'me' => $me, 'rows' => $rows, 'q' => $q]);
    }
}

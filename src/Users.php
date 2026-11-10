<?php
declare(strict_types=1);

namespace Auth;

final class Users
{
    public static function byLogin(string $loginId): ?array
    {
        return Db::one('SELECT * FROM users WHERE login_id = ?', [$loginId]);
    }

    public static function byId(int $id): ?array
    {
        return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** The signed-in, still-active user of the current session (re-read every request so disabling takes effect at once). */
    public static function current(): ?array
    {
        $id = Session::get('uid');
        if (!is_int($id)) {
            return null;
        }
        $u = self::byId($id);
        if ($u === null || $u['status'] !== 'active') {
            Session::destroy();
            return null;
        }
        return $u;
    }

    /** @return string|null error message */
    public static function validate(string $loginId, string $name, string $email, ?int $exceptId = null): ?string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._@-]{0,63}$/', $loginId)) {
            return 'Login ID may contain letters, digits, space and . _ @ - (max 64).';
        }
        if ($name === '' || mb_strlen($name) > 150) {
            return 'Full name is required (max 150 characters).';
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
            return 'Email address is not valid.';
        }
        $dup = Db::one('SELECT id FROM users WHERE login_id = ? AND id <> ?', [$loginId, $exceptId ?? 0]);
        if ($dup) {
            return 'That Login ID is already in use.';
        }
        if ($email !== '' && Db::one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $exceptId ?? 0])) {
            return 'That email is already in use.';
        }
        return null;
    }

    public static function create(string $loginId, string $name, ?string $email, string $password, bool $admin = false, bool $mustChange = true): int
    {
        $now = Db::now();
        return Db::insert(
            'INSERT INTO users (login_id, full_name, email, password_hash, must_change_password, is_admin, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$loginId, $name, $email ?: null, Password::hash($password), $mustChange ? 1 : 0, $admin ? 1 : 0, 'active', $now, $now]
        );
    }

    public static function setPassword(int $id, string $password, bool $mustChange): void
    {
        Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = ?, password_changed_at = ?, updated_at = ? WHERE id = ?',
            [Password::hash($password), $mustChange ? 1 : 0, Db::now(), Db::now(), $id]
        );
    }

    public static function setApps(int $userId, array $clientPks): void
    {
        Db::run('DELETE FROM user_apps WHERE user_id = ?', [$userId]);
        foreach (array_unique(array_map('intval', $clientPks)) as $pk) {
            Db::run('INSERT IGNORE INTO user_apps (user_id, client_pk) SELECT ?, id FROM clients WHERE id = ?', [$userId, $pk]);
        }
    }

    /** Additive: grants one app and leaves every other grant untouched (setApps() replaces the whole set). */
    public static function grantApp(int $userId, int $clientPk): void
    {
        Db::run('INSERT IGNORE INTO user_apps (user_id, client_pk) VALUES (?, ?)', [$userId, $clientPk]);
    }

    public static function appCount(int $userId): int
    {
        return (int)(Db::one('SELECT COUNT(*) AS n FROM user_apps WHERE user_id = ?', [$userId])['n'] ?? 0);
    }

    public static function hasApp(int $userId, int $clientPk): bool
    {
        return Db::one('SELECT 1 AS x FROM user_apps WHERE user_id = ? AND client_pk = ?', [$userId, $clientPk]) !== null;
    }
}

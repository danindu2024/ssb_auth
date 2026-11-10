<?php
declare(strict_types=1);

namespace Auth;

/**
 * Failed-login throttling over a 15-minute window. Three independent limits, so that one attacker
 * cannot lock a victim out of their own account from the victim's usual IP:
 *   - same account + same IP:   5
 *   - same account, any IP:    25  (distributed guessing)
 *   - same IP, any account:    40  (password spraying)
 * The caller must show the SAME generic message whether blocked or wrong password.
 */
final class Throttle
{
    private const WINDOW = 900;

    public static function blocked(string $loginId, string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW);
        $r = Db::one(
            'SELECT SUM(login_id = ? AND ip = ?) AS pair, SUM(login_id = ?) AS acct, SUM(ip = ?) AS ipc
               FROM login_attempts WHERE created_at >= ? AND (login_id = ? OR ip = ?)',
            [$loginId, $ip, $loginId, $ip, $since, $loginId, $ip]
        );
        return (int)($r['pair'] ?? 0) >= 5 || (int)($r['acct'] ?? 0) >= 25 || (int)($r['ipc'] ?? 0) >= 40;
    }

    public static function fail(string $loginId, string $ip): void
    {
        Db::run('INSERT INTO login_attempts (login_id, ip, created_at) VALUES (?,?,?)', [substr($loginId, 0, 64), $ip, Db::now()]);
        if (random_int(1, 50) === 1) {   // opportunistic cleanup
            Db::run('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
            Db::run('DELETE FROM auth_codes WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        }
    }

    public static function clear(string $loginId, string $ip): void
    {
        Db::run('DELETE FROM login_attempts WHERE login_id = ? AND ip = ?', [$loginId, $ip]);
    }
}

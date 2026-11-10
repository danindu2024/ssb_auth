<?php
declare(strict_types=1);

namespace Auth;

/** PHP session with idle + absolute timeouts, fixation protection and CSRF token. */
final class Session
{
    private static bool $started = false;

    private static function secure(): bool
    {
        $c = Config::get('session.cookie_secure', 'auto');
        return $c === 'auto' ? Http::isHttps() : (bool)$c;
    }

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        $name = (string)Config::get('session.name', 'ssb_idp');
        if (self::secure()) {
            $name = '__Host-' . $name;       // browsers then refuse a copy set by a sibling subdomain or over http
        }
        $idle = max(1, (int)Config::get('session.idle_minutes', 30)) * 60;
        $path = (string)Config::get('session.save_path', '');
        if ($path !== '') {
            if (!is_dir($path)) {
                @mkdir($path, 0770, true);
            }
            if (is_dir($path) && is_writable($path)) {
                session_save_path($path);
            }
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.gc_maxlifetime', (string)$idle);
        session_name($name);
        // Session cookie (no lifetime): dies with the browser; server-side timeouts below do the real work.
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => self::secure(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new \RuntimeException('Cannot start session.');
        }
        self::$started = true;

        $now = time();
        $absolute = max(1, (int)Config::get('session.absolute_hours', 12)) * 3600;
        $last = $_SESSION['_t'] ?? null;
        $born = $_SESSION['_born'] ?? null;
        if ((is_int($last) && $now - $last > $idle) || (is_int($born) && $now - $born > $absolute)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_t'] = $now;
        $_SESSION['_born'] ??= $now;
    }

    public static function get(string $k, mixed $d = null): mixed
    {
        self::start();
        return $_SESSION[$k] ?? $d;
    }

    public static function set(string $k, mixed $v): void
    {
        self::start();
        $_SESSION[$k] = $v;
    }

    public static function forget(string $k): void
    {
        self::start();
        unset($_SESSION[$k]);
    }

    /** New session id and empty data - call right after a successful login. */
    public static function fresh(): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION = ['_t' => time(), '_born' => time()];
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (!headers_sent()) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => '/', 'secure' => self::secure(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        @session_destroy();
        self::$started = false;
    }

    public static function csrf(): string
    {
        $t = self::get('_csrf');
        if (!is_string($t) || strlen($t) < 32) {
            $t = bin2hex(random_bytes(32));
            self::set('_csrf', $t);
        }
        return $t;
    }

    /** Verify the POST token; aborts with 400 on failure. */
    public static function checkCsrf(): void
    {
        $sent = $_POST['csrf'] ?? '';
        if (!is_string($sent) || !hash_equals(self::csrf(), $sent)) {
            Http::render('error', ['title' => 'Request rejected', 'message' => 'Your form expired. Go back, reload the page and try again.'], 400);
        }
    }

    public static function flash(string $msg, string $kind = 'ok'): void
    {
        self::start();
        $_SESSION['_flash'][] = [$kind, $msg];
    }

    /** @return array<int,array{0:string,1:string}> */
    public static function pullFlashes(): array
    {
        self::start();
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($f) ? $f : [];
    }
}

<?php
declare(strict_types=1);

namespace Auth;

final class Config
{
    private static array $c = [];

    public static function load(string $file): void
    {
        self::$c = is_file($file) ? (array)require $file : [];
        // Environment overrides (tests / CI); never required in production.
        foreach (['host' => 'AUTH_DB_HOST', 'port' => 'AUTH_DB_PORT', 'name' => 'AUTH_DB_NAME', 'user' => 'AUTH_DB_USER', 'pass' => 'AUTH_DB_PASS'] as $k => $env) {
            $v = getenv($env);
            if ($v !== false) {
                self::$c['db'][$k] = $v;
            }
        }
        if (($v = getenv('AUTH_BASE_URL')) !== false) {
            self::$c['base_url'] = $v;
        }
        if (($v = getenv('AUTH_PORTAL_URL')) !== false) {
            self::$c['portal_url'] = $v;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$c;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }
}

<?php
declare(strict_types=1);

namespace Auth;

final class Http
{
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return (bool)Config::get('trust_proxy', false)
            && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function ip(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (Config::get('trust_proxy', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
        return substr($ip, 0, 45);
    }

    public static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Same-site relative path only (prevents open redirects through the resume target). */
    public static function safePath(?string $p, string $fallback = '/apps'): string
    {
        if ($p === null || $p === '' || $p[0] !== '/' || str_starts_with($p, '//') || str_contains($p, '\\') || preg_match('/[\x00-\x1f]/', $p)) {
            return $fallback;
        }
        return $p;
    }

    /** A redirect/launch URI: https (http only for localhost), no fragment, no credentials. */
    public static function validUri(string $u): bool
    {
        $p = parse_url($u);
        if ($p === false || empty($p['scheme']) || empty($p['host']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        if (strlen($u) > 500 || preg_match('/[\x00-\x20]/', $u)) {
            return false;
        }
        $local = in_array($p['host'], ['localhost', '127.0.0.1'], true);
        return $p['scheme'] === 'https' || ($p['scheme'] === 'http' && $local);
    }

    /** @return string[] */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '') {
                $out[] = $l;
            }
        }
        return array_values(array_unique($out));
    }

    public static function render(string $view, array $vars = [], int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        extract($vars, EXTR_SKIP);
        $brand = !str_starts_with($view, 'admin/');          // public pages use the SSB branded look; admin pages stay plain
        ob_start();
        require AUTH_ROOT . '/views/' . $view . '.php';
        $content = (string)ob_get_clean();
        $title = $title ?? 'SSB Sign-in';
        require AUTH_ROOT . '/views/layout.php';
        exit;
    }
}

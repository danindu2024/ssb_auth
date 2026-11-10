<?php
declare(strict_types=1);

namespace Auth;

final class Clients
{
    public static function byClientId(string $cid): ?array
    {
        return Db::one("SELECT * FROM clients WHERE client_id = ? AND status = 'active'", [$cid]);
    }

    /** @return array{0:int,1:string} [pk, plaintext secret - shown once] */
    public static function create(string $clientId, string $name, array $redirects, array $logouts, ?string $launch): array
    {
        $secret = bin2hex(random_bytes(32));
        $pk = Db::insert(
            'INSERT INTO clients (client_id, name, secret_hash, redirect_uris, logout_uris, launch_url, status, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$clientId, $name, hash('sha256', $secret), implode("\n", $redirects), implode("\n", $logouts), $launch ?: null, 'active', Db::now()]
        );
        return [$pk, $secret];
    }

    public static function rotateSecret(int $pk): string
    {
        $secret = bin2hex(random_bytes(32));
        Db::run('UPDATE clients SET secret_hash = ? WHERE id = ?', [hash('sha256', $secret), $pk]);
        return $secret;
    }

    public static function checkSecret(array $client, string $secret): bool
    {
        return hash_equals((string)$client['secret_hash'], hash('sha256', $secret));
    }

    public static function allows(array $client, string $field, string $uri): bool
    {
        return in_array($uri, Http::lines((string)$client[$field]), true);   // exact string match
    }

    /** @return string|null error */
    public static function validate(string $clientId, string $name, array $redirects, array $logouts, string $launch): ?string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $clientId)) {
            return 'Client ID: 2-64 characters, lowercase letters, digits, - and _.';
        }
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Name is required.';
        }
        if ($redirects === []) {
            return 'At least one redirect URI is required.';
        }
        foreach (array_merge($redirects, $logouts, $launch !== '' ? [$launch] : []) as $u) {
            if (!Http::validUri($u)) {
                return 'Invalid URL (must be https, or http for localhost; no fragments): ' . $u;
            }
        }
        return null;
    }
}

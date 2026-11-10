<?php
declare(strict_types=1);

namespace Auth;

final class Audit
{
    public static function log(string $event, ?int $userId = null, ?string $loginId = null, ?string $clientId = null, ?string $detail = null): void
    {
        try {
            Db::run(
                'INSERT INTO audit_log (created_at, event, user_id, login_id, client_id, ip, user_agent, detail) VALUES (?,?,?,?,?,?,?,?)',
                [
                    Db::now(), $event, $userId, $loginId !== null ? substr($loginId, 0, 64) : null, $clientId,
                    Http::ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    $detail !== null ? substr($detail, 0, 500) : null,
                ]
            );
        } catch (\Throwable $e) {
            error_log('audit failed: ' . $e->getMessage());   // never break the request because of logging
        }
    }
}

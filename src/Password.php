<?php
declare(strict_types=1);

namespace Auth;

final class Password
{
    public const COST = 12;
    public const MIN = 10;
    public const MAX_BYTES = 72;      // bcrypt limit

    private const COMMON = [
        'password', 'password1', 'password123', 'passw0rd', 'qwertyuiop', 'qwerty123', '1234567890', '12345678910',
        'iloveyou12', 'welcome123', 'admin12345', 'letmein123', 'changeme123', 'abc1234567', 'mpcs@123456',
        'admin@123456', 'ssb@123456', 'fundflow123',
    ];

    private static ?string $dummy = null;

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }

    /** Hash to verify against for unknown users so response time does not reveal whether the account exists. */
    public static function dummy(): string
    {
        return self::$dummy ??= self::hash('dummy-' . bin2hex(random_bytes(8)));
    }

    /** Returns an error message, or null if the new password is acceptable. */
    public static function policyError(string $pw, string $loginId = ''): ?string
    {
        if (mb_strlen($pw) < self::MIN) {
            return 'Password must be at least ' . self::MIN . ' characters.';
        }
        if (strlen($pw) > self::MAX_BYTES) {
            return 'Password must be at most ' . self::MAX_BYTES . ' bytes.';
        }
        $l = mb_strtolower($pw);
        if (in_array($l, self::COMMON, true) || ($loginId !== '' && str_contains($l, mb_strtolower($loginId)))) {
            return 'That password is too easy to guess. Choose something less predictable.';
        }
        if (preg_match('/^(.)\1+$/u', $pw) || count(array_unique(mb_str_split($pw))) < 5) {
            return 'Use a greater variety of characters.';
        }
        return null;
    }

    /** Random temporary password (no look-alike characters). */
    public static function generate(int $len = 14): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}

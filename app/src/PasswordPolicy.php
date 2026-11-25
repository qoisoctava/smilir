<?php
declare(strict_types=1);

/**
 * Password rules for the console.
 *
 * There is no shipped default account, but `install.php --admin=admin:admin`
 * is accepted for convenience: weak passwords are flagged and the account is
 * forced through the change-password screen on first sign-in.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** Passwords that must never survive past the first login. */
    private const COMMON = [
        'admin', 'administrator', 'password', 'passw0rd', 'pipnix', 'smilir',
        '123456', '1234567', '12345678', '123456789', 'qwerty',
        'letmein', 'welcome', 'changeme', 'admin123', 'root',
    ];

    /** Weak = same as the username, a common default, or below MIN_LENGTH. */
    public static function isWeak(string $username, string $password): bool
    {
        $pass = strtolower(trim($password));
        if ($pass === '') {
            return true;
        }
        if (strlen($password) < self::MIN_LENGTH) {
            return true;
        }
        if ($pass === strtolower(trim($username))) {
            return true;
        }
        return in_array($pass, self::COMMON, true);
    }

    /** @return string|null human-readable reason, null when acceptable */
    public static function problem(string $username, string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strtolower($password) === strtolower(trim($username))) {
            return 'Password must differ from the username.';
        }
        if (in_array(strtolower($password), self::COMMON, true)) {
            return 'That password is too common — choose another one.';
        }
        return null;
    }
}

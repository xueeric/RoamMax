<?php

declare(strict_types=1);

namespace Starlink\Auth;

final class PasswordValidator
{
    public const MIN_LENGTH = 10;

    /** Human-readable requirements shown on forms. */
    public const REQUIREMENTS = 'At least 10 characters with uppercase and lowercase letters.';

    public static function validate(string $password): ?string
    {
        if ($password === '') {
            return 'Password is required.';
        }

        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must include a lowercase letter.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must include an uppercase letter.';
        }

        return null;
    }

    public static function isValid(string $password): bool
    {
        return self::validate($password) === null;
    }
}

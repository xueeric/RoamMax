<?php

declare(strict_types=1);

namespace Starlink\Auth;

final class EmailValidator
{
    public static function validate(string $email): ?string
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return 'Enter a valid email address.';
        }

        if (strlen($email) > 254) {
            return 'Email address is too long.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }

        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return 'Enter a valid email address.';
        }

        [$local, $domain] = $parts;

        if ($local === '' || $domain === '' || !str_contains($domain, '.')) {
            return 'Enter a valid email address.';
        }

        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return 'Enter a valid email address.';
        }

        if (!self::containsLetter($local)) {
            return 'Enter a real email address (not numbers only).';
        }

        $labels = explode('.', $domain);
        $tld = end($labels);
        if ($tld === false || !preg_match('/^[a-z]{2,63}$/i', $tld)) {
            return 'Enter a valid email address.';
        }

        $hostLabel = $labels[count($labels) - 2] ?? '';
        if ($hostLabel === '' || !self::containsLetter($hostLabel)) {
            return 'Enter a real email address with a valid domain.';
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return 'Enter a valid email address.';
            }
        }

        return null;
    }

    public static function isValid(string $email): bool
    {
        return self::validate($email) === null;
    }

    private static function containsLetter(string $value): bool
    {
        return (bool) preg_match('/[a-z]/i', $value);
    }
}

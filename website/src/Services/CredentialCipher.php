<?php

declare(strict_types=1);

namespace Starlink\Services;

final class CredentialCipher
{
    public static function encrypt(string $plainText): string
    {
        $key = self::key();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plainText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('Unable to encrypt credential.');
        }

        return base64_encode($iv . $cipher);
    }

    public static function decrypt(?string $encoded): ?string
    {
        if ($encoded === null || trim($encoded) === '') {
            return null;
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            return null;
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    public static function hasStored(?string $encoded): bool
    {
        return self::decrypt($encoded) !== null;
    }

    /** @return non-empty-string */
    private static function key(): string
    {
        return hash('sha256', (string) config('session_secret'), true);
    }
}

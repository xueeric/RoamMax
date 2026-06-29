<?php

declare(strict_types=1);

namespace Starlink\Auth;

use PDO;
use Starlink\Database\Connection;

final class RememberMeService
{
    private const COOKIE_NAME = 'starlink_remember';
    private const COOKIE_DAYS = 30;

    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    public function tryRestore(): void
    {
        if (Session::get('user_id') !== null) {
            return;
        }

        $token = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return;
        }

        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'SELECT id, role FROM users WHERE remember_token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $hash]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->clearCookie();
            return;
        }

        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('user_role', $user['role']);
        $this->remember((int) $user['id']);
    }

    public function remember(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $stmt = $this->db->prepare(
            'UPDATE users SET remember_token_hash = :hash WHERE id = :id'
        );
        $stmt->execute(['hash' => $hash, 'id' => $userId]);

        $this->setCookie($token);
    }

    public function forget(?int $userId = null): void
    {
        if ($userId !== null) {
            $stmt = $this->db->prepare(
                'UPDATE users SET remember_token_hash = NULL WHERE id = :id'
            );
            $stmt->execute(['id' => $userId]);
        }

        $this->clearCookie();
    }

    private function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + (self::COOKIE_DAYS * 86400),
            'path' => '/',
            'secure' => request_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(): void
    {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return;
        }

        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => request_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

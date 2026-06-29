<?php

declare(strict_types=1);

namespace Starlink\Auth;

use PDO;
use Starlink\Database\Connection;
use Starlink\Services\RateLimitService;

final class AuthService
{
    private readonly PDO $db;
    private readonly RememberMeService $rememberMe;

    public function __construct(?PDO $db = null, ?RememberMeService $rememberMe = null)
    {
        $this->db = $db ?? Connection::get();
        $this->rememberMe = $rememberMe ?? new RememberMeService($this->db);
    }

    public function user(): ?array
    {
        $userId = Session::get('user_id');
        if (!is_int($userId) && !is_string($userId)) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $userId]);

        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function role(): ?string
    {
        return $this->user()['role'] ?? null;
    }

    public function login(string $email, string $password, bool $remember = false): bool
    {
        $email = strtolower(trim($email));
        $ip = request_client_ip() ?? 'unknown';
        $rateLimit = new RateLimitService();

        if ($rateLimit->tooManyAttempts('login-ip:' . $ip, 30, 3600)) {
            Session::flash('error', 'Too many login attempts. Try again later.');
            return false;
        }

        $emailError = EmailValidator::validate($email);
        if ($emailError !== null) {
            Session::flash('error', $emailError);
            return false;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            Session::flash('error', 'Invalid email or password.');
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt((int) $user['id'], (int) $user['failed_login_count']);
            Session::flash('error', 'Invalid email or password.');
            return false;
        }

        $rateLimit = new RateLimitService();
        $rateLimit->clear('login-ip:' . (request_client_ip() ?? 'unknown'));

        $userId = (int) $user['id'];
        $this->clearFailedAttempts($userId);
        Session::regenerate();
        Session::set('user_id', $userId);
        Session::set('user_role', $user['role']);

        if ($remember) {
            $this->rememberMe->remember($userId);
        } else {
            $this->rememberMe->forget($userId);
        }

        return true;
    }

    public function registerCustomer(string $name, string $email, string $password, ?string $phone = null): array
    {
        $email = strtolower(trim($email));
        $name = trim($name);

        if ($name === '' || $email === '' || $password === '') {
            throw new AuthException('All fields are required.');
        }

        $emailError = EmailValidator::validate($email);
        if ($emailError !== null) {
            throw new AuthException($emailError);
        }

        $passwordError = PasswordValidator::validate($password);
        if ($passwordError !== null) {
            throw new AuthException($passwordError);
        }

        $exists = $this->db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $exists->execute(['email' => $email]);
        if ($exists->fetch()) {
            throw new AuthException('An account with that email already exists.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (email, password_hash, role, name, phone, created_at)
                 VALUES (:email, :password_hash, :role, :name, :phone, :created_at)'
            );
            $stmt->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'customer',
                'name' => $name,
                'phone' => $phone,
                'created_at' => now_utc(),
            ]);

            $userId = (int) $this->db->lastInsertId();

            $customer = $this->db->prepare('INSERT INTO customers (user_id) VALUES (:user_id)');
            $customer->execute(['user_id' => $userId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        Session::regenerate();
        Session::set('user_id', $userId);
        Session::set('user_role', 'customer');

        return $this->user() ?? [];
    }

    public function logout(): void
    {
        $userId = Session::get('user_id');
        if (is_int($userId) || is_string($userId)) {
            $this->rememberMe->forget((int) $userId);
        }

        Session::remove('user_id');
        Session::remove('user_role');
        Session::regenerate();
    }

    public function partnerId(): ?int
    {
        $user = $this->user();
        if (!$user || $user['role'] !== 'partner') {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id FROM partners WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => (int) $user['id']]);
        $partnerId = $stmt->fetchColumn();

        return $partnerId !== false ? (int) $partnerId : null;
    }

    private function recordFailedAttempt(int $userId, int $currentCount): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET failed_login_count = :count WHERE id = :id'
        );
        $stmt->execute([
            'count' => $currentCount + 1,
            'id' => $userId,
        ]);
    }

    private function clearFailedAttempts(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }
}

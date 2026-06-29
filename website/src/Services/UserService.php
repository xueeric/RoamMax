<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Auth\AuthException;
use Starlink\Auth\EmailValidator;
use Starlink\Auth\PasswordValidator;
use Starlink\Database\Connection;

final class UserService
{
    private readonly PDO $db;

    /** @var list<string> */
    private const CHARGE_TYPES = [
        'checkout',
        'rental',
        'late_fee',
        'cancellation_fee',
        'shipping',
        'addon',
        'damage_charge',
        'deposit',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function listUsers(?string $role = null, ?string $search = null): array
    {
        $sql = 'SELECT u.*,
                       p.id AS partner_record_id,
                       COUNT(DISTINCT b.id) AS booking_count,
                       COALESCE(SUM(
                           CASE
                               WHEN pay.status = \'completed\'
                               AND pay.type IN (\'checkout\',\'rental\',\'late_fee\',\'cancellation_fee\',\'shipping\',\'addon\',\'damage_charge\',\'deposit\')
                               THEN pay.amount_cents
                               ELSE 0
                           END
                       ), 0) AS total_paid_cents,
                       COALESCE(SUM(
                           CASE
                               WHEN pay.status = \'refunded\'
                               AND pay.type != \'deposit_refund\'
                               THEN pay.amount_cents
                               ELSE 0
                           END
                       ), 0) AS total_refunded_cents
                FROM users u
                LEFT JOIN customers c ON c.user_id = u.id
                LEFT JOIN partners p ON p.user_id = u.id
                LEFT JOIN bookings b ON b.customer_id = u.id
                LEFT JOIN payments pay ON pay.booking_id = b.id
                WHERE 1 = 1';

        $params = [];
        if ($role !== null && $role !== '') {
            $sql .= ' AND u.role = :role';
            $params['role'] = $role;
        }

        $searchTerm = trim((string) $search);
        if ($searchTerm !== '') {
            $like = '%' . $searchTerm . '%';
            $sql .= ' AND (
                u.name LIKE :search_name
                OR u.email LIKE :search_email
                OR COALESCE(u.phone, \'\') LIKE :search_phone
                OR COALESCE(c.company_name, \'\') LIKE :search_company
            )';
            $params['search_name'] = $like;
            $params['search_email'] = $like;
            $params['search_phone'] = $like;
            $params['search_company'] = $like;
        }

        $sql .= ' GROUP BY u.id ORDER BY u.created_at DESC, u.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(function (array $row): array {
            $row['booking_count'] = (int) $row['booking_count'];
            $row['total_paid_cents'] = (int) $row['total_paid_cents'];
            $row['total_refunded_cents'] = (int) $row['total_refunded_cents'];
            $row['net_spent_cents'] = $row['total_paid_cents'] - $row['total_refunded_cents'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** @return ?array<string, mixed> */
    public function findUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.*,
                    c.default_address_json,
                    c.company_name,
                    c.home_address_json,
                    c.shipping_address_json,
                    c.billing_address_json,
                    p.id AS partner_record_id
             FROM users u
             LEFT JOIN customers c ON c.user_id = u.id
             LEFT JOIN partners p ON p.user_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateCustomerEmail(int $userId, string $email): void
    {
        $email = strtolower(trim($email));

        $emailError = EmailValidator::validate($email);
        if ($emailError !== null) {
            throw new AuthException($emailError);
        }

        $profile = $this->findUser($userId);
        if ($profile === null) {
            throw new AuthException('User not found.');
        }

        if (($profile['role'] ?? '') !== 'customer') {
            throw new AuthException('Only customer emails can be updated here.');
        }

        $exists = $this->db->prepare(
            'SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1'
        );
        $exists->execute(['email' => $email, 'id' => $userId]);
        if ($exists->fetch()) {
            throw new AuthException('An account with that email already exists.');
        }

        $this->db->prepare('UPDATE users SET email = :email WHERE id = :id')
            ->execute(['email' => $email, 'id' => $userId]);
    }

    public function createUser(
        string $role,
        string $name,
        string $email,
        string $password,
        ?string $phone = null,
    ): int {
        if (!in_array($role, ['customer', 'admin', 'partner'], true)) {
            throw new AuthException('Invalid account role.');
        }

        $name = trim($name);
        $email = strtolower(trim($email));
        $phone = trim((string) $phone);

        if ($name === '' || $email === '' || $password === '') {
            throw new AuthException('Name, email, and password are required.');
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
            $this->db->prepare(
                'INSERT INTO users (email, password_hash, role, name, phone, created_at)
                 VALUES (:email, :password_hash, :role, :name, :phone, :created_at)'
            )->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'created_at' => now_utc(),
            ]);

            $userId = (int) $this->db->lastInsertId();

            if ($role === 'customer') {
                $this->db->prepare('INSERT INTO customers (user_id) VALUES (:user_id)')
                    ->execute(['user_id' => $userId]);
            }

            if ($role === 'partner') {
                $this->db->prepare(
                    'INSERT INTO partners (user_id, name, email, phone, created_at)
                     VALUES (:user_id, :name, :email, :phone, :created_at)'
                )->execute([
                    'user_id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone !== '' ? $phone : null,
                    'created_at' => now_utc(),
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $userId;
    }

    public function resetPassword(int $userId, string $password): void
    {
        $passwordError = PasswordValidator::validate($password);
        if ($passwordError !== null) {
            throw new AuthException($passwordError);
        }

        $profile = $this->findUser($userId);
        if ($profile === null) {
            throw new AuthException('User not found.');
        }

        $this->db->prepare(
            'UPDATE users
             SET password_hash = :password_hash, failed_login_count = 0, locked_until = NULL
             WHERE id = :id'
        )->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
    }

    /** @return array<string, int> */
    public function spendingSummary(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                COALESCE(SUM(
                    CASE
                        WHEN pay.status = \'completed\'
                        AND pay.type IN (\'checkout\',\'rental\',\'late_fee\',\'cancellation_fee\',\'shipping\',\'addon\',\'damage_charge\',\'deposit\')
                        THEN pay.amount_cents
                        ELSE 0
                    END
                ), 0) AS total_paid_cents,
                COALESCE(SUM(
                    CASE
                        WHEN pay.status = \'refunded\'
                        AND pay.type != \'deposit_refund\'
                        THEN pay.amount_cents
                        ELSE 0
                    END
                ), 0) AS total_refunded_cents,
                COUNT(DISTINCT b.id) AS booking_count
             FROM bookings b
             LEFT JOIN payments pay ON pay.booking_id = b.id
             WHERE b.customer_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch() ?: [];

        $paid = (int) ($row['total_paid_cents'] ?? 0);
        $refunded = (int) ($row['total_refunded_cents'] ?? 0);

        return [
            'booking_count' => (int) ($row['booking_count'] ?? 0),
            'total_paid_cents' => $paid,
            'total_refunded_cents' => $refunded,
            'net_spent_cents' => $paid - $refunded,
            'deposit_hold_cents' => $this->depositHoldCents($userId),
        ];
    }

    public function depositHoldCents(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(deposit_cents), 0)
             FROM bookings
             WHERE customer_id = :user_id
             AND payment_status = 'payment_deposit_scheduled_processed'
             AND booking_status IN ('booking_confirmed', 'booking_active', 'booking_late')"
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function userBookings(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, l.name AS location_name, e.nickname AS equipment_name
             FROM bookings b
             INNER JOIN locations l ON l.id = b.location_id
             LEFT JOIN equipment e ON e.id = b.equipment_id
             WHERE b.customer_id = :user_id
             ORDER BY b.created_at DESC, b.id DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function userPayments(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pay.*, b.id AS booking_ref, b.start_date, b.end_date, e.nickname AS equipment_name
             FROM payments pay
             INNER JOIN bookings b ON b.id = pay.booking_id
             LEFT JOIN equipment e ON e.id = pay.equipment_id
             WHERE b.customer_id = :user_id
             ORDER BY pay.created_at DESC, pay.id DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function deleteUser(int $userId, int $actingAdminId): void
    {
        if ($userId <= 0) {
            throw new AuthException('User not found.');
        }

        if ($userId === $actingAdminId) {
            throw new AuthException('You cannot delete your own account.');
        }

        $profile = $this->findUser($userId);
        if ($profile === null) {
            throw new AuthException('User not found.');
        }

        $bookingCount = $this->countUserBookings($userId);
        if ($bookingCount > 0) {
            throw new AuthException('This account has bookings and cannot be deleted.');
        }

        $partnerEquipmentCount = $this->countPartnerEquipment($userId);
        if ($partnerEquipmentCount > 0) {
            throw new AuthException('This partner still has equipment assigned. Reassign or remove units first.');
        }

        $this->db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }

    private function countUserBookings(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM bookings WHERE customer_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function countPartnerEquipment(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM equipment e
             INNER JOIN partners p ON p.id = e.partner_id
             WHERE p.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}

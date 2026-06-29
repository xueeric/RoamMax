<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class LongTermRequestService
{
    private readonly PDO $db;

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    /** @return array<string, mixed> */
    public function create(
        int $customerUserId,
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
        ?string $customerNotes = null,
    ): array {
        $fulfillmentType = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType($fulfillmentType);

        $start = parse_date($startDate);
        $end = parse_date($endDate);
        if ($start === null || $end === null || $start > $end) {
            throw new BookingUnavailableException('Invalid rental dates.');
        }

        $days = inclusive_day_count($start, $end);
        $maxSelfServe = (int) pricing_config('max_self_serve_days', (int) config('max_self_serve_days', 30));
        if ($days <= $maxSelfServe) {
            throw new BookingUnavailableException('This rental length can be booked online — use checkout instead.');
        }

        if (!in_array($fulfillmentType, \Starlink\Booking\BookingStatuses::FULFILLMENT_TYPES, true)) {
            throw new BookingUnavailableException('Invalid fulfillment method.');
        }

        $location = $this->db->prepare('SELECT id, name FROM locations WHERE id = :id AND is_active = 1 LIMIT 1');
        $location->execute(['id' => $locationId]);
        if (!$location->fetch()) {
            throw new BookingUnavailableException('Location not available.');
        }

        $existing = $this->findPendingForCustomer($customerUserId, $locationId, $startDate, $endDate);
        if ($existing !== null) {
            return $existing;
        }

        $now = now_utc();
        $stmt = $this->db->prepare(
            'INSERT INTO long_term_requests (
                customer_id, location_id, fulfillment_type, start_date, end_date, day_count,
                customer_notes, status, created_at, updated_at
             ) VALUES (
                :customer_id, :location_id, :fulfillment_type, :start_date, :end_date, :day_count,
                :customer_notes, :status, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            'customer_id' => $customerUserId,
            'location_id' => $locationId,
            'fulfillment_type' => $fulfillmentType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'day_count' => $days,
            'customer_notes' => $customerNotes,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $requestId = (int) $this->db->lastInsertId();
        $request = $this->find($requestId) ?? [];
        $this->dispatchLongTermRequestCreated($request);

        return $request;
    }

    /** @return list<array<string, mixed>> */
    public function listForCustomer(int $customerUserId): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, l.name AS location_name
             FROM long_term_requests r
             INNER JOIN locations l ON l.id = r.location_id
             WHERE r.customer_id = :customer_id
             ORDER BY r.created_at DESC, r.id DESC'
        );
        $stmt->execute(['customer_id' => $customerUserId]);

        return $stmt->fetchAll();
    }

    /** @return ?array<string, mixed> */
    public function find(int $requestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                    l.name AS location_name
             FROM long_term_requests r
             INNER JOIN users u ON u.id = r.customer_id
             INNER JOIN locations l ON l.id = r.location_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listAll(?string $status = null): array
    {
        $sql = 'SELECT r.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                       l.name AS location_name
                FROM long_term_requests r
                INNER JOIN users u ON u.id = r.customer_id
                INNER JOIN locations l ON l.id = r.location_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE r.status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY r.created_at DESC, r.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function updateStatus(int $requestId, string $status, ?string $adminNotes = null): void
    {
        $allowed = ['pending', 'contacted', 'quoted', 'converted', 'declined', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new BookingUnavailableException('Invalid request status.');
        }

        $this->db->prepare(
            'UPDATE long_term_requests SET status = :status, admin_notes = COALESCE(:admin_notes, admin_notes), updated_at = :updated_at WHERE id = :id'
        )->execute([
            'status' => $status,
            'admin_notes' => $adminNotes,
            'updated_at' => now_utc(),
            'id' => $requestId,
        ]);
    }

    /** @return ?array<string, mixed> */
    private function findPendingForCustomer(
        int $customerUserId,
        int $locationId,
        string $startDate,
        string $endDate,
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT r.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                    l.name AS location_name
             FROM long_term_requests r
             INNER JOIN users u ON u.id = r.customer_id
             INNER JOIN locations l ON l.id = r.location_id
             WHERE r.customer_id = :customer_id
               AND r.location_id = :location_id
               AND r.start_date = :start_date
               AND r.end_date = :end_date
               AND r.status = :status
             ORDER BY r.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'customer_id' => $customerUserId,
            'location_id' => $locationId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'pending',
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $request */
    private function dispatchLongTermRequestCreated(array $request): void
    {
        $notesSuffix = !empty($request['customer_notes'])
            ? ' Notes: ' . (string) $request['customer_notes']
            : '';

        $this->notifications->dispatch(
            'long_term_request_created',
            [
                'id' => 0,
                'customer_email' => (string) ($request['customer_email'] ?? ''),
                'customer_name' => (string) ($request['customer_name'] ?? ''),
                'customer_phone' => (string) ($request['customer_phone'] ?? ''),
                'start_date' => (string) ($request['start_date'] ?? ''),
                'end_date' => (string) ($request['end_date'] ?? ''),
                'location_name' => (string) ($request['location_name'] ?? ''),
                'fulfillment_type' => (string) ($request['fulfillment_type'] ?? ''),
                'customer_notes' => (string) ($request['customer_notes'] ?? ''),
            ],
            [
                'request_id' => (string) ($request['id'] ?? ''),
                'day_count' => (string) ((int) ($request['day_count'] ?? 0)),
                'location_name' => (string) ($request['location_name'] ?? ''),
                'fulfillment_type' => str_replace('_', ' ', (string) ($request['fulfillment_type'] ?? '')),
                'notes_suffix' => $notesSuffix,
            ],
        );
    }
}

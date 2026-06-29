<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class EquipmentService
{
    public const TYPE_STARLINK = 'starlink';
    public const TYPE_ACCESSORY = 'accessory';

    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->allByType(self::TYPE_STARLINK);
    }

    /** @return list<array<string, mixed>> */
    public function allByType(string $type): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, l.name AS location_name, p.name AS partner_name
             FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             LEFT JOIN partners p ON p.id = e.partner_id
             WHERE e.equipment_type = :equipment_type
             ORDER BY e.rental_priority ASC, e.id ASC'
        );
        $stmt->execute(['equipment_type' => $type]);

        return $stmt->fetchAll();
    }

    /** @return ?array<string, mixed> */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM equipment WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?int $equipmentId = null): int
    {
        $fields = $this->bindEquipment($data, forInsert: $equipmentId === null);
        $isStarlink = ($fields['equipment_type'] ?? '') === self::TYPE_STARLINK;

        if ($equipmentId === null) {
            if ($isStarlink && trim((string) ($data['starlink_account_password'] ?? '')) !== '') {
                $fields['starlink_account_password_enc'] = CredentialCipher::encrypt(trim((string) $data['starlink_account_password']));
            }
            $stmt = $this->db->prepare(
                'INSERT INTO equipment (
                    equipment_type, nickname, serial_number, sku, owner_type, partner_id, purchase_date, purchase_cost_cents,
                    starlink_account_email, starlink_account_password_enc, data_plan, billing_cycle_start_day,
                    subscription_payer, current_storage_location_id,
                    at_pickup_site, rental_priority, rental_price_cents_per_day, rental_price_cents_flat,
                    status, notes, created_at
                 ) VALUES (
                    :equipment_type, :nickname, :serial_number, :sku, :owner_type, :partner_id, :purchase_date, :purchase_cost_cents,
                    :starlink_account_email, :starlink_account_password_enc, :data_plan, :billing_cycle_start_day,
                    :subscription_payer, :current_storage_location_id,
                    :at_pickup_site, :rental_priority, :rental_price_cents_per_day, :rental_price_cents_flat,
                    :status, :notes, :created_at
                 )'
            );
            $stmt->execute($fields);
            $equipmentId = (int) $this->db->lastInsertId();
            if ($isStarlink) {
                (new StarlinkPlanService($this->db))->ensureInitialPlan($equipmentId, (string) $fields['owner_type']);
            }

            return $equipmentId;
        }

        $existing = $this->find($equipmentId);
        if ($existing === null) {
            throw new \RuntimeException('Equipment not found.');
        }

        $passwordEnc = $this->resolvePasswordEnc($data, $existing);
        $fields['starlink_account_password_enc'] = $passwordEnc;

        $stmt = $this->db->prepare(
            'UPDATE equipment SET
                equipment_type = :equipment_type, nickname = :nickname, serial_number = :serial_number, sku = :sku,
                owner_type = :owner_type, partner_id = :partner_id, purchase_date = :purchase_date,
                purchase_cost_cents = :purchase_cost_cents, starlink_account_email = :starlink_account_email,
                starlink_account_password_enc = :starlink_account_password_enc,
                data_plan = :data_plan, billing_cycle_start_day = :billing_cycle_start_day,
                subscription_payer = :subscription_payer,
                current_storage_location_id = :current_storage_location_id, at_pickup_site = :at_pickup_site,
                rental_priority = :rental_priority, rental_price_cents_per_day = :rental_price_cents_per_day,
                rental_price_cents_flat = :rental_price_cents_flat, status = :status, notes = :notes
             WHERE id = :id'
        );
        $fields['id'] = $equipmentId;
        $stmt->execute($fields);

        return $equipmentId;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $existing */
    private function resolvePasswordEnc(array $data, array $existing): ?string
    {
        if (!empty($data['clear_starlink_password'])) {
            return null;
        }

        $password = trim((string) ($data['starlink_account_password'] ?? ''));
        if ($password !== '') {
            return CredentialCipher::encrypt($password);
        }

        $existingEnc = $existing['starlink_account_password_enc'] ?? null;

        return ($existingEnc ?? '') !== '' ? (string) $existingEnc : null;
    }

    public function addCost(int $equipmentId, string $type, int $amountCents, string $incurredDate, ?string $description = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO equipment_costs (equipment_id, type, amount_cents, description, incurred_date, created_at)
             VALUES (:equipment_id, :type, :amount_cents, :description, :incurred_date, :created_at)'
        );
        $stmt->execute([
            'equipment_id' => $equipmentId,
            'type' => $type,
            'amount_cents' => $amountCents,
            'description' => $description,
            'incurred_date' => $incurredDate,
            'created_at' => now_utc(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function costs(int $equipmentId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM equipment_costs WHERE equipment_id = :id ORDER BY incurred_date DESC');
        $stmt->execute(['id' => $equipmentId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function allActiveForOps(): array
    {
        return $this->db->query(
            "SELECT e.id, e.nickname, e.equipment_type, e.current_storage_location_id,
                    l.name AS location_name, l.id AS location_id, l.city AS storage_city
             FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.status = 'active'
             ORDER BY e.equipment_type ASC, e.nickname ASC, e.id ASC"
        )->fetchAll();
    }

    /** @param array<string, mixed> $unit */
    public function catalogKey(array $unit): string
    {
        $sku = trim((string) ($unit['sku'] ?? ''));
        if ($sku !== '') {
            return $sku;
        }

        return trim((string) ($unit['nickname'] ?? ''));
    }

    /** @return list<array<string, mixed>> */
    public function availableAddOnCatalog(int $locationId, ?string $startDate = null, ?string $endDate = null): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, l.name AS location_name
             FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.equipment_type = :equipment_type
             AND e.status = 'active'
             AND e.current_storage_location_id = :location_id
             ORDER BY e.nickname ASC, e.id ASC"
        );
        $stmt->execute([
            'equipment_type' => self::TYPE_ACCESSORY,
            'location_id' => $locationId,
        ]);

        $groups = [];
        foreach ($stmt->fetchAll() as $unit) {
            $key = $this->catalogKey($unit);
            if ($key === '') {
                continue;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'id' => (int) $unit['id'],
                    'name' => (string) $unit['nickname'],
                    'sku' => ($unit['sku'] ?? '') !== '' ? (string) $unit['sku'] : null,
                    'rental_price_cents_per_day' => (int) ($unit['rental_price_cents_per_day'] ?? 0),
                    'rental_price_cents_flat' => (int) ($unit['rental_price_cents_flat'] ?? 0),
                    'quantity_available' => 0,
                    'catalog_key' => $key,
                ];
            }
        }

        foreach (array_keys($groups) as $key) {
            $groups[$key]['quantity_available'] = $this->availableAccessoryCount(
                $locationId,
                $key,
                $startDate,
                $endDate,
            );
        }

        return array_values(array_filter(
            $groups,
            static fn (array $row): bool => (int) $row['quantity_available'] > 0,
        ));
    }

    public function availableAccessoryCount(
        int $locationId,
        string $catalogKey,
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $excludeBookingId = null,
    ): int {
        $stmt = $this->db->prepare(
            "SELECT e.id, e.sku, e.nickname, e.status
             FROM equipment e
             WHERE e.equipment_type = :equipment_type
             AND e.current_storage_location_id = :location_id
             AND e.status IN ('active', 'with_customer')"
        );
        $stmt->execute([
            'equipment_type' => self::TYPE_ACCESSORY,
            'location_id' => $locationId,
        ]);

        $physical = 0;
        foreach ($stmt->fetchAll() as $unit) {
            if ($this->catalogKey($unit) !== $catalogKey) {
                continue;
            }
            if ((string) $unit['status'] === 'with_customer') {
                continue;
            }
            ++$physical;
        }

        if ($startDate === null || $endDate === null || $physical === 0) {
            return $physical;
        }

        $committed = $this->committedAddOnQuantity($locationId, $catalogKey, $startDate, $endDate, $excludeBookingId);

        return max(0, $physical - $committed);
    }

    public function findCatalogRepresentative(int $equipmentId, int $locationId): ?array
    {
        $unit = $this->find($equipmentId);
        if ($unit === null || (string) ($unit['equipment_type'] ?? '') !== self::TYPE_ACCESSORY) {
            return null;
        }
        if ((int) ($unit['current_storage_location_id'] ?? 0) !== $locationId) {
            return null;
        }
        if ((string) ($unit['status'] ?? '') !== 'active') {
            return null;
        }

        return $unit;
    }

    private function committedAddOnQuantity(
        int $locationId,
        string $catalogKey,
        string $startDate,
        string $endDate,
        ?int $excludeBookingId = null,
    ): int {
        $sql = "SELECT COALESCE(SUM(bao.quantity), 0)
                FROM booking_add_ons bao
                INNER JOIN bookings b ON b.id = bao.booking_id
                INNER JOIN equipment e ON e.id = bao.equipment_id
                WHERE b.cancelled_at IS NULL
                AND b.location_id = :location_id
                AND b.end_date >= :start_date
                AND b.start_date <= :end_date
                AND e.equipment_type = :equipment_type
                AND COALESCE(NULLIF(TRIM(e.sku), ''), e.nickname) = :catalog_key";
        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $sql .= ' AND b.id != :exclude_booking_id';
        }

        $stmt = $this->db->prepare($sql);
        $params = [
            'location_id' => $locationId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'equipment_type' => self::TYPE_ACCESSORY,
            'catalog_key' => $catalogKey,
        ];
        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $params['exclude_booking_id'] = $excludeBookingId;
        }
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $booking */
    public function restoreFromBooking(array $booking): void
    {
        $equipmentId = (int) ($booking['equipment_id'] ?? 0);
        if ($equipmentId <= 0) {
            return;
        }

        $returnLocationId = $this->resolveReturnLocationId($booking);
        if ($returnLocationId !== null) {
            $this->db->prepare(
                "UPDATE equipment SET status = 'active', current_storage_location_id = :location_id WHERE id = :id"
            )->execute([
                'location_id' => $returnLocationId,
                'id' => $equipmentId,
            ]);

            return;
        }

        $this->db->prepare("UPDATE equipment SET status = 'active' WHERE id = :id")->execute(['id' => $equipmentId]);
    }

    /** @param array<string, mixed> $booking */
    private function resolveReturnLocationId(array $booking): ?int
    {
        if ($booking['fulfillment_type'] === 'pickup' || $booking['fulfillment_type'] === 'city_delivery') {
            return (int) $booking['location_id'];
        }

        if ($booking['fulfillment_type'] === 'pickup_appointment') {
            $stmt = $this->db->prepare(
                "SELECT l2.id FROM locations l1
                 INNER JOIN locations l2
                    ON LOWER(TRIM(l2.city)) = LOWER(TRIM(l1.city))
                 WHERE l1.id = :location_id
                 AND l2.location_type = 'store'
                 AND l2.is_active = 1
                 ORDER BY l2.id ASC
                 LIMIT 1"
            );
            $stmt->execute(['location_id' => (int) $booking['location_id']]);
            $id = $stmt->fetchColumn();

            return $id !== false ? (int) $id : null;
        }

        return null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function bindEquipment(array $data, bool $forInsert = false): array
    {
        $equipmentType = (string) ($data['equipment_type'] ?? self::TYPE_STARLINK);
        if (!in_array($equipmentType, [self::TYPE_STARLINK, self::TYPE_ACCESSORY], true)) {
            $equipmentType = self::TYPE_STARLINK;
        }

        $pricePerDay = ($data['rental_price_per_day'] ?? '') !== ''
            ? (int) round(((float) $data['rental_price_per_day']) * 100)
            : (($data['rental_price_cents_per_day'] ?? '') !== '' ? (int) $data['rental_price_cents_per_day'] : null);
        $priceFlat = ($data['rental_price_flat'] ?? '') !== ''
            ? (int) round(((float) $data['rental_price_flat']) * 100)
            : (($data['rental_price_cents_flat'] ?? '') !== '' ? (int) $data['rental_price_cents_flat'] : null);

        $ownerType = (string) ($data['owner_type'] ?? 'admin');
        $subscriptionPayer = (string) ($data['subscription_payer'] ?? '');
        if (!in_array($subscriptionPayer, ['partner', 'admin'], true)) {
            $subscriptionPayer = $ownerType === 'partner' ? 'partner' : 'admin';
        }

        $fields = [
            'equipment_type' => $equipmentType,
            'nickname' => (string) ($data['nickname'] ?? ''),
            'serial_number' => ($data['serial_number'] ?? '') !== '' ? (string) $data['serial_number'] : null,
            'sku' => ($data['sku'] ?? '') !== '' ? (string) $data['sku'] : null,
            'owner_type' => $ownerType,
            'partner_id' => !empty($data['partner_id']) ? (int) $data['partner_id'] : null,
            'purchase_date' => ($data['purchase_date'] ?? '') !== '' ? (string) $data['purchase_date'] : null,
            'purchase_cost_cents' => (int) ($data['purchase_cost_cents'] ?? 0),
            'starlink_account_email' => ($data['starlink_account_email'] ?? '') !== '' ? (string) $data['starlink_account_email'] : null,
            'starlink_account_password_enc' => null,
            'data_plan' => ($data['data_plan'] ?? '') !== '' ? (string) $data['data_plan'] : null,
            'billing_cycle_start_day' => !empty($data['billing_cycle_start_day']) ? (int) $data['billing_cycle_start_day'] : null,
            'subscription_payer' => $subscriptionPayer,
            'current_storage_location_id' => (int) ($data['current_storage_location_id'] ?? 0),
            'at_pickup_site' => 0,
            'rental_priority' => (int) ($data['rental_priority'] ?? 0),
            'rental_price_cents_per_day' => $pricePerDay,
            'rental_price_cents_flat' => $priceFlat,
            'status' => (string) ($data['status'] ?? 'active'),
            'notes' => ($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null,
        ];

        if ($equipmentType === self::TYPE_ACCESSORY) {
            $fields['owner_type'] = 'admin';
            $fields['partner_id'] = null;
            $fields['starlink_account_email'] = null;
            $fields['starlink_account_password_enc'] = null;
            $fields['data_plan'] = null;
            $fields['billing_cycle_start_day'] = null;
            $fields['subscription_payer'] = 'admin';
            $fields['rental_priority'] = 0;
        }

        if ($forInsert) {
            $fields['created_at'] = now_utc();
        }

        return $fields;
    }

    /** @return list<array{city: string, at_location: int, with_customer: int, staging_in: int, booked: int}> */
    public function summaryByCity(): array
    {
        $rows = $this->db->query(
            "SELECT LOWER(TRIM(l.city)) AS city_key, TRIM(l.city) AS city,
                    SUM(CASE WHEN e.status = 'active' THEN 1 ELSE 0 END) AS at_location,
                    SUM(CASE WHEN e.status = 'with_customer' THEN 1 ELSE 0 END) AS with_customer
             FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.equipment_type = 'starlink'
             AND e.status IN ('active', 'with_customer')
             GROUP BY city_key
             ORDER BY city ASC"
        )->fetchAll();

        $staging = $this->db->query(
            "SELECT LOWER(TRIM(lt.city)) AS city_key, COUNT(*) AS cnt
             FROM staging_events s
             INNER JOIN locations lt ON lt.id = s.to_location_id
             WHERE s.status = 'scheduled'
             GROUP BY city_key"
        )->fetchAll();
        $stagingMap = [];
        foreach ($staging as $row) {
            $stagingMap[(string) $row['city_key']] = (int) $row['cnt'];
        }

        $booked = $this->db->query(
            "SELECT LOWER(TRIM(l.city)) AS city_key, COUNT(*) AS cnt
             FROM bookings b
             INNER JOIN locations l ON l.id = b.location_id
             WHERE b.cancelled_at IS NULL
             AND b.booking_status IN ('booking_confirmed', 'booking_active', 'booking_late')
             GROUP BY city_key"
        )->fetchAll();
        $bookedMap = [];
        foreach ($booked as $row) {
            $bookedMap[(string) $row['city_key']] = (int) $row['cnt'];
        }

        $summary = [];
        foreach ($rows as $row) {
            $key = (string) $row['city_key'];
            $summary[] = [
                'city' => (string) $row['city'],
                'at_location' => (int) $row['at_location'],
                'with_customer' => (int) $row['with_customer'],
                'staging_in' => $stagingMap[$key] ?? 0,
                'booked' => $bookedMap[$key] ?? 0,
            ];
        }

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    public function withCommitments(?string $type = null): array
    {
        $type ??= self::TYPE_STARLINK;
        $units = $this->allByType($type);
        foreach ($units as &$unit) {
            $unit['next_commitment'] = $type === self::TYPE_STARLINK
                ? $this->nextCommitmentForUnit((int) $unit['id'])
                : null;
        }

        return $units;
    }

    public function delete(int $equipmentId): void
    {
        $equipment = $this->find($equipmentId);
        if ($equipment === null) {
            throw new \RuntimeException('Equipment not found.');
        }

        $blockReason = $this->deleteBlockReason($equipmentId, (string) ($equipment['status'] ?? ''));
        if ($blockReason !== null) {
            throw new \RuntimeException($blockReason);
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM equipment_costs WHERE equipment_id = :id')
                ->execute(['id' => $equipmentId]);
            $this->db->prepare('DELETE FROM equipment WHERE id = :id')
                ->execute(['id' => $equipmentId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Unable to delete equipment.', 0, $e);
        }
    }

    private function deleteBlockReason(int $equipmentId, string $status): ?string
    {
        if ($status === 'with_customer') {
            return 'This unit is out with a customer. Return it before deleting.';
        }

        $bookingCount = $this->countReferences(
            'SELECT COUNT(*) FROM bookings
             WHERE equipment_id = :id OR partner_owner_equipment_id = :id',
            $equipmentId,
        );
        if ($bookingCount > 0) {
            return 'This unit is linked to existing bookings and cannot be deleted.';
        }

        $stagingCount = $this->countReferences(
            "SELECT COUNT(*) FROM staging_events
             WHERE equipment_id = :id AND status = 'scheduled'",
            $equipmentId,
        );
        if ($stagingCount > 0) {
            return 'Cancel scheduled staging moves for this unit before deleting.';
        }

        $blockCount = $this->countReferences(
            'SELECT COUNT(*) FROM owner_blocks WHERE equipment_id = :id',
            $equipmentId,
        );
        if ($blockCount > 0) {
            return 'Remove owner blocks on this unit before deleting.';
        }

        $addOnCount = $this->countReferences(
            'SELECT COUNT(*) FROM booking_add_ons WHERE equipment_id = :id',
            $equipmentId,
        );
        if ($addOnCount > 0) {
            return 'This accessory was used on a booking and cannot be deleted.';
        }

        return null;
    }

    private function countReferences(string $sql, int $equipmentId): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $equipmentId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return ?array{label: string, staging_date: ?string, ready_date: ?string} */
    private function nextCommitmentForUnit(int $equipmentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT b.reference_code, b.start_date, b.staging_date, l.name AS location_name
             FROM bookings b
             INNER JOIN locations l ON l.id = b.location_id
             WHERE b.equipment_id = :equipment_id
             AND b.cancelled_at IS NULL
             AND b.booking_status IN ('booking_confirmed', 'booking_active', 'booking_late', 'booking_pending_payment')
             ORDER BY b.start_date ASC
             LIMIT 1"
        );
        $stmt->execute(['equipment_id' => $equipmentId]);
        $row = $stmt->fetch();
        if ($row === false) {
            $staging = $this->db->prepare(
                "SELECT s.staging_date, s.ready_date, lt.name AS to_name
                 FROM staging_events s
                 INNER JOIN locations lt ON lt.id = s.to_location_id
                 WHERE s.equipment_id = :equipment_id AND s.status = 'scheduled'
                 ORDER BY s.staging_date ASC LIMIT 1"
            );
            $staging->execute(['equipment_id' => $equipmentId]);
            $event = $staging->fetch();
            if ($event === false) {
                return null;
            }

            return [
                'label' => 'Stage to ' . (string) $event['to_name'],
                'staging_date' => (string) $event['staging_date'],
                'ready_date' => (string) $event['ready_date'],
            ];
        }

        return [
            'label' => 'Booking ' . (string) ($row['reference_code'] ?? '') . ' @ ' . (string) $row['location_name'],
            'staging_date' => ($row['staging_date'] ?? '') !== '' ? (string) $row['staging_date'] : null,
            'ready_date' => (string) $row['start_date'],
        ];
    }
}

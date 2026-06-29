<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class OperationsService
{
    private readonly PDO $db;

    public function __construct(
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly StagingService $staging = new StagingService(),
        private readonly SquareDepositService $deposits = new SquareDepositService(),
        private readonly BookingStateService $bookingState = new BookingStateService(),
        private readonly EquipmentService $equipment = new EquipmentService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    /** @return array<string, int> */
    public function dashboardStats(): array
    {
        return [
            'active_bookings' => (int) $this->db->query(
                "SELECT COUNT(*) FROM bookings WHERE booking_status IN ('booking_confirmed','booking_active','booking_late')"
            )->fetchColumn(),
            'units_out' => (int) $this->db->query(
                "SELECT COUNT(*) FROM equipment WHERE status = 'with_customer'"
            )->fetchColumn(),
            'revenue_cents' => (int) $this->db->query(
                "SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE status = 'completed' AND type IN ('checkout','rental','late_fee','shipping','addon')"
            )->fetchColumn(),
            'deposits_held_cents' => (int) $this->db->query(
                "SELECT COALESCE(SUM(deposit_cents),0) FROM bookings WHERE payment_status = 'payment_deposit_scheduled_processed' AND booking_status IN ('booking_confirmed','booking_active','booking_late')"
            )->fetchColumn(),
            'pending_appointments' => (int) $this->db->query(
                "SELECT COUNT(*) FROM bookings
                 WHERE fulfillment_type = 'pickup_appointment'
                 AND booking_status NOT IN ('booking_cancelled', 'booking_pending_payment', 'booking_closed')
                 AND appointment_status IN ('appointment_awaiting_admin','appointment_proposed')"
            )->fetchColumn(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listBookings(?string $status = null): array
    {
        $sql = 'SELECT b.*, l.name AS location_name, e.nickname AS equipment_name, u.name AS customer_name, u.email AS customer_email
                FROM bookings b
                INNER JOIN locations l ON l.id = b.location_id
                LEFT JOIN equipment e ON e.id = b.equipment_id
                INNER JOIN users u ON u.id = b.customer_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE b.booking_status = :booking_status';
            $params['booking_status'] = $status;
        }
        $sql .= ' ' . $this->orderClauseForBookingsList($status);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, int> */
    public function bookingTabCounts(): array
    {
        $rows = $this->db->query(
            "SELECT booking_status, COUNT(*) AS cnt FROM bookings GROUP BY booking_status"
        )->fetchAll();

        $counts = [
            '' => 0,
            'booking_pending_payment' => 0,
            'booking_confirmed' => 0,
            'booking_cancellation_pending' => 0,
            'booking_active' => 0,
            'booking_late' => 0,
            'booking_closed' => 0,
            'booking_cancelled' => 0,
        ];

        foreach ($rows as $row) {
            $key = (string) $row['booking_status'];
            $cnt = (int) $row['cnt'];
            if (array_key_exists($key, $counts)) {
                $counts[$key] = $cnt;
            }
            $counts[''] += $cnt;
        }

        return $counts;
    }

    private function orderClauseForBookingsList(?string $status): string
    {
        return match ($status) {
            'booking_pending_payment' => 'ORDER BY b.created_at ASC, b.id ASC',
            'booking_confirmed', 'booking_cancellation_pending' => 'ORDER BY b.start_date ASC, b.id ASC',
            'booking_active', 'booking_late' => 'ORDER BY b.end_date ASC, b.id ASC',
            default => 'ORDER BY COALESCE(b.reference_code, CAST(b.id AS TEXT)) ASC, b.id DESC',
        };
    }

    public function advanceBooking(int $bookingId, string $action, ?int $damageCents = null): array
    {
        $booking = $this->fetchBooking($bookingId);
        if ($booking === null) {
            throw new BookingUnavailableException('Booking not found.');
        }

        $lifecycle = booking_lifecycle_status($booking);
        $fulfillment = booking_fulfillment_status($booking);

        match ($action) {
            'stage' => $this->actionStage($bookingId, $booking, $lifecycle, $fulfillment),
            'pickup' => $this->actionPickup($bookingId, $booking, $lifecycle, $fulfillment),
            'ship' => $this->actionShip($bookingId, $booking, $lifecycle, $fulfillment),
            'return_received' => $this->actionReturnReceived($bookingId, $booking, $lifecycle, $fulfillment),
            'confirm_qc' => $this->actionConfirmQc($bookingId, $booking, $fulfillment),
            'qc_failed' => $this->actionQcFailed($bookingId, $booking, $fulfillment, $damageCents),
            default => throw new BookingUnavailableException('Unknown booking action.'),
        };

        return $this->fetchBooking($bookingId) ?? [];
    }

    /** @param array<string, mixed> $booking */
    private function actionStage(int $bookingId, array $booking, string $lifecycle, string $fulfillment): void
    {
        if ($lifecycle !== 'booking_confirmed') {
            throw new BookingUnavailableException('Booking must be confirmed before staging.');
        }
        if ($fulfillment !== 'fulfillment_pending') {
            throw new BookingUnavailableException('Booking is not awaiting staging.');
        }
        if (!$this->bookingState->canHandOutHardware($booking)) {
            throw new BookingUnavailableException('Deposit must be authorized before hardware can be staged.');
        }
        if (empty($booking['equipment_id'])) {
            throw new BookingUnavailableException('Assign a unit before marking staged.');
        }

        $this->bookingState->apply($bookingId, ['fulfillment_status' => 'fulfillment_staged']);
        $this->staging->completeForBooking($bookingId);
    }

    /** @param array<string, mixed> $booking */
    private function actionPickup(int $bookingId, array $booking, string $lifecycle, string $fulfillment): void
    {
        if ($booking['fulfillment_type'] === 'mail_ship') {
            throw new BookingUnavailableException('Mail bookings ship — do not mark picked up.');
        }
        if (!$this->bookingState->canHandOutHardware($booking)) {
            throw new BookingUnavailableException('Deposit must be authorized before hardware can be released.');
        }
        if ($lifecycle !== 'booking_confirmed') {
            throw new BookingUnavailableException('Booking must be confirmed before pickup.');
        }
        if (!in_array($fulfillment, ['fulfillment_pending', 'fulfillment_staged'], true)) {
            throw new BookingUnavailableException('Booking is not ready for pickup.');
        }
        if (empty($booking['equipment_id'])) {
            throw new BookingUnavailableException('Assign a unit before pickup.');
        }

        $this->bookingState->apply($bookingId, [
            'booking_status' => 'booking_active',
            'fulfillment_status' => 'fulfillment_with_customer',
        ]);

        if (!empty($booking['equipment_id'])) {
            $this->db->prepare("UPDATE equipment SET status = 'with_customer' WHERE id = :id")->execute([
                'id' => (int) $booking['equipment_id'],
            ]);
        }

        $updated = $this->fetchBooking($bookingId) ?? $booking;
        $this->notifications->dispatch('fulfillment_with_customer', $updated);
    }

    /** @param array<string, mixed> $booking */
    private function actionShip(int $bookingId, array $booking, string $lifecycle, string $fulfillment): void
    {
        if ($booking['fulfillment_type'] !== 'mail_ship') {
            throw new BookingUnavailableException('Only mail bookings can be marked shipped.');
        }
        if (!$this->bookingState->canHandOutHardware($booking)) {
            throw new BookingUnavailableException('Deposit must be authorized before hardware can be released.');
        }
        if ($lifecycle !== 'booking_confirmed') {
            throw new BookingUnavailableException('Booking must be confirmed before shipping.');
        }
        if (!in_array($fulfillment, ['fulfillment_pending', 'fulfillment_staged'], true)) {
            throw new BookingUnavailableException('Booking is not ready to ship.');
        }
        if (empty($booking['equipment_id'])) {
            throw new BookingUnavailableException('Assign a unit before shipping.');
        }

        $this->bookingState->apply($bookingId, [
            'booking_status' => 'booking_active',
            'fulfillment_status' => 'shipping_received',
        ]);

        if (!empty($booking['equipment_id'])) {
            $this->db->prepare("UPDATE equipment SET status = 'with_customer' WHERE id = :id")->execute([
                'id' => (int) $booking['equipment_id'],
            ]);
        }

        $this->notifications->dispatch('fulfillment_shipped', $this->fetchBooking($bookingId) ?? $booking);
    }

    /** @param array<string, mixed> $booking */
    private function actionReturnReceived(int $bookingId, array $booking, string $lifecycle, string $fulfillment): void
    {
        if (!in_array($lifecycle, ['booking_active', 'booking_late'], true)) {
            throw new BookingUnavailableException('Booking is not out with customer.');
        }
        if (!in_array($fulfillment, ['fulfillment_with_customer', 'shipping_received'], true)) {
            throw new BookingUnavailableException('Booking is not awaiting return.');
        }

        $this->bookingState->apply($bookingId, ['fulfillment_status' => 'fulfillment_return_received']);

        if (!empty($booking['equipment_id'])) {
            $this->equipment->restoreFromBooking($booking);
        }

        $updated = $this->fetchBooking($bookingId) ?? $booking;
        $this->notifications->dispatch('fulfillment_return_received', $updated);
    }

    /** @param array<string, mixed> $booking */
    private function actionConfirmQc(int $bookingId, array $booking, string $fulfillment): void
    {
        if ($fulfillment !== 'fulfillment_return_received') {
            throw new BookingUnavailableException('Unit must be received before QC can be confirmed.');
        }

        $depositResult = $this->deposits->releaseDepositOnReturn($booking);
        if (!$depositResult['ok']) {
            throw new BookingUnavailableException(
                'Deposit release failed: ' . ($depositResult['message'] ?? 'Square error'),
            );
        }

        $paymentStatus = booking_payment_status($booking);
        $states = [
            'booking_status' => 'booking_closed',
            'fulfillment_status' => 'fulfillment_return_confirmed',
        ];
        if ($paymentStatus === 'payment_deposit_scheduled_processed') {
            $states['payment_status'] = 'payment_deposit_scheduled_released';
        }

        $this->bookingState->apply($bookingId, $states);
        $this->releaseAddOns($bookingId);

        $updated = $this->fetchBooking($bookingId) ?? $booking;
        $this->notifications->dispatch('fulfillment_return_confirmed', $updated);
        $this->notifications->dispatch('booking_closed', $updated);
    }

    /** @param array<string, mixed> $booking */
    private function actionQcFailed(int $bookingId, array $booking, string $fulfillment, ?int $damageCents): void
    {
        if ($fulfillment !== 'fulfillment_return_received') {
            throw new BookingUnavailableException('Unit must be received before QC can fail.');
        }

        $chargeCents = $damageCents ?? (int) $booking['deposit_cents'];
        $chargeResult = $this->deposits->chargeDamageFromDeposit($booking, $chargeCents);
        if (!$chargeResult['ok']) {
            throw new BookingUnavailableException(
                'QC failed but damage charge failed: ' . ($chargeResult['message'] ?? 'Payment error'),
            );
        }

        $this->bookingState->apply($bookingId, [
            'booking_status' => 'booking_closed',
            'fulfillment_status' => 'fulfillment_return_failed',
        ]);

        $updated = $this->fetchBooking($bookingId) ?? $booking;
        $this->notifications->dispatch('fulfillment_return_failed', $updated);
        $this->releaseAddOns($bookingId);
    }

    /** @param array<string, mixed> $booking */
    private function restoreEquipmentAfterReturn(array $booking): void
    {
        $this->equipment->restoreFromBooking($booking);
    }

    public function completeStaging(int $stagingId): void
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, l.is_customer_pickup FROM staging_events s
             INNER JOIN locations l ON l.id = s.to_location_id
             WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $stagingId]);
        $event = $stmt->fetch();
        if (!$event || $event['status'] !== 'scheduled') {
            throw new BookingUnavailableException('Staging event not found or already completed.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE staging_events SET status = 'completed' WHERE id = :id")->execute(['id' => $stagingId]);
            $this->db->prepare(
                'UPDATE equipment SET current_storage_location_id = :location_id WHERE id = :equipment_id'
            )->execute([
                'location_id' => (int) $event['to_location_id'],
                'equipment_id' => (int) $event['equipment_id'],
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listInventory(?int $locationId = null): array
    {
        if ($locationId !== null) {
            $stmt = $this->db->prepare('SELECT i.*, l.name AS location_name FROM inventory_items i INNER JOIN locations l ON l.id = i.location_id WHERE i.location_id = :location_id ORDER BY i.name ASC');
            $stmt->execute(['location_id' => $locationId]);
            return $stmt->fetchAll();
        }

        return $this->db->query(
            'SELECT i.*, l.name AS location_name FROM inventory_items i INNER JOIN locations l ON l.id = i.location_id ORDER BY l.name ASC, i.name ASC'
        )->fetchAll();
    }

    /** @param array<string, mixed> $data */
    public function saveInventoryItem(array $data, ?int $itemId = null): int
    {
        $fields = [
            'sku' => (string) ($data['sku'] ?? ''),
            'name' => (string) ($data['name'] ?? ''),
            'description' => ($data['description'] ?? '') !== '' ? (string) $data['description'] : null,
            'location_id' => (int) ($data['location_id'] ?? 0),
            'quantity_total' => (int) ($data['quantity_total'] ?? 0),
            'quantity_available' => (int) ($data['quantity_available'] ?? 0),
            'rental_price_cents_per_day' => ($data['rental_price_cents_per_day'] ?? '') !== '' ? (int) $data['rental_price_cents_per_day'] : null,
            'rental_price_cents_flat' => ($data['rental_price_cents_flat'] ?? '') !== '' ? (int) $data['rental_price_cents_flat'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        if ($itemId === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO inventory_items (sku, name, description, location_id, quantity_total, quantity_available, rental_price_cents_per_day, rental_price_cents_flat, is_active, created_at)
                 VALUES (:sku, :name, :description, :location_id, :quantity_total, :quantity_available, :rental_price_cents_per_day, :rental_price_cents_flat, :is_active, :created_at)'
            );
            $fields['created_at'] = now_utc();
            $stmt->execute($fields);
            return (int) $this->db->lastInsertId();
        }

        $fields['id'] = $itemId;
        $this->db->prepare(
            'UPDATE inventory_items SET sku = :sku, name = :name, description = :description, location_id = :location_id,
             quantity_total = :quantity_total, quantity_available = :quantity_available,
             rental_price_cents_per_day = :rental_price_cents_per_day, rental_price_cents_flat = :rental_price_cents_flat,
             is_active = :is_active WHERE id = :id'
        )->execute($fields);

        return $itemId;
    }

    /** @return list<array<string, mixed>> */
    public function listLocations(): array
    {
        return $this->db->query('SELECT * FROM locations ORDER BY is_active DESC, name ASC')->fetchAll();
    }

    public function setLocationActive(int $locationId, bool $active): void
    {
        $location = $this->findLocation($locationId);
        if ($location === null) {
            return;
        }

        $customerFacing = $active && in_array($location['location_type'] ?? '', ['store', 'home'], true);

        $this->db->prepare(
            'UPDATE locations SET is_active = :active, is_customer_pickup = :is_customer_pickup WHERE id = :id'
        )->execute([
            'active' => $active ? 1 : 0,
            'is_customer_pickup' => $customerFacing ? 1 : 0,
            'id' => $locationId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findLocation(int $locationId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM locations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $locationId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function saveLocation(int $locationId, array $data): void
    {
        $location = $this->findLocation($locationId);
        if ($location === null) {
            throw new BookingUnavailableException('Location not found.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $slug = $this->normalizeLocationSlug((string) ($data['slug'] ?? ''));
        $locationType = (string) ($data['location_type'] ?? '');
        $address = trim((string) ($data['address'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $province = trim((string) ($data['province'] ?? 'AB'));

        if ($name === '' || $slug === '' || $address === '' || $city === '') {
            throw new BookingUnavailableException('Name, slug, address, and city are required.');
        }

        if (!in_array($locationType, ['store', 'home', 'warehouse'], true)) {
            throw new BookingUnavailableException('Invalid location type.');
        }

        $duplicate = $this->db->prepare('SELECT id FROM locations WHERE slug = :slug AND id != :id LIMIT 1');
        $duplicate->execute(['slug' => $slug, 'id' => $locationId]);
        if ($duplicate->fetch()) {
            throw new BookingUnavailableException('That slug is already in use by another location.');
        }

        $stmt = $this->db->prepare(
            'UPDATE locations
             SET name = :name,
                 slug = :slug,
                 location_type = :location_type,
                 address = :address,
                 city = :city,
                 province = :province,
                 pickup_instructions = :pickup_instructions,
                 is_active = :is_active,
                 is_customer_pickup = :is_customer_pickup
             WHERE id = :id'
        );
        $active = !empty($data['is_active']) ? 1 : 0;
        $customerFacing = $active === 1 && in_array($locationType, ['store', 'home'], true);
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'location_type' => $locationType,
            'address' => $address,
            'city' => $city,
            'province' => $province !== '' ? $province : 'AB',
            'pickup_instructions' => trim((string) ($data['pickup_instructions'] ?? '')) ?: null,
            'is_active' => $active,
            'is_customer_pickup' => $customerFacing ? 1 : 0,
            'id' => $locationId,
        ]);
    }

    private function normalizeLocationSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    private function releaseAddOns(int $bookingId): void
    {
        // Path B: add-on capacity is derived from booking_add_ons rows; no counter to restore.
    }

    /** @return ?array<string, mixed> */
    private function fetchBooking(int $bookingId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, u.email AS customer_email FROM bookings b INNER JOIN users u ON u.id = b.customer_id WHERE b.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $bookingId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

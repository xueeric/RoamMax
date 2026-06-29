<?php

declare(strict_types=1);

namespace Starlink\Services;

use DateTimeImmutable;
use PDO;
use Starlink\Database\Connection;

final class StagingService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    public function needsStaging(array $equipment, int $locationId, string $fulfillmentType): bool
    {
        if ($fulfillmentType === 'city_delivery') {
            return true;
        }

        if ($fulfillmentType !== 'pickup') {
            return false;
        }

        return (int) $equipment['current_storage_location_id'] !== $locationId;
    }

    public function stagingLeadDays(array $equipment, int $locationId, string $fulfillmentType): int
    {
        if ($fulfillmentType === 'city_delivery') {
            return (int) pricing_config('staging_lead_days', (int) config('staging_lead_days', 1));
        }

        if (!$this->needsStaging($equipment, $locationId, $fulfillmentType)) {
            return 0;
        }

        $pickupLocation = $this->findLocation($locationId);
        if ($pickupLocation === null) {
            return (int) pricing_config('staging_lead_days', (int) config('staging_lead_days', 1));
        }

        $storageCity = $this->normalizeCity((string) ($equipment['storage_city'] ?? ''));
        $pickupCity = $this->normalizeCity((string) ($pickupLocation['city'] ?? ''));

        if ($storageCity !== '' && $storageCity === $pickupCity) {
            return (int) pricing_config('staging_lead_days', (int) config('staging_lead_days', 1));
        }

        return (int) pricing_config('inter_city_staging_lead_days', (int) config('inter_city_staging_lead_days', 7));
    }

    public function earliestStartDate(
        array $equipment,
        int $locationId,
        string $fulfillmentType,
        ?DateTimeImmutable $reference = null,
    ): DateTimeImmutable {
        $today = ($reference ?? today_date())->setTime(0, 0);
        $leadDays = $this->stagingLeadDays($equipment, $locationId, $fulfillmentType);

        return match ($fulfillmentType) {
            'pickup' => $leadDays > 0 ? $today->modify('+' . $leadDays . ' days') : $today,
            'city_delivery' => $today->modify('+' . $this->stagingLeadDays($equipment, $locationId, $fulfillmentType) . ' days'),
            'pickup_appointment' => $this->isAtHomeLocation($equipment)
                ? $today->modify('+1 day')
                : $today,
            'mail_ship' => $today->modify('+' . (int) pricing_config('shipping_lead_days', (int) config('shipping_lead_days', 1)) . ' days'),
            default => $today,
        };
    }

    public function stagingDateForStart(
        array $equipment,
        int $locationId,
        string $fulfillmentType,
        DateTimeImmutable $startDate,
    ): ?DateTimeImmutable {
        $leadDays = $this->stagingLeadDays($equipment, $locationId, $fulfillmentType);
        if ($leadDays <= 0) {
            return null;
        }

        return $startDate->modify('-' . $leadDays . ' days');
    }

    public function readyDateFromStaging(DateTimeImmutable $stagingDate, ?int $leadDays = null): DateTimeImmutable
    {
        $days = $leadDays ?? (int) pricing_config('staging_lead_days', (int) config('staging_lead_days', 1));

        return $stagingDate->modify('+' . $days . ' days');
    }

    public function scheduleForBooking(
        int $bookingId,
        array $equipment,
        int $toLocationId,
        string $startDate,
        string $fulfillmentType = 'pickup',
    ): void {
        $start = parse_date($startDate);
        if ($start === null || !$this->needsStaging($equipment, $toLocationId, $fulfillmentType)) {
            return;
        }

        $stagingDate = $this->stagingDateForStart($equipment, $toLocationId, $fulfillmentType, $start);
        if ($stagingDate === null) {
            return;
        }

        $existing = $this->db->prepare(
            "SELECT COUNT(*) FROM staging_events
             WHERE related_booking_id = :booking_id AND status = 'scheduled'"
        );
        $existing->execute(['booking_id' => $bookingId]);
        if ((int) $existing->fetchColumn() > 0) {
            return;
        }

        $this->db->prepare(
            'INSERT INTO staging_events (
                equipment_id, from_location_id, to_location_id, staging_date, ready_date,
                related_booking_id, status, created_at
             ) VALUES (
                :equipment_id, :from_location_id, :to_location_id, :staging_date, :ready_date,
                :related_booking_id, :status, :created_at
             )'
        )->execute([
            'equipment_id' => (int) $equipment['id'],
            'from_location_id' => (int) $equipment['current_storage_location_id'],
            'to_location_id' => $toLocationId,
            'staging_date' => $stagingDate->format('Y-m-d'),
            'ready_date' => $start->format('Y-m-d'),
            'related_booking_id' => $bookingId,
            'status' => 'scheduled',
            'created_at' => now_utc(),
        ]);
    }

    public function cancelForBooking(int $bookingId): void
    {
        $this->db->prepare(
            "UPDATE staging_events SET status = 'cancelled'
             WHERE related_booking_id = :booking_id AND status = 'scheduled'"
        )->execute(['booking_id' => $bookingId]);
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{
     *   equipment: string,
     *   has_scheduled_move: bool,
     *   from_name: string,
     *   to_name: string,
     *   current_name: string,
     *   staging_date: ?string,
     *   ready_date: ?string,
     *   move_line: string,
     *   confirm_message: string
     * }
     */
    public function stageSummaryForBooking(int $bookingId, array $booking): array
    {
        $equipmentId = (int) ($booking['equipment_id'] ?? 0);
        $equipmentLabel = trim((string) ($booking['equipment_name'] ?? ''));
        if ($equipmentLabel === '') {
            $equipmentLabel = $equipmentId > 0 ? 'Unit #' . $equipmentId : 'Not assigned yet';
        }
        $pickupName = trim((string) ($booking['location_name'] ?? 'Pickup site'));
        $reference = booking_reference($booking);
        $stagingDue = trim((string) ($booking['staging_date'] ?? ''));

        if ($equipmentId <= 0) {
            return [
                'equipment' => $equipmentLabel,
                'has_scheduled_move' => false,
                'from_name' => '',
                'to_name' => $pickupName,
                'current_name' => '',
                'staging_date' => $stagingDue !== '' ? $stagingDue : null,
                'ready_date' => (string) ($booking['start_date'] ?? ''),
                'move_line' => 'Assign a unit → ' . $pickupName,
                'confirm_message' => '',
                'needs_assign' => true,
            ];
        }

        $currentName = $this->equipmentLocationName($equipmentId);

        $stmt = $this->db->prepare(
            'SELECT s.staging_date, s.ready_date, lf.name AS from_name, lt.name AS to_name
             FROM staging_events s
             INNER JOIN locations lf ON lf.id = s.from_location_id
             INNER JOIN locations lt ON lt.id = s.to_location_id
             WHERE s.related_booking_id = :booking_id AND s.status = :status
             LIMIT 1'
        );
        $stmt->execute(['booking_id' => $bookingId, 'status' => 'scheduled']);
        $event = $stmt->fetch();

        if ($event !== false) {
            $from = (string) $event['from_name'];
            $to = (string) $event['to_name'];
            $stagingDate = (string) $event['staging_date'];
            $readyDate = (string) $event['ready_date'];

            return [
                'equipment' => $equipmentLabel,
                'has_scheduled_move' => true,
                'from_name' => $from,
                'to_name' => $to,
                'current_name' => $currentName,
                'staging_date' => $stagingDate,
                'ready_date' => $readyDate,
                'move_line' => $from . ' → ' . $to,
                'confirm_message' => "Confirm you moved equipment for {$reference}:\n\n"
                    . "Unit: {$equipmentLabel}\n"
                    . ($currentName !== '' ? "Currently listed at: {$currentName}\n" : '')
                    . "Move from: {$from}\n"
                    . "Move to: {$to}\n"
                    . "Stage by: {$stagingDate}\n"
                    . "Ready for pickup: {$readyDate}\n\n"
                    . "Press OK only if the unit is physically at {$to}.",
                'needs_assign' => false,
            ];
        }

        $moveLine = $currentName !== '' ? $currentName . ' → ' . $pickupName : '→ ' . $pickupName;
        $dueLine = $stagingDue !== '' ? 'Stage by: ' . $stagingDue : 'No move scheduled';

        return [
            'equipment' => $equipmentLabel,
            'has_scheduled_move' => false,
            'from_name' => $currentName,
            'to_name' => $pickupName,
            'current_name' => $currentName,
            'staging_date' => $stagingDue !== '' ? $stagingDue : null,
            'ready_date' => (string) ($booking['start_date'] ?? ''),
            'move_line' => $moveLine,
            'confirm_message' => "Confirm {$reference} is ready at the pickup site:\n\n"
                . "Unit: {$equipmentLabel}\n"
                . ($currentName !== '' ? "Listed location now: {$currentName}\n" : '')
                . "Pickup site: {$pickupName}\n"
                . $dueLine . "\n\n"
                . "Press OK only if the unit is already at {$pickupName}.",
            'needs_assign' => false,
        ];
    }

    private function equipmentLocationName(int $equipmentId): string
    {
        if ($equipmentId <= 0) {
            return '';
        }

        $stmt = $this->db->prepare(
            'SELECT l.name FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $equipmentId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : '';
    }

    public function completeForBooking(int $bookingId): void
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, l.is_customer_pickup FROM staging_events s
             INNER JOIN locations l ON l.id = s.to_location_id
             WHERE s.related_booking_id = :booking_id AND s.status = :status
             LIMIT 1'
        );
        $stmt->execute(['booking_id' => $bookingId, 'status' => 'scheduled']);
        $event = $stmt->fetch();
        if (!$event) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE staging_events SET status = 'completed' WHERE id = :id")->execute(['id' => (int) $event['id']]);
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

    /** @return ?array<string, mixed> */
    private function findLocation(int $locationId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM locations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $locationId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function normalizeCity(string $city): string
    {
        return strtolower(trim($city));
    }

    private function isAtHomeLocation(array $equipment): bool
    {
        return ($equipment['location_type'] ?? '') === 'home';
    }
}

<?php

declare(strict_types=1);

namespace Starlink\Services;

use DateTimeImmutable;
use PDO;
use Starlink\Booking\BookingStatuses;
use Starlink\Database\Connection;

final class AvailabilityService
{
    private readonly PDO $db;

    public function __construct(
        private readonly StagingService $staging = new StagingService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    public function isRangeAvailable(
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
    ): bool {
        $start = parse_date($startDate);
        $end = parse_date($endDate);
        if ($start === null || $end === null) {
            return false;
        }

        $minimumDays = (int) pricing_config('minimum_rental_days', (int) config('minimum_rental_days', 3));
        if (inclusive_day_count($start, $end) < $minimumDays) {
            return false;
        }

        if ($this->remainingCapacity($startDate, $endDate, $fulfillmentType) <= 0) {
            return false;
        }

        $earliest = parse_date($this->globalEarliestStart($locationId, $fulfillmentType));
        if ($earliest !== null && $start < $earliest) {
            return false;
        }

        return true;
    }

    /** @return array<string, array{available: bool, reason: ?string}> */
    public function calendarStartDays(
        int $locationId,
        string $fulfillmentType,
        string $rangeStart,
        string $rangeEnd,
    ): array {
        $start = parse_date($rangeStart);
        $end = parse_date($rangeEnd);
        if ($start === null || $end === null || $start > $end) {
            return [];
        }

        $minimumDays = (int) pricing_config('minimum_rental_days', (int) config('minimum_rental_days', 3));
        $earliest = parse_date($this->globalEarliestStart($locationId, $fulfillmentType));
        $days = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $rentalEnd = $cursor->modify('+' . ($minimumDays - 1) . ' days');

            if ($earliest !== null && $cursor < $earliest) {
                $days[$key] = ['available' => false, 'reason' => 'staging'];
            } elseif ($this->fleetCapacity($fulfillmentType) <= 0) {
                $days[$key] = ['available' => false, 'reason' => 'no_inventory'];
            } elseif ($this->remainingCapacity($key, $rentalEnd->format('Y-m-d'), $fulfillmentType) <= 0) {
                $days[$key] = ['available' => false, 'reason' => 'booked_or_blocked'];
            } else {
                $days[$key] = ['available' => true, 'reason' => null];
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    public function globalEarliestStart(int $locationId, string $fulfillmentType): string
    {
        $today = today_date();

        return match ($fulfillmentType) {
            'mail_ship' => $today->modify('+' . (int) pricing_config('shipping_lead_days', (int) config('shipping_lead_days', 1)) . ' days')->format('Y-m-d'),
            'city_delivery' => $today->modify('+' . $this->cityLeadDays($locationId) . ' days')->format('Y-m-d'),
            'pickup_appointment' => $this->homeAppointmentEarliest($today),
            default => $today->modify('+' . $this->cityLeadDays($locationId) . ' days')->format('Y-m-d'),
        };
    }

    public function fleetCapacity(string $fulfillmentType): int
    {
        if ($fulfillmentType === 'pickup_appointment') {
            $stmt = $this->db->query(
                "SELECT COUNT(*) FROM equipment e
                 INNER JOIN locations l ON l.id = e.current_storage_location_id
                 WHERE e.equipment_type = 'starlink'
                 AND e.status IN ('active', 'with_customer')
                 AND l.location_type = 'home'"
            );

            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM equipment WHERE equipment_type = 'starlink' AND status IN ('active', 'with_customer')"
        );

        return (int) $stmt->fetchColumn();
    }

    public function remainingCapacity(string $startDate, string $endDate, string $fulfillmentType): int
    {
        $start = parse_date($startDate);
        $end = parse_date($endDate);
        if ($start === null || $end === null) {
            return 0;
        }

        return max(0, $this->fleetCapacity($fulfillmentType) - $this->countBlockingBookings($start, $end));
    }

    /**
     * Units admin can assign to a booking (Path B — assign at staging/pickup).
     *
     * @param array<string, mixed> $booking
     * @return list<array<string, mixed>>
     */
    public function eligibleUnitsForBooking(array $booking): array
    {
        $locationId = (int) ($booking['location_id'] ?? 0);
        $fulfillmentType = BookingStatuses::normalizeFulfillmentType((string) ($booking['fulfillment_type'] ?? 'pickup'));
        $startDate = (string) ($booking['start_date'] ?? '');
        $endDate = (string) ($booking['end_date'] ?? '');
        $bookingId = (int) ($booking['id'] ?? 0);

        $stmt = $this->db->query(
            'SELECT e.*, l.name AS location_name, l.city AS storage_city, l.location_type
             FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.equipment_type = \'starlink\'
             AND e.status IN (\'active\', \'with_customer\')
             ORDER BY e.rental_priority ASC, e.id ASC'
        );

        $eligible = [];
        foreach ($stmt->fetchAll() as $unit) {
            if ($fulfillmentType === 'pickup_appointment' && ($unit['location_type'] ?? '') !== 'home') {
                continue;
            }
            if ($this->isUnitAvailableForBooking($unit, $locationId, $fulfillmentType, $startDate, $endDate, $bookingId)) {
                $eligible[] = $unit;
            }
        }

        return $eligible;
    }

    /**
     * @param array<string, mixed> $unit
     */
    public function isUnitAvailableForBooking(
        array $unit,
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
        ?int $excludeBookingId = null,
    ): bool {
        $start = parse_date($startDate);
        $end = parse_date($endDate);
        if ($start === null || $end === null) {
            return false;
        }

        if (!in_array((string) ($unit['status'] ?? ''), ['active', 'with_customer'], true)) {
            return false;
        }

        $equipmentId = (int) $unit['id'];
        if ($this->hasOverlappingBookingOnUnit($equipmentId, $start, $end, $excludeBookingId)) {
            return false;
        }
        if ($this->hasOverlappingBlock($equipmentId, $start, $end)) {
            return false;
        }
        if ($this->unitInTransitDuringRange($equipmentId, $start, $end)) {
            return false;
        }

        return true;
    }

    public function cityLeadDays(int $locationId): int
    {
        $sameCityLead = (int) pricing_config('staging_lead_days', (int) config('staging_lead_days', 2));
        $interCityLead = (int) pricing_config('inter_city_staging_lead_days', (int) config('inter_city_staging_lead_days', 7));

        if ($locationId <= 0) {
            return $sameCityLead;
        }

        $location = $this->findLocation($locationId);
        if ($location === null) {
            return $sameCityLead;
        }

        // Home pickup (e.g. Edmonton North): unit is on-site but customers book from tomorrow.
        if (($location['location_type'] ?? '') === 'home') {
            return 1;
        }

        // Store pickup with a unit already listed at this location.
        if ($this->unitsAtLocation($locationId) > 0) {
            return 0;
        }

        // Same-city move — fleet stored elsewhere in this city (e.g. North → Premium Outlet).
        if ($this->unitsInCityButNotAtLocation($locationId) > 0) {
            return $sameCityLead;
        }

        return $interCityLead;
    }

    public function unitsInCity(int $locationId): int
    {
        $location = $this->findLocation($locationId);
        if ($location === null) {
            return 0;
        }

        $city = $this->normalizeCity((string) ($location['city'] ?? ''));
        if ($city === '') {
            return 0;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.equipment_type = 'starlink'
             AND e.status = 'active'
             AND LOWER(TRIM(l.city)) = :city"
        );
        $stmt->execute(['city' => $city]);

        return (int) $stmt->fetchColumn();
    }

    public function unitsAtLocation(int $locationId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM equipment e
             WHERE e.equipment_type = 'starlink'
             AND e.status = 'active'
             AND e.current_storage_location_id = :location_id"
        );
        $stmt->execute(['location_id' => $locationId]);

        return (int) $stmt->fetchColumn();
    }

    public function unitsInCityButNotAtLocation(int $locationId): int
    {
        $location = $this->findLocation($locationId);
        if ($location === null) {
            return 0;
        }

        $city = $this->normalizeCity((string) ($location['city'] ?? ''));
        if ($city === '') {
            return 0;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.equipment_type = 'starlink'
             AND e.status = 'active'
             AND LOWER(TRIM(l.city)) = :city
             AND e.current_storage_location_id != :location_id"
        );
        $stmt->execute([
            'city' => $city,
            'location_id' => $locationId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function homeAppointmentEarliest(DateTimeImmutable $today): string
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.equipment_type = 'starlink'
             AND e.status = 'active' AND l.location_type = 'home'"
        );
        if ((int) $stmt->fetchColumn() <= 0) {
            return $today->modify('+7 days')->format('Y-m-d');
        }

        return $today->modify('+1 day')->format('Y-m-d');
    }

    private function countBlockingBookings(DateTimeImmutable $start, DateTimeImmutable $end, ?int $excludeBookingId = null): int
    {
        $blocking = implode("','", BookingStatuses::BLOCKING_BOOKING_STATUSES);
        $legacy = implode("','", BookingStatuses::LEGACY_OPEN_STATUSES);

        $sql = "SELECT COUNT(*) FROM bookings
             WHERE cancelled_at IS NULL
             AND start_date <= :end_date
             AND end_date >= :start_date
             AND (
                booking_status IN ('{$blocking}')
                OR status IN ('{$legacy}')
             )";
        $params = [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ];

        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeBookingId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function hasOverlappingBookingOnUnit(
        int $equipmentId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?int $excludeBookingId = null,
    ): bool {
        $blocking = implode("','", BookingStatuses::BLOCKING_BOOKING_STATUSES);
        $legacy = implode("','", BookingStatuses::LEGACY_OPEN_STATUSES);

        $sql = "SELECT COUNT(*) FROM bookings
             WHERE equipment_id = :equipment_id
             AND cancelled_at IS NULL
             AND start_date <= :end_date
             AND end_date >= :start_date
             AND (
                booking_status IN ('{$blocking}')
                OR status IN ('{$legacy}')
             )";
        $params = [
            'equipment_id' => $equipmentId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ];

        if ($excludeBookingId !== null && $excludeBookingId > 0) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeBookingId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasOverlappingBlock(int $equipmentId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM owner_blocks
             WHERE equipment_id = :equipment_id
             AND start_date <= :end_date
             AND end_date >= :start_date'
        );
        $stmt->execute([
            'equipment_id' => $equipmentId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function unitInTransitDuringRange(int $equipmentId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $cursor = $start;
        while ($cursor <= $end) {
            if ($this->dayIsInTransit($equipmentId, $cursor)) {
                return true;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return false;
    }

    private function dayIsInTransit(int $equipmentId, DateTimeImmutable $day): bool
    {
        $key = $day->format('Y-m-d');

        $staging = $this->db->prepare(
            "SELECT COUNT(*) FROM staging_events
             WHERE equipment_id = :equipment_id
             AND status = 'scheduled'
             AND staging_date <= :day
             AND ready_date >= :day"
        );
        $staging->execute(['equipment_id' => $equipmentId, 'day' => $key]);
        if ((int) $staging->fetchColumn() > 0) {
            return true;
        }

        return false;
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
}

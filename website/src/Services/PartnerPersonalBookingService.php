<?php

declare(strict_types=1);

namespace Starlink\Services;

use DateTimeImmutable;
use PDO;
use Starlink\Booking\BookingStatuses;
use Starlink\Database\Connection;

final class PartnerPersonalBookingService
{
    public const BORROW_POLICY_MESSAGE = 'If your unit is making us money those days, and you still want a kit, you pay what a customer would for those days.';

    private readonly PDO $db;

    public function __construct(
        private readonly PricingService $pricing = new PricingService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    /** @return array<string, mixed> */
    public function quote(
        int $partnerId,
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
    ): array {
        $plan = $this->planBooking($partnerId, $locationId, $fulfillmentType, $startDate, $endDate);

        return [
            'available' => true,
            'uses_own_unit' => $plan['uses_own_unit'],
            'owner_equipment_id' => $plan['owner_equipment_id'],
            'assigned_equipment_id' => $plan['assigned_equipment_id'],
            'assigned_equipment_nickname' => $plan['assigned_equipment_nickname'],
            'lender_partner_id' => $plan['lender_partner_id'],
            'lender_partner_name' => $plan['lender_partner_name'],
            'total_days' => $plan['total_days'],
            'conflict_days' => $plan['conflict_days'],
            'daily_rate_cents' => $plan['daily_rate_cents'],
            'borrow_fee_cents' => $plan['borrow_fee_cents'],
            'borrow_fee_formatted' => PricingService::formatMoney($plan['borrow_fee_cents']),
            'policy_message' => self::BORROW_POLICY_MESSAGE,
            'summary' => $plan['summary'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        int $partnerId,
        int $requestingUserId,
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
        ?string $notes = null,
    ): array {
        $plan = $this->planBooking($partnerId, $locationId, $fulfillmentType, $startDate, $endDate);
        $customerUserId = (new BookingService($this->db))->ensureCustomerUserId($requestingUserId);
        $fulfillmentType = BookingStatuses::normalizeFulfillmentType($fulfillmentType);
        $feeCents = (int) $plan['borrow_fee_cents'];
        $requiresPayment = $feeCents > 0;

        $this->db->beginTransaction();
        try {
            $referenceCode = (new BookingReferenceService())->generate($this->db);
            $stmt = $this->db->prepare(
                'INSERT INTO bookings (
                    customer_id, equipment_id, location_id, fulfillment_type, shipping_address_json,
                    reference_code, staging_date, start_date, end_date, daily_rate_cents, rental_total_cents,
                    shipping_fee_cents, deposit_cents, add_ons_total_cents, tax_cents, is_admin_created,
                    booking_kind, owner_block_requested_by, partner_owner_equipment_id, partner_conflict_days,
                    partner_borrow_fee_cents, customer_notes, appointment_status,
                    status, payment_status, booking_status, fulfillment_status, agreement_accepted_at, created_at
                 ) VALUES (
                    :customer_id, :equipment_id, :location_id, :fulfillment_type, :shipping_address_json,
                    :reference_code, :staging_date, :start_date, :end_date, :daily_rate_cents, :rental_total_cents,
                    :shipping_fee_cents, :deposit_cents, :add_ons_total_cents, :tax_cents, :is_admin_created,
                    :booking_kind, :owner_block_requested_by, :partner_owner_equipment_id, :partner_conflict_days,
                    :partner_borrow_fee_cents, :customer_notes, :appointment_status,
                    :status, :payment_status, :booking_status, :fulfillment_status, :agreement_accepted_at, :created_at
                 )'
            );
            $stmt->execute([
                'customer_id' => $customerUserId,
                'equipment_id' => (int) $plan['assigned_equipment_id'],
                'location_id' => $locationId,
                'fulfillment_type' => $fulfillmentType,
                'shipping_address_json' => null,
                'reference_code' => $referenceCode,
                'staging_date' => null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_rate_cents' => (int) $plan['daily_rate_cents'],
                'rental_total_cents' => $feeCents,
                'shipping_fee_cents' => 0,
                'deposit_cents' => 0,
                'add_ons_total_cents' => 0,
                'tax_cents' => 0,
                'is_admin_created' => 0,
                'booking_kind' => BookingStatuses::BOOKING_KIND_PARTNER_PERSONAL,
                'owner_block_requested_by' => 'partner',
                'partner_owner_equipment_id' => (int) $plan['owner_equipment_id'],
                'partner_conflict_days' => (int) $plan['conflict_days'],
                'partner_borrow_fee_cents' => $feeCents,
                'customer_notes' => $notes,
                'appointment_status' => $fulfillmentType === 'pickup_appointment' ? 'appointment_confirmed' : 'appointment_na',
                'status' => $requiresPayment ? 'pending_payment' : 'confirmed',
                'payment_status' => $requiresPayment ? 'payment_pending_etransfer' : 'payment_waived',
                'booking_status' => $requiresPayment ? 'booking_pending_payment' : 'booking_confirmed',
                'fulfillment_status' => 'fulfillment_pending',
                'agreement_accepted_at' => now_utc(),
                'created_at' => now_utc(),
            ]);

            $bookingId = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return (new BookingService($this->db))->findBooking($bookingId) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function listBorrowerBookings(int $customerUserId): array
    {
        return $this->listBookings(
            'b.customer_id = :customer_id',
            ['customer_id' => $customerUserId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function listLenderBookings(int $partnerId): array
    {
        return $this->listBookings(
            'e.partner_id = :partner_id AND b.customer_id != (SELECT user_id FROM partners WHERE id = :partner_id LIMIT 1)',
            ['partner_id' => $partnerId],
        );
    }

    /** @return array<string, mixed> */
    private function planBooking(
        int $partnerId,
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
    ): array {
        $fulfillmentType = BookingStatuses::normalizeFulfillmentType($fulfillmentType);
        $start = parse_date($startDate);
        $end = parse_date($endDate);
        if ($start === null || $end === null || $start > $end) {
            throw new BookingUnavailableException('Invalid dates.');
        }

        $ownerUnit = $this->partnerEquipment($partnerId);
        if ($ownerUnit === null) {
            throw new BookingUnavailableException('No Starlink unit is linked to your partner account.');
        }

        $totalDays = inclusive_day_count($start, $end);
        $minimumDays = (int) pricing_config('minimum_rental_days', 3);
        $maxDays = (int) pricing_config('max_self_serve_days', 30);
        if ($totalDays < $minimumDays) {
            throw new BookingUnavailableException("Minimum rental is {$minimumDays} days.");
        }
        if ($totalDays > $maxDays) {
            throw new BookingUnavailableException('Rentals over 30 days require admin approval.');
        }

        $ownerEquipmentId = (int) $ownerUnit['id'];
        $conflictDays = $this->countConflictDays($ownerEquipmentId, $start, $end);
        $usesOwnUnit = $conflictDays === 0;

        if ($usesOwnUnit) {
            if (!$this->isEquipmentFreeForRange($ownerEquipmentId, $start, $end)) {
                throw new BookingUnavailableException('Your unit is not available for the full trip.');
            }
            $assigned = $ownerUnit;
            $lenderPartnerId = null;
            $lenderPartnerName = null;
        } else {
            $assigned = $this->findSubstituteUnit($partnerId, $start, $end);
            if ($assigned === null) {
                throw new BookingUnavailableException(
                    'No partner substitute unit is available for your full trip. Try different dates.',
                );
            }
            $lenderPartnerId = (int) ($assigned['partner_id'] ?? 0);
            $lenderPartnerName = (string) ($assigned['partner_name'] ?? '');
        }

        $priceQuote = $this->pricing->quote($locationId, $fulfillmentType, $startDate, $endDate);
        $dailyRate = (int) $priceQuote['daily_rate_cents'];
        $borrowFee = $conflictDays * $dailyRate;

        $summary = $usesOwnUnit
            ? 'Your unit is free for this trip — no borrow fee.'
            : sprintf(
                'One unit (%s) for the full trip. Borrow fee: %d day(s) × %s = %s paid to %s.',
                (string) $assigned['nickname'],
                $conflictDays,
                PricingService::formatMoney($dailyRate),
                PricingService::formatMoney($borrowFee),
                $lenderPartnerName !== '' ? $lenderPartnerName : 'the lending partner',
            );

        return [
            'uses_own_unit' => $usesOwnUnit,
            'owner_equipment_id' => $ownerEquipmentId,
            'assigned_equipment_id' => (int) $assigned['id'],
            'assigned_equipment_nickname' => (string) $assigned['nickname'],
            'lender_partner_id' => $lenderPartnerId,
            'lender_partner_name' => $lenderPartnerName,
            'total_days' => $totalDays,
            'conflict_days' => $conflictDays,
            'daily_rate_cents' => $dailyRate,
            'borrow_fee_cents' => $borrowFee,
            'summary' => $summary,
        ];
    }

    /** @return array<string, mixed>|null */
    private function partnerEquipment(int $partnerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, p.name AS partner_name
             FROM equipment e
             LEFT JOIN partners p ON p.id = e.partner_id
             WHERE e.partner_id = :partner_id
             AND e.equipment_type = 'starlink'
             AND e.status != 'retired'
             ORDER BY e.id ASC
             LIMIT 1"
        );
        $stmt->execute(['partner_id' => $partnerId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function countConflictDays(int $ownerEquipmentId, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $conflictDays = 0;
        $cursor = $start;
        while ($cursor <= $end) {
            if ($this->hasActiveCustomerRentalOnUnit($ownerEquipmentId, $cursor)) {
                $conflictDays++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $conflictDays;
    }

    private function hasActiveCustomerRentalOnUnit(int $equipmentId, DateTimeImmutable $day): bool
    {
        $key = $day->format('Y-m-d');
        $blocking = implode("','", BookingStatuses::BLOCKING_BOOKING_STATUSES);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM bookings
             WHERE equipment_id = :equipment_id
             AND cancelled_at IS NULL
             AND booking_kind = :customer_kind
             AND booking_status IN ('{$blocking}')
             AND start_date <= :day
             AND end_date >= :day"
        );
        $stmt->execute([
            'equipment_id' => $equipmentId,
            'customer_kind' => BookingStatuses::BOOKING_KIND_CUSTOMER,
            'day' => $key,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function isEquipmentFreeForRange(int $equipmentId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $blocking = implode("','", BookingStatuses::BLOCKING_BOOKING_STATUSES);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM bookings
             WHERE equipment_id = :equipment_id
             AND cancelled_at IS NULL
             AND booking_status IN ('{$blocking}')
             AND start_date <= :end_date
             AND end_date >= :start_date"
        );
        $stmt->execute([
            'equipment_id' => $equipmentId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);

        return (int) $stmt->fetchColumn() === 0;
    }

    /** @return array<string, mixed>|null */
    private function findSubstituteUnit(int $requestingPartnerId, DateTimeImmutable $start, DateTimeImmutable $end): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, p.name AS partner_name
             FROM equipment e
             INNER JOIN partners p ON p.id = e.partner_id
             WHERE e.equipment_type = 'starlink'
             AND e.owner_type = 'partner'
             AND e.partner_id != :partner_id
             AND e.status = 'active'
             ORDER BY e.rental_priority ASC, e.id ASC"
        );
        $stmt->execute(['partner_id' => $requestingPartnerId]);

        while ($row = $stmt->fetch()) {
            if ($this->isEquipmentFreeForRange((int) $row['id'], $start, $end)) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>> */
    private function listBookings(string $where, array $params): array
    {
        $sql = "SELECT b.*, l.name AS location_name,
                       e.nickname AS equipment_name, owner_eq.nickname AS owner_equipment_name,
                       borrower.name AS borrower_name, lp.name AS lender_partner_name
                FROM bookings b
                INNER JOIN locations l ON l.id = b.location_id
                INNER JOIN equipment e ON e.id = b.equipment_id
                LEFT JOIN equipment owner_eq ON owner_eq.id = b.partner_owner_equipment_id
                INNER JOIN users borrower ON borrower.id = b.customer_id
                LEFT JOIN partners lp ON lp.id = e.partner_id
                WHERE b.booking_kind = :booking_kind
                AND {$where}
                ORDER BY b.start_date DESC, b.id DESC";

        $params['booking_kind'] = BookingStatuses::BOOKING_KIND_PARTNER_PERSONAL;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}

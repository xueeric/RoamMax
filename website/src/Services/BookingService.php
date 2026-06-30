<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Booking\BookingStatuses;
use Starlink\Database\Connection;

final class BookingService
{
    private readonly PDO $db;

    public function __construct(
        private readonly AvailabilityService $availability = new AvailabilityService(),
        private readonly PricingService $pricing = new PricingService(),
        private readonly StagingService $staging = new StagingService(),
        private readonly BookingStateService $state = new BookingStateService(),
        private readonly EquipmentService $equipment = new EquipmentService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    /**
     * @param array<int, int> $addOnQuantities
     * @param array<string, mixed> $shippingAddress
     * @return array<string, mixed>
     */
    public function createCustomerBooking(
        int $customerUserId,
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
        array $addOnQuantities = [],
        ?string $customerNotes = null,
        ?array $shippingAddress = null,
        bool $agreementAccepted = false,
        ?array $homeAddress = null,
        ?array $billingAddress = null,
        ?string $companyName = null,
        ?string $paymentMethod = null,
        ?string $pickupDate = null,
        ?string $pickupTimeStart = null,
        ?string $pickupTimeEnd = null,
        ?string $agreementIp = null,
        ?string $agreementVersion = null,
        ?string $agreementContentSnapshot = null,
    ): array {
        if (!$agreementAccepted) {
            throw new BookingUnavailableException('You must accept the rental agreement.');
        }

        $fulfillmentType = BookingStatuses::normalizeFulfillmentType($fulfillmentType);
        $fulfillmentType = $this->enforceFulfillmentForLocation($locationId, $fulfillmentType);

        if (!in_array($fulfillmentType, BookingStatuses::FULFILLMENT_TYPES, true)) {
            throw new BookingUnavailableException('Invalid fulfillment method.');
        }

        AddressService::validate($homeAddress ?? [], ['line1', 'city', 'province', 'postal_code'], 'home');
        AddressService::validate($billingAddress ?? [], ['line1', 'city', 'province', 'postal_code'], 'billing');

        if ($fulfillmentType === 'mail_ship') {
            AddressService::validate($shippingAddress ?? [], ['line1', 'city', 'province', 'postal_code'], 'shipping');
            if (trim((string) ($shippingAddress['name'] ?? '')) === '') {
                throw new BookingUnavailableException('Shipping recipient name is required.');
            }
        }

        if ($fulfillmentType === 'city_delivery') {
            AddressService::validate($shippingAddress ?? [], ['line1', 'city', 'province', 'postal_code'], 'delivery');
            if (trim((string) ($shippingAddress['name'] ?? '')) === '') {
                throw new BookingUnavailableException('Delivery recipient name is required.');
            }
        }

        $isHomeAppointment = $fulfillmentType === 'pickup_appointment';
        if ($isHomeAppointment) {
            $pickupDate = $startDate;
            $pickupTimeStart = trim((string) $pickupTimeStart);
            if ($pickupTimeStart === '') {
                throw new BookingUnavailableException('Choose a preferred pickup time.');
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $pickupTimeStart)) {
                throw new BookingUnavailableException('Invalid pickup time.');
            }
            $pickupTimeEnd = null;
        } else {
            $pickupDate = null;
            $pickupTimeStart = null;
            $pickupTimeEnd = null;
        }
        $assignLocationId = $fulfillmentType === 'mail_ship' ? 0 : $locationId;
        $taxProvince = $this->resolveTaxProvince($locationId, $fulfillmentType, $shippingAddress);

        $start = parse_date($startDate);
        $stagingDate = null;
        $status = 'pending_payment';
        $appointmentStatus = $isHomeAppointment ? 'appointment_awaiting_admin' : 'appointment_na';

        $paymentMethod = (new PaymentService())->normalizeMethod((string) ($paymentMethod ?? ''));
        if ($paymentMethod === null) {
            throw new BookingUnavailableException('Choose a payment method.');
        }
        $paymentStatus = $paymentMethod === 'etransfer' ? 'payment_pending_etransfer' : 'payment_pending_square';
        $quote = $this->pricing->quote(
            $locationId,
            $fulfillmentType,
            $startDate,
            $endDate,
            $addOnQuantities,
            false,
            null,
            null,
            $taxProvince,
            $isHomeAppointment ? true : null,
            $paymentMethod,
        );

        if (!$quote['tax_determined']) {
            throw new BookingUnavailableException('Tax could not be calculated. Check your shipping province.');
        }

        $referenceCode = (new BookingReferenceService())->generate($this->db);

        Connection::beginImmediate($this->db);
        try {
            if (!$this->availability->isRangeAvailable($assignLocationId, $fulfillmentType, $startDate, $endDate)) {
                throw new BookingUnavailableException('Selected dates are not available.');
            }

            $this->pricing->assertAddOnsStillAvailable(
                $locationId,
                $addOnQuantities,
                (int) $quote['days'],
                $startDate,
                $endDate,
                $quote['add_ons'],
            );

            $stmt = $this->db->prepare(
                'INSERT INTO bookings (
                    customer_id, equipment_id, location_id, fulfillment_type, shipping_address_json,
                    company_name, home_address_json, billing_address_json, payment_method, reference_code,
                    staging_date, start_date, end_date, daily_rate_cents, rental_total_cents,
                    shipping_fee_cents, deposit_cents, add_ons_total_cents, tax_cents, is_admin_created,
                    customer_notes, appointment_status, confirmed_pickup_date, confirmed_pickup_time_start,
                    confirmed_pickup_time_end, status, payment_status, booking_status, fulfillment_status,
                    agreement_accepted_at, agreement_ip, agreement_version, agreement_content_snapshot, created_at
                 ) VALUES (
                    :customer_id, :equipment_id, :location_id, :fulfillment_type, :shipping_address_json,
                    :company_name, :home_address_json, :billing_address_json, :payment_method, :reference_code,
                    :staging_date, :start_date, :end_date, :daily_rate_cents, :rental_total_cents,
                    :shipping_fee_cents, :deposit_cents, :add_ons_total_cents, :tax_cents, :is_admin_created,
                    :customer_notes, :appointment_status, :confirmed_pickup_date, :confirmed_pickup_time_start,
                    :confirmed_pickup_time_end, :status, :payment_status, :booking_status, :fulfillment_status,
                    :agreement_accepted_at, :agreement_ip, :agreement_version, :agreement_content_snapshot, :created_at
                 )'
            );
            $stmt->execute([
                'customer_id' => $customerUserId,
                'equipment_id' => null,
                'location_id' => $locationId,
                'fulfillment_type' => $fulfillmentType,
                'shipping_address_json' => $shippingAddress !== null ? json_encode(AddressService::normalize($shippingAddress), JSON_THROW_ON_ERROR) : null,
                'company_name' => $companyName !== null && trim($companyName) !== '' ? trim($companyName) : null,
                'home_address_json' => AddressService::encode($homeAddress),
                'billing_address_json' => AddressService::encode($billingAddress),
                'payment_method' => $paymentMethod,
                'reference_code' => $referenceCode,
                'staging_date' => $stagingDate,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_rate_cents' => $quote['daily_rate_cents'],
                'rental_total_cents' => $quote['rental_total_cents'],
                'shipping_fee_cents' => $quote['shipping_fee_cents'],
                'deposit_cents' => $quote['deposit_cents'],
                'add_ons_total_cents' => $quote['add_ons_total_cents'],
                'tax_cents' => $quote['tax_cents'],
                'is_admin_created' => 0,
                'customer_notes' => $customerNotes,
                'appointment_status' => $appointmentStatus,
                'confirmed_pickup_date' => $pickupDate,
                'confirmed_pickup_time_start' => $pickupTimeStart,
                'confirmed_pickup_time_end' => $pickupTimeEnd,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'booking_status' => 'booking_pending_payment',
                'fulfillment_status' => 'fulfillment_pending',
                'agreement_accepted_at' => now_utc(),
                'agreement_ip' => $agreementIp,
                'agreement_version' => $agreementVersion,
                'agreement_content_snapshot' => $agreementContentSnapshot,
                'created_at' => now_utc(),
            ]);

            $bookingId = (int) $this->db->lastInsertId();
            $this->persistAddOns($bookingId, $quote['add_ons']);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $created = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->dispatch('booking_created', $created);
        if ($fulfillmentType === 'pickup_appointment') {
            (new NotificationService())->dispatch('appointment_awaiting_admin', $created);
        }

        return $created;
    }

    /**
     * @param array<int, int> $addOnQuantities
     * @return array<string, mixed>
     */
    public function createAdminBooking(
        int $adminUserId,
        int $customerUserId,
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
        int $dailyRateCents,
        ?int $rentalTotalCents = null,
        array $addOnQuantities = [],
        ?string $customerNotes = null,
        ?array $shippingAddress = null,
    ): array {
        $fulfillmentType = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType($fulfillmentType);

        if (!$this->availability->isRangeAvailable($locationId, $fulfillmentType, $startDate, $endDate)) {
            throw new BookingUnavailableException('Selected dates are not available.');
        }

        $quote = $this->pricing->quote(
            $locationId,
            $fulfillmentType,
            $startDate,
            $endDate,
            $addOnQuantities,
            true,
            $dailyRateCents,
            $rentalTotalCents,
            $this->resolveTaxProvince($locationId, $fulfillmentType, $shippingAddress),
        );

        Connection::beginImmediate($this->db);
        try {
            if (!$this->availability->isRangeAvailable($locationId, $fulfillmentType, $startDate, $endDate)) {
                throw new BookingUnavailableException('Selected dates are not available.');
            }

            $this->pricing->assertAddOnsStillAvailable(
                $locationId,
                $addOnQuantities,
                (int) $quote['days'],
                $startDate,
                $endDate,
                $quote['add_ons'],
            );

            $referenceCode = (new BookingReferenceService())->generate($this->db);

            $stmt = $this->db->prepare(
                'INSERT INTO bookings (
                    customer_id, equipment_id, location_id, fulfillment_type, shipping_address_json,
                    reference_code, staging_date, start_date, end_date, daily_rate_cents, rental_total_cents,
                    shipping_fee_cents, deposit_cents, add_ons_total_cents, tax_cents, is_admin_created,
                    customer_notes, appointment_status, status, payment_status, booking_status, fulfillment_status, agreement_accepted_at, created_at
                 ) VALUES (
                    :customer_id, :equipment_id, :location_id, :fulfillment_type, :shipping_address_json,
                    :reference_code, :staging_date, :start_date, :end_date, :daily_rate_cents, :rental_total_cents,
                    :shipping_fee_cents, :deposit_cents, :add_ons_total_cents, :tax_cents, :is_admin_created,
                    :customer_notes, :appointment_status, :status, :payment_status, :booking_status, :fulfillment_status, :agreement_accepted_at, :created_at
                 )'
            );
            $stmt->execute([
                'customer_id' => $customerUserId,
                'equipment_id' => null,
                'location_id' => $locationId,
                'fulfillment_type' => $fulfillmentType,
                'shipping_address_json' => $shippingAddress !== null ? json_encode($shippingAddress, JSON_THROW_ON_ERROR) : null,
                'reference_code' => $referenceCode,
                'staging_date' => null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_rate_cents' => $quote['daily_rate_cents'],
                'rental_total_cents' => $quote['rental_total_cents'],
                'shipping_fee_cents' => $quote['shipping_fee_cents'],
                'deposit_cents' => $quote['deposit_cents'],
                'add_ons_total_cents' => $quote['add_ons_total_cents'],
                'tax_cents' => $quote['tax_cents'],
                'is_admin_created' => 1,
                'customer_notes' => $customerNotes,
                'appointment_status' => $fulfillmentType === 'pickup_appointment' ? 'appointment_confirmed' : 'appointment_na',
                'status' => 'pending_payment',
                'payment_status' => 'payment_pending_square',
                'booking_status' => 'booking_pending_payment',
                'fulfillment_status' => 'fulfillment_pending',
                'agreement_accepted_at' => now_utc(),
                'created_at' => now_utc(),
            ]);

            $bookingId = (int) $this->db->lastInsertId();
            $this->persistAddOns($bookingId, $quote['add_ons']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $created = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->dispatch('booking_admin_created', $created);

        return $created;
    }

    /**
     * Owner/partner personal use — same availability as customer booking, no payment.
     *
     * @return array<string, mixed>
     */
    public function createOwnerBlockBooking(
        int $requestingUserId,
        string $requestedBy,
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
        ?string $notes = null,
        bool $allowLongTerm = false,
    ): array {
        $fulfillmentType = BookingStatuses::normalizeFulfillmentType($fulfillmentType);
        $assignLocationId = $fulfillmentType === 'mail_ship' ? 0 : $locationId;

        if (!$this->availability->isRangeAvailable($assignLocationId, $fulfillmentType, $startDate, $endDate)) {
            throw new BookingUnavailableException('Selected dates are not available.');
        }

        $start = parse_date($startDate);
        $end = parse_date($endDate);
        if ($start === null || $end === null || $start > $end) {
            throw new BookingUnavailableException('Invalid dates.');
        }

        $days = inclusive_day_count($start, $end);
        $minimumDays = (int) pricing_config('minimum_rental_days', 3);
        $maxDays = (int) pricing_config('max_self_serve_days', 30);
        if ($days < $minimumDays) {
            throw new BookingUnavailableException("Minimum rental is {$minimumDays} days.");
        }
        if (!$allowLongTerm && $days > $maxDays) {
            throw new BookingUnavailableException('Rentals over 30 days require admin approval.');
        }

        $customerUserId = $this->ensureCustomerUserId($requestingUserId);

        $this->db->beginTransaction();
        try {
            $referenceCode = (new BookingReferenceService())->generate($this->db);
            $stmt = $this->db->prepare(
                'INSERT INTO bookings (
                    customer_id, equipment_id, location_id, fulfillment_type, shipping_address_json,
                    reference_code, staging_date, start_date, end_date, daily_rate_cents, rental_total_cents,
                    shipping_fee_cents, deposit_cents, add_ons_total_cents, tax_cents, is_admin_created,
                    booking_kind, owner_block_requested_by, customer_notes, appointment_status,
                    status, payment_status, booking_status, fulfillment_status, agreement_accepted_at, created_at
                 ) VALUES (
                    :customer_id, :equipment_id, :location_id, :fulfillment_type, :shipping_address_json,
                    :reference_code, :staging_date, :start_date, :end_date, :daily_rate_cents, :rental_total_cents,
                    :shipping_fee_cents, :deposit_cents, :add_ons_total_cents, :tax_cents, :is_admin_created,
                    :booking_kind, :owner_block_requested_by, :customer_notes, :appointment_status,
                    :status, :payment_status, :booking_status, :fulfillment_status, :agreement_accepted_at, :created_at
                 )'
            );
            $stmt->execute([
                'customer_id' => $customerUserId,
                'equipment_id' => null,
                'location_id' => $locationId,
                'fulfillment_type' => $fulfillmentType,
                'shipping_address_json' => null,
                'reference_code' => $referenceCode,
                'staging_date' => null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_rate_cents' => 0,
                'rental_total_cents' => 0,
                'shipping_fee_cents' => 0,
                'deposit_cents' => 0,
                'add_ons_total_cents' => 0,
                'tax_cents' => 0,
                'is_admin_created' => $requestedBy === 'admin' ? 1 : 0,
                'booking_kind' => BookingStatuses::BOOKING_KIND_OWNER_BLOCK,
                'owner_block_requested_by' => $requestedBy,
                'customer_notes' => $notes,
                'appointment_status' => $fulfillmentType === 'pickup_appointment' ? 'appointment_confirmed' : 'appointment_na',
                'status' => 'confirmed',
                'payment_status' => 'payment_waived',
                'booking_status' => 'booking_confirmed',
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

        return $this->findBooking($bookingId) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function listOwnerBlockBookings(?int $customerUserId = null): array
    {
        $sql = 'SELECT b.*, l.name AS location_name, e.nickname AS equipment_name, u.name AS customer_name, u.email AS customer_email
                FROM bookings b
                INNER JOIN locations l ON l.id = b.location_id
                LEFT JOIN equipment e ON e.id = b.equipment_id
                INNER JOIN users u ON u.id = b.customer_id
                WHERE b.booking_kind = :booking_kind';
        $params = ['booking_kind' => BookingStatuses::BOOKING_KIND_OWNER_BLOCK];
        if ($customerUserId !== null) {
            $sql .= ' AND b.customer_id = :customer_id';
            $params['customer_id'] = $customerUserId;
        }
        $sql .= ' ORDER BY b.start_date DESC, b.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row): array => $this->ensureBookingReference($row), $stmt->fetchAll());
    }

    public function ensureCustomerUserId(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT user_id FROM customers WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        if ($stmt->fetch() !== false) {
            return $userId;
        }

        $this->db->prepare('INSERT INTO customers (user_id) VALUES (:user_id)')->execute(['user_id' => $userId]);

        return $userId;
    }

    /**
     * Assign a unit to a booking (Path B — admin assigns before staging/pickup).
     *
     * @return array<string, mixed>
     */
    public function assignEquipment(int $bookingId, int $equipmentId, int $adminUserId): array
    {
        $booking = $this->requireBooking($bookingId);
        if (booking_lifecycle_status($booking) === 'booking_cancelled') {
            throw new BookingUnavailableException('Cannot assign equipment to a cancelled booking.');
        }

        $unit = $this->equipment->find($equipmentId);
        if ($unit === null) {
            throw new BookingUnavailableException('Equipment not found.');
        }
        if (($unit['equipment_type'] ?? EquipmentService::TYPE_STARLINK) !== EquipmentService::TYPE_STARLINK) {
            throw new BookingUnavailableException('Only Starlink units can be assigned to a booking.');
        }

        $fulfillmentType = BookingStatuses::normalizeFulfillmentType((string) $booking['fulfillment_type']);
        $locationId = (int) $booking['location_id'];
        if (!$this->availability->isUnitAvailableForBooking(
            $unit,
            $locationId,
            $fulfillmentType,
            (string) $booking['start_date'],
            (string) $booking['end_date'],
            $bookingId,
        )) {
            throw new BookingUnavailableException('That unit is not available for this booking.');
        }

        $start = parse_date((string) $booking['start_date']);
        $stagingDate = $start !== null
            ? $this->staging->stagingDateForStart($unit, $locationId, $fulfillmentType, $start)?->format('Y-m-d')
            : null;

        $this->db->beginTransaction();
        try {
            $this->staging->cancelForBooking($bookingId);

            $this->db->prepare(
                'UPDATE bookings SET equipment_id = :equipment_id, staging_date = :staging_date WHERE id = :id'
            )->execute([
                'equipment_id' => $equipmentId,
                'staging_date' => $stagingDate,
                'id' => $bookingId,
            ]);

            if ($stagingDate !== null) {
                $this->staging->scheduleForBooking(
                    $bookingId,
                    $unit,
                    $locationId,
                    (string) $booking['start_date'],
                    $fulfillmentType,
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->findBooking($bookingId) ?? [];
    }

    public function confirmAppointment(
        int $bookingId,
        string $messageStyle,
        ?string $customMessage = null,
    ): array {
        $booking = $this->requireBooking($bookingId);
        if (booking_lifecycle_status($booking) === 'booking_cancelled') {
            throw new BookingUnavailableException('This booking was cancelled.');
        }
        if ($booking['fulfillment_type'] !== 'pickup_appointment' || !in_array($booking['appointment_status'], ['appointment_awaiting_admin', 'awaiting_admin'], true)) {
            throw new BookingUnavailableException('This booking cannot be confirmed.');
        }

        $pickupDate = trim((string) ($booking['confirmed_pickup_date'] ?? ''));
        if ($pickupDate === '') {
            throw new BookingUnavailableException('Customer has not chosen a pickup window yet.');
        }

        $adminMessage = match ($messageStyle) {
            'leave_at_door' => 'If you are not home when we arrive, we will leave your Starlink Mini at your front door.',
            default => 'Looking forward to seeing you! Just knock on the door when you arrive.',
        };
        $customMessage = trim((string) $customMessage);
        if ($customMessage !== '') {
            $adminMessage .= ' ' . $customMessage;
        }

        $stmt = $this->db->prepare(
            'UPDATE bookings SET
                appointment_status = :appointment_status,
                admin_pickup_message = :admin_pickup_message,
                appointment_confirmed_at = :appointment_confirmed_at
             WHERE id = :id'
        );
        $stmt->execute([
            'appointment_status' => 'appointment_confirmed',
            'admin_pickup_message' => $adminMessage,
            'appointment_confirmed_at' => now_utc(),
            'id' => $bookingId,
        ]);

        $updated = $this->findBooking($bookingId) ?? [];
        $timeStart = trim((string) ($booking['confirmed_pickup_time_start'] ?? ''));
        $timeEnd = trim((string) ($booking['confirmed_pickup_time_end'] ?? ''));
        $message = format_pickup_window_phrase($pickupDate, $timeStart, $timeEnd) . ' ' . $adminMessage;
        (new NotificationService())->appointmentUpdate($updated, trim($message));

        return $updated;
    }

    public function proposeAppointmentWindow(
        int $bookingId,
        string $pickupDate,
        string $timeStart,
        string $timeEnd,
    ): array {
        $booking = $this->requireBooking($bookingId);
        if ($booking['fulfillment_type'] !== 'pickup_appointment' || !in_array($booking['appointment_status'], ['appointment_awaiting_admin', 'awaiting_admin'], true)) {
            throw new BookingUnavailableException('This booking cannot be updated with a proposal.');
        }

        $stmt = $this->db->prepare(
            'UPDATE bookings SET
                appointment_status = :appointment_status,
                proposed_pickup_date = :proposed_pickup_date,
                proposed_pickup_time_start = :proposed_pickup_time_start,
                proposed_pickup_time_end = :proposed_pickup_time_end,
                proposed_at = :proposed_at
             WHERE id = :id'
        );
        $stmt->execute([
            'appointment_status' => 'appointment_proposed',
            'proposed_pickup_date' => $pickupDate,
            'proposed_pickup_time_start' => $timeStart,
            'proposed_pickup_time_end' => $timeEnd,
            'proposed_at' => now_utc(),
            'id' => $bookingId,
        ]);

        $updated = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->appointmentUpdate(
            $updated,
            'We proposed a pickup window on ' . $pickupDate . ' from ' . $timeStart . ' to ' . $timeEnd
            . '. Accept it from your bookings page when it works for you.',
        );

        return $updated;
    }

    public function acceptProposal(int $bookingId, int $customerUserId): array
    {
        $booking = $this->requireBooking($bookingId);
        if ((int) $booking['customer_id'] !== $customerUserId) {
            throw new BookingUnavailableException('Booking not found.');
        }
        if (!in_array($booking['appointment_status'], ['appointment_proposed', 'proposed'], true)) {
            throw new BookingUnavailableException('No proposal is waiting for acceptance.');
        }

        $stmt = $this->db->prepare(
            'UPDATE bookings SET
                appointment_status = :appointment_status,
                confirmed_pickup_date = :confirmed_pickup_date,
                confirmed_pickup_time_start = :confirmed_pickup_time_start,
                confirmed_pickup_time_end = :confirmed_pickup_time_end,
                appointment_confirmed_at = :appointment_confirmed_at
             WHERE id = :id'
        );
        $stmt->execute([
            'appointment_status' => 'appointment_confirmed',
            'confirmed_pickup_date' => $booking['proposed_pickup_date'],
            'confirmed_pickup_time_start' => $booking['proposed_pickup_time_start'],
            'confirmed_pickup_time_end' => $booking['proposed_pickup_time_end'],
            'appointment_confirmed_at' => now_utc(),
            'id' => $bookingId,
        ]);

        $updated = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->appointmentUpdate(
            $updated,
            'Pickup window accepted for ' . ($booking['proposed_pickup_date'] ?? '') . '.',
        );

        return $updated;
    }

    public function confirmEtransfer(int $bookingId, int $amountCents): array
    {
        $booking = $this->requireBooking($bookingId);
        if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
            throw new BookingUnavailableException('This booking is not awaiting e-Transfer confirmation.');
        }
        if (($booking['payment_method'] ?? '') !== 'etransfer') {
            throw new BookingUnavailableException('This booking is not using Interac e-Transfer.');
        }

        $totalDue = $this->totalDueCents($booking);
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE bookings SET etransfer_confirmed_amount_cents = :amount WHERE id = :id'
            )->execute(['amount' => $amountCents, 'id' => $bookingId]);

            if ($amountCents < $totalDue) {
                $this->state->apply($bookingId, [
                    'payment_status' => 'payment_etransfer_partial_confirmed',
                    'booking_status' => 'booking_pending_payment',
                ]);
                $this->db->commit();
                $updated = $this->findBooking($bookingId) ?? [];
                $balance = PricingService::formatMoney($totalDue - $amountCents);
                (new NotificationService())->dispatch('payment_etransfer_partial_confirmed', $updated, [
                    'amount' => PricingService::formatMoney($amountCents),
                    'balance_message' => 'Please send the remaining ' . $balance . ' to complete your booking.',
                ]);

                return $updated;
            }

            $this->state->apply($bookingId, [
                'payment_status' => 'payment_etransfer_confirmed',
                'booking_status' => 'booking_confirmed',
            ]);
            $this->db->prepare(
                'UPDATE bookings SET square_payment_id = :ref WHERE id = :id'
            )->execute(['ref' => 'etransfer_' . $bookingId, 'id' => $bookingId]);

            $this->insertCheckoutPayment($bookingId, $booking, $amountCents, 'etransfer', 'Interac e-Transfer received.');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $updated = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->dispatch('payment_etransfer_confirmed', $updated);

        return $updated;
    }

    public function markPaid(
        int $bookingId,
        string $paymentReference,
        ?string $squareOrderId = null,
        string $paymentMethod = 'square',
        ?string $notes = null,
    ): array {
        $booking = $this->requireBooking($bookingId);
        if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
            return $booking;
        }

        $method = (string) ($booking['payment_method'] ?? $paymentMethod);
        if ($method === 'etransfer') {
            return $this->confirmEtransfer($bookingId, $this->totalDueCents($booking));
        }

        return $this->applyMockSquarePayment($bookingId, $paymentReference);
    }

    /** @return array<string, mixed> */
    public function applyMockSquarePayment(int $bookingId, string $paymentReference): array
    {
        $booking = $this->requireBooking($bookingId);
        if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
            return $booking;
        }
        if (($booking['payment_method'] ?? '') !== 'square') {
            throw new BookingUnavailableException('This booking is not using card payment.');
        }

        if ($this->isShortTermRental($booking)) {
            $this->db->prepare(
                'UPDATE bookings SET square_payment_id = :square_payment_id, square_payment_flow = :square_payment_flow, payment_method = :payment_method WHERE id = :id'
            )->execute([
                'square_payment_id' => $paymentReference,
                'square_payment_flow' => 'short_term_auth',
                'payment_method' => 'square',
                'id' => $bookingId,
            ]);
            $this->insertCheckoutPayment(
                $bookingId,
                $booking,
                $this->rentalChargeCents($booking),
                'square',
                'Mock short-term rental charge.',
            );
            $this->state->apply($bookingId, [
                'payment_status' => 'payment_square_rental_captured',
                'booking_status' => 'booking_confirmed',
            ]);
            $updated = $this->findBooking($bookingId) ?? $booking;
            (new NotificationService())->bookingDepositPending($updated);

            return $updated;
        }

        $this->db->prepare(
            'UPDATE bookings SET square_payment_id = :square_payment_id, square_payment_flow = :square_payment_flow, payment_method = :payment_method WHERE id = :id'
        )->execute([
            'square_payment_id' => $paymentReference,
            'square_payment_flow' => 'long_term_capture',
            'payment_method' => 'square',
            'id' => $bookingId,
        ]);
        $this->insertCheckoutPayment(
            $bookingId,
            $booking,
            $this->totalDueCents($booking),
            'square',
            'Mock long-term checkout.',
        );
        $this->state->apply($bookingId, [
            'payment_status' => 'payment_square_full_captured',
            'booking_status' => 'booking_confirmed',
        ]);
        $updated = $this->findBooking($bookingId) ?? $booking;
        (new NotificationService())->bookingConfirmed($updated);

        return $updated;
    }

    /** @param array<string, mixed> $booking */
    private function insertCheckoutPayment(
        int $bookingId,
        array $booking,
        int $amountCents,
        string $paymentMethod,
        string $notes,
    ): void {
        $this->db->prepare(
            'INSERT INTO payments (
                booking_id, equipment_id, type, amount_cents, square_payment_id, status, notes, created_at
             ) VALUES (
                :booking_id, :equipment_id, :type, :amount_cents, :square_payment_id, :status, :notes, :created_at
             )'
        )->execute([
            'booking_id' => $bookingId,
            'equipment_id' => $booking['equipment_id'],
            'type' => 'checkout',
            'amount_cents' => $amountCents,
            'square_payment_id' => $paymentMethod === 'square' ? 'checkout_' . $bookingId : null,
            'status' => 'completed',
            'notes' => $notes,
            'created_at' => now_utc(),
        ]);
    }

    /** Reset a paid booking so the customer can complete real Square/e-Transfer payment again. */
    public function resetPaymentForRepay(int $bookingId, int $adminUserId): array
    {
        $booking = $this->requireBooking($bookingId);
        $lifecycle = booking_lifecycle_status($booking);

        if (in_array($lifecycle, ['booking_cancelled', 'booking_closed', 'booking_active', 'booking_late'], true)) {
            throw new BookingUnavailableException('Cannot reset payment for this booking status.');
        }

        if (booking_fulfillment_status($booking) !== 'fulfillment_pending') {
            throw new BookingUnavailableException('Cannot reset payment after staging or handout.');
        }

        $ledger = new BookingPaymentLedgerService();
        if (!$ledger->hasPaymentMismatch($booking)) {
            throw new BookingUnavailableException('Payment reset is only when Square verification fails or IDs are test/local only.');
        }

        $depositHoldId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        if ($depositHoldId !== '' && !$this->square->isLocalPaymentId($depositHoldId)) {
            $cancel = (new SquareService())->cancelPayment($depositHoldId);
            if (!$cancel['ok']) {
                throw new BookingUnavailableException($cancel['message'] ?? 'Could not release deposit hold in Square.');
            }
        }

        $method = booking_resolved_payment_context($booking)['method'];
        if ($method === '') {
            $method = 'square';
        }

        $paymentStatus = $method === 'etransfer' ? 'payment_pending_etransfer' : 'payment_pending_square';

        $this->state->apply($bookingId, [
            'booking_status' => 'booking_pending_payment',
            'payment_status' => $paymentStatus,
            'fulfillment_status' => 'fulfillment_pending',
        ]);

        $this->db->prepare(
            'UPDATE bookings SET
                payment_method = :payment_method,
                square_payment_id = NULL,
                square_deposit_payment_id = NULL,
                square_customer_id = NULL,
                square_card_id = NULL,
                square_payment_flow = NULL,
                deposit_auth_at = NULL,
                etransfer_notified_at = NULL,
                etransfer_confirmed_amount_cents = NULL
             WHERE id = :id'
        )->execute([
            'payment_method' => $method,
            'id' => $bookingId,
        ]);

        $reversal = $this->ledgerReversalTotalsBeforeReset($bookingId, $booking);
        if ($reversal['checkout_cents'] > 0) {
            $this->recordLedgerEntry(
                $bookingId,
                $booking,
                'checkout',
                $reversal['checkout_cents'],
                'reversed',
                null,
                'Admin reset payment — customer must pay again (admin #' . $adminUserId . ').',
            );
        }
        if ($reversal['deposit_cents'] > 0) {
            $this->recordLedgerEntry(
                $bookingId,
                $booking,
                'deposit_refund',
                $reversal['deposit_cents'],
                'completed',
                null,
                'Deposit hold cleared on payment reset (admin #' . $adminUserId . ').',
            );
        }

        $ledger->clearVerificationsForBooking($bookingId);

        return $this->findBooking($bookingId) ?? [];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{checkout_cents: int, deposit_cents: int}
     */
    private function ledgerReversalTotalsBeforeReset(int $bookingId, array $booking): array
    {
        $checkoutCents = 0;
        $depositCents = 0;

        foreach ($this->listBookingPayments($bookingId) as $row) {
            if ((string) ($row['status'] ?? '') !== 'completed') {
                continue;
            }
            $amount = (int) ($row['amount_cents'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            if (in_array($type, ['checkout', 'rental'], true)) {
                $checkoutCents += $amount;
            } elseif ($type === 'deposit') {
                $depositCents += $amount;
            }
        }

        if ($checkoutCents <= 0) {
            $flow = trim((string) ($booking['square_payment_flow'] ?? ''));
            $checkoutCents = $flow === 'long_term_capture'
                ? $this->totalDueCents($booking)
                : $this->rentalChargeCents($booking);
        }

        return [
            'checkout_cents' => max(0, $checkoutCents),
            'deposit_cents' => max(0, $depositCents),
        ];
    }

    public function setPaymentMethod(int $bookingId, int $customerUserId, string $method): array
    {
        $booking = $this->requireBooking($bookingId);
        if ((int) $booking['customer_id'] !== $customerUserId) {
            throw new BookingUnavailableException('Booking not found.');
        }
        if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
            throw new BookingUnavailableException('This booking is not awaiting payment.');
        }

        $method = (new PaymentService())->normalizeMethod($method);
        if ($method === null) {
            throw new BookingUnavailableException('Choose a valid payment method.');
        }

        $paymentStatus = $method === 'etransfer' ? 'payment_pending_etransfer' : 'payment_pending_square';
        $this->db->prepare(
            'UPDATE bookings SET payment_method = :payment_method WHERE id = :id'
        )->execute([
            'payment_method' => $method,
            'id' => $bookingId,
        ]);
        $this->state->apply($bookingId, ['payment_status' => $paymentStatus]);

        return $this->findBooking($bookingId) ?? [];
    }

    public function acknowledgeEtransfer(int $bookingId, int $customerUserId): array
    {
        $booking = $this->requireBooking($bookingId);
        if ((int) $booking['customer_id'] !== $customerUserId) {
            throw new BookingUnavailableException('Booking not found.');
        }
        if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
            throw new BookingUnavailableException('This booking is not awaiting payment.');
        }
        if (($booking['payment_method'] ?? '') !== 'etransfer') {
            throw new BookingUnavailableException('This booking is not using Interac e-Transfer.');
        }

        $this->db->prepare('UPDATE bookings SET etransfer_notified_at = :notified_at WHERE id = :id')->execute([
            'notified_at' => now_utc(),
            'id' => $bookingId,
        ]);

        $this->state->apply($bookingId, ['payment_status' => 'payment_etransfer_sent']);
        $updated = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->dispatch('payment_etransfer_sent', $updated);

        return $updated;
    }

    /**
     * @return array{
     *   free_cancel: bool,
     *   fee_cents: int,
     *   refund_cents: int,
     *   rental_cents: int,
     *   deposit_cents: int,
     *   requires_admin_approval: bool
     * }
     */
    public function estimateCancellation(array $booking): array
    {
        $start = parse_date((string) ($booking['start_date'] ?? ''));
        if ($start === null) {
            throw new BookingUnavailableException('Invalid booking dates.');
        }

        $hoursUntil = ($start->getTimestamp() - time()) / 3600;
        $freeCancel = $hoursUntil >= (int) config('cancellation_free_hours', 72);
        $feeCents = $freeCancel ? 0 : min(
            (int) $booking['rental_total_cents'],
            (int) $booking['daily_rate_cents'] * (int) pricing_config('minimum_rental_days', 3),
        );
        $rentalCents = $this->rentalChargeCents($booking);
        $requiresAdmin = $this->requiresAdminCancellationApproval($booking);
        $refundCents = $requiresAdmin ? max(0, $rentalCents - $feeCents) : 0;

        return [
            'free_cancel' => $freeCancel,
            'fee_cents' => $feeCents,
            'refund_cents' => $refundCents,
            'rental_cents' => $rentalCents,
            'deposit_cents' => (int) ($booking['deposit_cents'] ?? 0),
            'requires_admin_approval' => $requiresAdmin,
        ];
    }

    /** Customer requests cancel (paid bookings wait for admin). Admin cancel is immediate. */
    public function requestCancellation(int $bookingId, int $actorUserId, ?string $reason = null): array
    {
        $booking = $this->requireBooking($bookingId);
        if ((int) $booking['customer_id'] !== $actorUserId) {
            throw new BookingUnavailableException('Booking not found.');
        }

        if (!$this->state->canCustomerCancel($booking)) {
            throw new BookingUnavailableException('This booking cannot be cancelled online. Contact support.');
        }

        $estimate = $this->estimateCancellation($booking);
        if (!$estimate['requires_admin_approval']) {
            return $this->finalizeCancellation($bookingId, $actorUserId, false, $reason, $estimate['fee_cents']);
        }

        if (booking_lifecycle_status($booking) === 'booking_cancellation_pending') {
            throw new BookingUnavailableException('Cancellation is already awaiting admin approval.');
        }

        $this->state->apply($bookingId, ['booking_status' => 'booking_cancellation_pending']);
        $this->db->prepare(
            'UPDATE bookings SET
                cancellation_requested_at = :requested_at,
                cancellation_fee_cents = :fee,
                cancellation_refund_cents = :refund,
                cancellation_reason = :reason
             WHERE id = :id'
        )->execute([
            'requested_at' => now_utc(),
            'fee' => $estimate['fee_cents'],
            'refund' => $estimate['refund_cents'],
            'reason' => $reason,
            'id' => $bookingId,
        ]);

        $updated = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->dispatch('booking_cancellation_requested', $updated, [
            'refund' => PricingService::formatMoney($estimate['refund_cents']),
            'fee' => PricingService::formatMoney($estimate['fee_cents']),
        ]);

        return $updated;
    }

    public function approveCancellation(
        int $bookingId,
        int $adminUserId,
        ?string $adminNote = null,
        ?int $refundCentsOverride = null,
        ?bool $releaseDeposit = true,
    ): array {
        $booking = $this->requireBooking($bookingId);
        if (booking_lifecycle_status($booking) !== 'booking_cancellation_pending') {
            throw new BookingUnavailableException('No pending cancellation request for this booking.');
        }

        $reason = trim((string) ($booking['cancellation_reason'] ?? ''));
        if ($adminNote !== null && trim($adminNote) !== '') {
            $reason = $reason !== '' ? $reason . ' — Admin: ' . $adminNote : 'Admin: ' . $adminNote;
        }

        $feeCents = (int) ($booking['cancellation_fee_cents'] ?? 0);
        $refundCents = $refundCentsOverride ?? (int) ($booking['cancellation_refund_cents'] ?? 0);

        return $this->finalizeCancellation(
            $bookingId,
            $adminUserId,
            true,
            $reason !== '' ? $reason : null,
            $feeCents,
            $refundCents,
            $releaseDeposit,
        );
    }

    /**
     * @param array{refund_cents?: ?int, release_deposit?: ?bool, fee_cents?: ?int} $options
     */
    public function adminCancelWithOptions(int $bookingId, int $adminUserId, ?string $reason, array $options = []): array
    {
        $booking = $this->requireBooking($bookingId);
        $lifecycle = booking_lifecycle_status($booking);

        if (in_array($lifecycle, ['booking_cancelled', 'booking_closed'], true)) {
            throw new BookingUnavailableException('Booking is already closed.');
        }

        if ($lifecycle === 'booking_cancellation_pending') {
            return $this->approveCancellation(
                $bookingId,
                $adminUserId,
                $reason,
                $options['refund_cents'] ?? null,
                $options['release_deposit'] ?? true,
            );
        }

        $estimate = $this->estimateCancellation($booking);
        $feeCents = isset($options['fee_cents']) ? (int) $options['fee_cents'] : $estimate['fee_cents'];

        return $this->finalizeCancellation(
            $bookingId,
            $adminUserId,
            true,
            $reason,
            $feeCents,
            $options['refund_cents'] ?? null,
            $options['release_deposit'] ?? true,
        );
    }

    public function rejectCancellation(int $bookingId, int $adminUserId, ?string $adminNote = null): array
    {
        $booking = $this->requireBooking($bookingId);
        if (booking_lifecycle_status($booking) !== 'booking_cancellation_pending') {
            throw new BookingUnavailableException('No pending cancellation request for this booking.');
        }

        $paymentStatus = $this->paymentStatusAfterRejectingCancellation($booking);

        $this->state->apply($bookingId, [
            'booking_status' => 'booking_confirmed',
            'payment_status' => $paymentStatus,
        ]);

        $this->db->prepare(
            'UPDATE bookings SET
                cancellation_requested_at = NULL,
                cancellation_refund_cents = NULL,
                cancellation_fee_cents = NULL,
                cancellation_reason = :reason
             WHERE id = :id'
        )->execute([
            'reason' => $adminNote,
            'id' => $bookingId,
        ]);

        $updated = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->dispatch('booking_cancellation_rejected', $updated, [
            'reason' => (string) ($adminNote ?? ''),
        ]);

        return $updated;
    }

    /** Admin immediate cancel (bypasses approval queue). */
    public function cancel(int $bookingId, int $actorUserId, bool $byAdmin = false, ?string $reason = null): array
    {
        $booking = $this->requireBooking($bookingId);
        $lifecycle = booking_lifecycle_status($booking);

        if (in_array($lifecycle, ['booking_cancelled', 'booking_closed'], true)) {
            throw new BookingUnavailableException('Booking is already closed.');
        }

        if (!$byAdmin && (int) $booking['customer_id'] !== $actorUserId) {
            throw new BookingUnavailableException('Booking not found.');
        }

        if (!$byAdmin) {
            return $this->requestCancellation($bookingId, $actorUserId, $reason);
        }

        if ($lifecycle === 'booking_cancellation_pending') {
            return $this->approveCancellation($bookingId, $actorUserId, $reason);
        }

        return $this->adminCancelWithOptions($bookingId, $actorUserId, $reason);
    }

    private function finalizeCancellation(
        int $bookingId,
        int $actorUserId,
        bool $byAdmin,
        ?string $reason,
        int $cancellationFee,
        ?int $refundCentsOverride = null,
        ?bool $releaseDeposit = null,
    ): array {
        $booking = $this->requireBooking($bookingId);
        $lifecycle = booking_lifecycle_status($booking);

        if (in_array($lifecycle, ['booking_cancelled', 'booking_closed'], true)) {
            throw new BookingUnavailableException('Booking is already closed.');
        }

        $freeCancel = $cancellationFee === 0;
        $releaseDeposit = $releaseDeposit ?? true;

        $this->db->beginTransaction();
        try {
            $this->processPaymentReversalOnCancel($booking, $cancellationFee, $refundCentsOverride, $releaseDeposit);

            $this->state->apply($bookingId, [
                'booking_status' => 'booking_cancelled',
                'payment_status' => ($booking['payment_method'] ?? '') === 'etransfer'
                    ? 'payment_etransfer_refunded'
                    : 'payment_square_refunded',
                'fulfillment_status' => 'fulfillment_pending',
            ]);

            $this->db->prepare(
                'UPDATE bookings SET
                    cancelled_at = :cancelled_at,
                    cancellation_fee_cents = :fee,
                    cancellation_reason = :reason,
                    cancellation_requested_at = NULL,
                    cancellation_refund_cents = NULL,
                    equipment_id = NULL,
                    appointment_status = :appointment_status
                 WHERE id = :id'
            )->execute([
                'cancelled_at' => now_utc(),
                'fee' => $cancellationFee,
                'reason' => $reason,
                'appointment_status' => 'appointment_na',
                'id' => $bookingId,
            ]);

            if ($cancellationFee > 0) {
                $this->db->prepare(
                    'INSERT INTO payments (booking_id, equipment_id, type, amount_cents, status, notes, created_at)
                     VALUES (:booking_id, :equipment_id, :type, :amount_cents, :status, :notes, :created_at)'
                )->execute([
                    'booking_id' => $bookingId,
                    'equipment_id' => $booking['equipment_id'],
                    'type' => 'cancellation_fee',
                    'amount_cents' => $cancellationFee,
                    'status' => 'completed',
                    'notes' => 'Late cancellation fee retained.',
                    'created_at' => now_utc(),
                ]);
            }

            $this->releaseBookingAddOns($bookingId);
            $this->staging->cancelForBooking($bookingId);
            $this->releaseEquipmentOnCancel($booking);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $updated = $this->findBooking($bookingId) ?? [];
        $message = $reason ?? ($freeCancel ? 'Your booking was cancelled.' : 'Late cancellation fee applied.');
        (new NotificationService())->bookingCancelled($updated, $message, $byAdmin);
        (new NotificationService())->dispatch('payment_refunded', $updated);

        return $updated;
    }

    /** @param array<string, mixed> $booking */
    private function requiresAdminCancellationApproval(array $booking): bool
    {
        if (booking_lifecycle_status($booking) === 'booking_pending_payment') {
            return false;
        }

        return in_array(booking_payment_status($booking), [
            'payment_square_rental_captured',
            'payment_square_full_captured',
            'payment_etransfer_confirmed',
            'payment_etransfer_partial_confirmed',
            'payment_deposit_scheduled',
            'payment_deposit_scheduled_processed',
            'payment_deposit_scheduled_failed',
        ], true);
    }

    /** @param array<string, mixed> $booking */
    private function paymentStatusAfterRejectingCancellation(array $booking): string
    {
        $flow = (string) ($booking['square_payment_flow'] ?? '');
        if ($flow === 'short_term_auth') {
            return trim((string) ($booking['square_deposit_payment_id'] ?? '')) !== ''
                ? 'payment_deposit_scheduled_processed'
                : 'payment_square_rental_captured';
        }

        if ($flow === 'long_term_capture') {
            return 'payment_square_full_captured';
        }

        if (($booking['payment_method'] ?? '') === 'etransfer') {
            return 'payment_etransfer_confirmed';
        }

        return booking_payment_status($booking);
    }

    /** @param array<string, mixed> $booking */
    private function processPaymentReversalOnCancel(
        array $booking,
        int $cancellationFee,
        ?int $refundCentsOverride,
        bool $releaseDeposit,
    ): void {
        $square = new SquareService();
        $bookingId = (int) $booking['id'];
        $paymentId = trim((string) ($booking['square_payment_id'] ?? ''));
        $depositHoldId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        $resolved = booking_resolved_payment_context($booking);
        $method = $resolved['method'];

        if ($releaseDeposit && $depositHoldId !== '') {
            if (!$square->isLocalPaymentId($depositHoldId)) {
                $cancel = $square->cancelPayment($depositHoldId);
                if (!$cancel['ok']) {
                    throw new BookingUnavailableException($cancel['message'] ?? 'Unable to release deposit hold.');
                }
            }

            $this->recordLedgerEntry(
                $bookingId,
                $booking,
                'deposit_refund',
                (int) $booking['deposit_cents'],
                'refunded',
                $depositHoldId,
                'Deposit hold released on cancellation.',
            );
        }

        if ($method === 'etransfer' || str_starts_with($paymentId, 'etransfer_')) {
            $maxRefund = max(0, $this->rentalChargeCents($booking) - $cancellationFee);
            $refundCents = $refundCentsOverride ?? $maxRefund;
            $refundCents = min(max(0, $refundCents), $maxRefund);
            if ($refundCents > 0) {
                $this->recordLedgerEntry(
                    $bookingId,
                    $booking,
                    'deposit_refund',
                    $refundCents,
                    'refunded',
                    null,
                    'e-Transfer refund recorded (process manually).',
                );
            }

            return;
        }

        if (booking_payment_status($booking) === 'payment_etransfer_partial_confirmed') {
            return;
        }

        $maxRefund = max(0, $this->rentalChargeCents($booking) - $cancellationFee);
        $refundCents = $refundCentsOverride ?? $maxRefund;
        $refundCents = min(max(0, $refundCents), $maxRefund);

        if ($refundCents <= 0 || $paymentId === '') {
            return;
        }

        if (!$square->isLocalPaymentId($paymentId)) {
            $refund = $square->refundPayment($paymentId, $refundCents, 'booking:' . $bookingId . ' cancel');
            if (!$refund['ok']) {
                throw new BookingUnavailableException($refund['message'] ?? 'Square refund failed.');
            }

            $refundId = trim((string) ($refund['refund_id'] ?? ''));
            $note = 'Rental refund on cancellation.';
            if ($refundId !== '') {
                $note = 'Rental refund on cancellation · Square refund ' . $refundId . '.';
            }

            $this->recordLedgerEntry(
                $bookingId,
                $booking,
                'checkout',
                $refundCents,
                'refunded',
                $paymentId,
                $note,
            );

            return;
        }

        $this->recordLedgerEntry(
            $bookingId,
            $booking,
            'deposit_refund',
            $refundCents,
            'refunded',
            $paymentId,
            'Local/test booking — refund recorded in ledger only.',
        );
    }

    /** @param array<string, mixed> $booking */
    private function recordLedgerEntry(
        int $bookingId,
        array $booking,
        string $type,
        int $amountCents,
        string $status,
        ?string $squarePaymentId,
        string $notes,
    ): void {
        $this->db->prepare(
            'INSERT INTO payments (booking_id, equipment_id, type, amount_cents, square_payment_id, status, notes, created_at)
             VALUES (:booking_id, :equipment_id, :type, :amount_cents, :square_payment_id, :status, :notes, :created_at)'
        )->execute([
            'booking_id' => $bookingId,
            'equipment_id' => $booking['equipment_id'],
            'type' => $type,
            'amount_cents' => $amountCents,
            'square_payment_id' => $squarePaymentId,
            'status' => $status,
            'notes' => $notes,
            'created_at' => now_utc(),
        ]);
    }

    public function applyLateFees(int $bookingId): array
    {
        $booking = $this->requireBooking($bookingId);
        if (!in_array(booking_lifecycle_status($booking), ['booking_active', 'booking_late'], true)) {
            throw new BookingUnavailableException('Late fees apply only after dispatch.');
        }

        $end = parse_date((string) $booking['end_date']);
        if ($end === null) {
            throw new BookingUnavailableException('Invalid end date.');
        }

        $today = today_date();
        if ($today <= $end) {
            throw new BookingUnavailableException('Rental is not overdue yet.');
        }

        $daysLate = inclusive_day_count($end->modify('+1 day'), $today);
        $feeCents = $daysLate * (int) config('late_fee_cents_per_day', 5000);

        $existing = $this->db->prepare(
            "SELECT COUNT(*) FROM payments
             WHERE booking_id = :booking_id
             AND type = 'late_fee'
             AND created_at >= :since"
        );
        $existing->execute([
            'booking_id' => $bookingId,
            'since' => $today->format('Y-m-d') . 'T00:00:00Z',
        ]);
        if ((int) $existing->fetchColumn() > 0) {
            return $this->findBooking($bookingId) ?? $booking;
        }

        $this->state->apply($bookingId, ['booking_status' => 'booking_late']);

        $this->db->prepare(
            'INSERT INTO payments (booking_id, equipment_id, type, amount_cents, status, notes, created_at)
             VALUES (:booking_id, :equipment_id, :type, :amount_cents, :status, :notes, :created_at)'
        )->execute([
            'booking_id' => $bookingId,
            'equipment_id' => $booking['equipment_id'],
            'type' => 'late_fee',
            'amount_cents' => $feeCents,
            'status' => 'completed',
            'notes' => $daysLate . ' day(s) late @ ' . PricingService::formatMoney((int) config('late_fee_cents_per_day', 5000)),
            'created_at' => now_utc(),
        ]);

        $updated = $this->findBooking($bookingId) ?? [];
        (new NotificationService())->dispatch('payment_late_fee_charged', $updated, [
            'amount' => PricingService::formatMoney($feeCents),
        ]);

        return $updated;
    }

    /** @param array<string, mixed> $booking */
    private function releaseEquipmentOnCancel(array $booking): void
    {
        $equipmentId = (int) ($booking['equipment_id'] ?? 0);
        if ($equipmentId <= 0) {
            return;
        }

        $fulfillment = booking_fulfillment_status($booking);
        if (!in_array($fulfillment, ['fulfillment_with_customer', 'shipping_received', 'shipping_intransit'], true)) {
            return;
        }

        $this->equipment->restoreFromBooking($booking);
    }

    private function releaseBookingAddOns(int $bookingId): void
    {
        // Path B: add-on capacity is derived from booking_add_ons rows; no counter to restore.
    }

    /** @return array<string, mixed> */
    public function requireBooking(int $bookingId): array
    {
        $booking = $this->findBooking($bookingId);
        if ($booking === null) {
            throw new BookingUnavailableException('Booking not found.');
        }

        return $booking;
    }

    /** @return ?array<string, mixed> */
    public function findBooking(int $bookingId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, l.name AS location_name, l.address AS location_address, l.city AS location_city,
                    l.province AS location_province, l.latitude AS location_latitude,
                    l.longitude AS location_longitude, l.pickup_instructions,
                    e.nickname AS equipment_name, e.wifi_ssid, e.wifi_password_enc,
                    u.name AS customer_name, u.email AS customer_email
             FROM bookings b
             INNER JOIN locations l ON l.id = b.location_id
             LEFT JOIN equipment e ON e.id = b.equipment_id
             INNER JOIN users u ON u.id = b.customer_id
             WHERE b.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $bookingId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->ensureBookingReference($row);
    }

    /** @return ?array<string, mixed> */
    public function findCustomerBookingDetail(int $bookingId, int $customerUserId): ?array
    {
        $booking = $this->findBooking($bookingId);
        if ($booking === null || (int) $booking['customer_id'] !== $customerUserId) {
            return null;
        }

        $booking['payments'] = $this->listBookingPayments($bookingId);

        return $booking;
    }

    /** @return list<array<string, mixed>> */
    public function listBookingPayments(int $bookingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payments WHERE booking_id = :booking_id ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['booking_id' => $bookingId]);

        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function ensureBookingReference(array $row): array
    {
        if (trim((string) ($row['reference_code'] ?? '')) !== '') {
            return $row;
        }

        $code = (new BookingReferenceService())->generate($this->db);
        $this->db->prepare('UPDATE bookings SET reference_code = :code WHERE id = :id')->execute([
            'code' => $code,
            'id' => (int) $row['id'],
        ]);
        $row['reference_code'] = $code;

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function listCustomerBookings(int $customerUserId): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, l.name AS location_name, e.nickname AS equipment_name
             FROM bookings b
             INNER JOIN locations l ON l.id = b.location_id
             LEFT JOIN equipment e ON e.id = b.equipment_id
             WHERE b.customer_id = :customer_id
             AND COALESCE(b.booking_kind, \'customer\') = \'customer\'
             ORDER BY b.created_at DESC'
        );
        $stmt->execute(['customer_id' => $customerUserId]);

        return array_map(fn (array $row): array => $this->ensureBookingReference($row), $stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function listAppointmentQueue(): array
    {
        return $this->db->query(
            "SELECT b.*, l.name AS location_name, u.name AS customer_name, u.email AS customer_email
             FROM bookings b
             INNER JOIN locations l ON l.id = b.location_id
             INNER JOIN users u ON u.id = b.customer_id
             WHERE b.fulfillment_type = 'pickup_appointment'
             AND b.booking_status NOT IN ('booking_cancelled', 'booking_pending_payment', 'booking_closed')
             AND (
                b.appointment_status IN ('appointment_awaiting_admin', 'appointment_proposed', 'awaiting_admin', 'proposed')
                OR (
                    b.appointment_status IN ('appointment_confirmed', 'confirmed')
                    AND b.fulfillment_status IN ('fulfillment_pending', 'fulfillment_staged')
                )
             )
             ORDER BY COALESCE(b.confirmed_pickup_date, b.proposed_pickup_date, b.start_date) ASC,
                      COALESCE(b.confirmed_pickup_time_start, b.proposed_pickup_time_start, '') ASC,
                      b.created_at ASC"
        )->fetchAll();
    }

    /** @param list<array<string, mixed>> $addOns */
    private function persistAddOns(int $bookingId, array $addOns): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO booking_add_ons (booking_id, equipment_id, inventory_item_id, quantity, unit_price_cents, line_total_cents)
             VALUES (:booking_id, :equipment_id, NULL, :quantity, :unit_price_cents, :line_total_cents)'
        );

        foreach ($addOns as $addOn) {
            $stmt->execute([
                'booking_id' => $bookingId,
                'equipment_id' => $addOn['equipment_id'],
                'quantity' => $addOn['quantity'],
                'unit_price_cents' => $addOn['unit_price_cents'],
                'line_total_cents' => $addOn['line_total_cents'],
            ]);
        }
    }

    /** @param array<string, mixed> $booking */
    public function totalDueCents(array $booking): int
    {
        return (int) $booking['rental_total_cents']
            + (int) $booking['shipping_fee_cents']
            + (int) $booking['add_ons_total_cents']
            + (int) ($booking['tax_cents'] ?? 0)
            + (int) $booking['deposit_cents'];
    }

    /** @param array<string, mixed> $booking */
    public function rentalChargeCents(array $booking): int
    {
        return (int) $booking['rental_total_cents']
            + (int) $booking['shipping_fee_cents']
            + (int) $booking['add_ons_total_cents']
            + (int) ($booking['tax_cents'] ?? 0);
    }

    /** @param array<string, mixed> $booking */
    public function rentalDayCount(array $booking): int
    {
        $start = parse_date((string) ($booking['start_date'] ?? ''));
        $end = parse_date((string) ($booking['end_date'] ?? ''));
        if ($start === null || $end === null) {
            return 0;
        }

        return inclusive_day_count($start, $end);
    }

    /** @param array<string, mixed> $booking */
    public function isShortTermRental(array $booking): bool
    {
        $maxDays = (int) config('payments.square_short_max_rental_days', 4);

        return $this->rentalDayCount($booking) <= $maxDays;
    }

    public function isSquareLongTerm(array $booking): bool
    {
        $minDays = (int) config('payments.square_long_term_min_days', 5);

        return $this->rentalDayCount($booking) >= $minDays;
    }

    /** @return list<string> */
    public static function releasableBookingStatuses(): array
    {
        return ['booking_confirmed'];
    }

    /** @param array<string, mixed>|null $shippingAddress */
    private function resolveTaxProvince(int $locationId, string $fulfillmentType, ?array $shippingAddress = null): ?string
    {
        if (in_array($fulfillmentType, ['pickup', 'pickup_appointment', 'store_pickup', 'home_appointment'], true)) {
            return null;
        }

        if (
            in_array($fulfillmentType, ['mail_ship', 'city_delivery'], true)
            && $shippingAddress !== null
            && trim((string) ($shippingAddress['province'] ?? '')) !== ''
        ) {
            $resolved = (new TaxService())->resolveProvince((string) $shippingAddress['province']);
            if ($resolved === null) {
                throw new BookingUnavailableException('Enter a valid Canadian province or territory.');
            }

            return $resolved;
        }

        return null;
    }

    private function enforceFulfillmentForLocation(int $locationId, string $fulfillmentType): string
    {
        if ($locationId <= 0 || $fulfillmentType !== 'pickup') {
            return $fulfillmentType;
        }

        $stmt = $this->db->prepare('SELECT location_type FROM locations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $locationId]);
        $locationType = (string) ($stmt->fetchColumn() ?: '');

        if ($locationType === 'home') {
            return 'pickup_appointment';
        }

        return $fulfillmentType;
    }

    public function expireStalePendingBookings(int $hours): int
    {
        if ($hours <= 0) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($hours * 3600));
        $stmt = $this->db->prepare(
            "SELECT id, customer_id FROM bookings
             WHERE booking_status = 'booking_pending_payment'
             AND cancelled_at IS NULL
             AND created_at <= :cutoff"
        );
        $stmt->execute(['cutoff' => $cutoff]);

        $expired = 0;
        while ($row = $stmt->fetch()) {
            try {
                $this->state->apply((int) $row['id'], [
                    'booking_status' => 'booking_cancelled',
                    'payment_status' => 'payment_cancelled',
                ]);
                $this->db->prepare(
                    'UPDATE bookings SET cancelled_at = :cancelled_at, status = :status WHERE id = :id'
                )->execute([
                    'cancelled_at' => now_utc(),
                    'status' => 'cancelled',
                    'id' => (int) $row['id'],
                ]);
                ++$expired;
            } catch (\Throwable) {
                continue;
            }
        }

        return $expired;
    }
}

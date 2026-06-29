<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class SquareDepositService
{
    private readonly PDO $db;

    public function __construct(
        private readonly SquareService $square = new SquareService(),
        private readonly BookingService $bookings = new BookingService(),
        private readonly BookingStateService $bookingState = new BookingStateService(),
        private readonly NotificationService $notifications = new NotificationService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, redirect?: string, message?: string}
     */
    public function processCardCheckout(array $booking, string $sourceId): array
    {
        $bookingId = (int) $booking['id'];

        Connection::beginImmediate($this->db);
        try {
            $locked = $this->bookings->findBooking($bookingId);
            if ($locked === null) {
                throw new BookingUnavailableException('Booking not found.');
            }
            $booking = $locked;

            if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
                if (trim((string) ($booking['square_payment_id'] ?? '')) !== '') {
                    $this->db->commit();

                    return [
                        'ok' => true,
                        'redirect' => route_path('booking/success') . '?booking_id=' . $bookingId,
                    ];
                }

                throw new BookingUnavailableException('This booking is not awaiting payment.');
            }

            if (($booking['payment_method'] ?? '') !== 'square') {
                throw new BookingUnavailableException('This booking is not using card payment.');
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e instanceof BookingUnavailableException) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }

            throw $e;
        }

        $emailError = $this->squareCustomerEmailError($booking);
        if ($emailError !== null) {
            return ['ok' => false, 'message' => $emailError];
        }

        if ($this->isShortTerm($booking)) {
            return $this->processShortTermCheckout($booking, $sourceId);
        }

        return $this->processLongTermCheckout($booking, $sourceId);
    }

    /** @param array<string, mixed> $booking */
    private function squareCustomerEmailError(array $booking): ?string
    {
        $email = trim((string) ($booking['customer_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Your account needs a valid email address before paying by card. Update it in Account → Profile.';
        }

        // Square rejects very short local parts (e.g. 1@1.com) that PHP may still accept.
        $local = strstr($email, '@', true);
        if ($local === false || strlen($local) < 2) {
            return 'Square requires a full email address on your account (not placeholders like 1@1.com). Update it in Account → Profile.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, redirect?: string, message?: string}
     */
    public function updateCardAndRetryDeposit(array $booking, string $sourceId): array
    {
        $bookingId = (int) $booking['id'];
        if (booking_payment_status($booking) !== 'payment_deposit_scheduled_failed') {
            return ['ok' => false, 'message' => 'This booking is not awaiting a new card.'];
        }

        $customerId = trim((string) ($booking['square_customer_id'] ?? ''));
        if ($customerId === '') {
            $customer = $this->square->createCustomer(
                (string) ($booking['customer_email'] ?? ''),
                (string) ($booking['customer_name'] ?? ''),
            );
            if (!$customer['ok']) {
                return ['ok' => false, 'message' => $customer['message'] ?? 'Unable to save customer profile.'];
            }
            $customerId = (string) $customer['customer_id'];
        }

        $card = $this->square->createCardFromToken($customerId, $sourceId);
        if (!$card['ok']) {
            return ['ok' => false, 'message' => $card['message'] ?? 'Unable to save the new card.'];
        }

        $this->db->prepare(
            'UPDATE bookings
             SET square_customer_id = :square_customer_id,
                 square_card_id = :square_card_id
             WHERE id = :id'
        )->execute([
            'square_customer_id' => $customerId,
            'square_card_id' => $card['card_id'],
            'id' => $bookingId,
        ]);

        $updated = $this->bookings->findBooking($bookingId) ?? $booking;
        $auth = $this->authorizeDepositHold($updated);
        if (!$auth['ok']) {
            $this->markDepositFailed($updated, $auth['message'] ?? 'Deposit authorization failed.');

            return ['ok' => false, 'message' => $auth['message'] ?? 'Deposit authorization still failed.'];
        }

        return [
            'ok' => true,
            'redirect' => route_path('booking/success') . '?booking_id=' . $bookingId,
        ];
    }

    /** @return array{processed: int, succeeded: int, failed: int}> */
    public function processDueDepositAuthorizations(): array
    {
        $today = today_date();
        $latestStart = $today->modify('+1 day')->format('Y-m-d');
        $stmt = $this->db->prepare(
            "SELECT b.*, u.email AS customer_email, u.name AS customer_name
             FROM bookings b
             INNER JOIN users u ON u.id = b.customer_id
             WHERE b.booking_status = 'booking_confirmed'
             AND b.square_payment_flow = 'short_term_auth'
             AND b.start_date <= :latest_start
             AND b.start_date >= :today
             AND (b.square_deposit_payment_id IS NULL OR b.square_deposit_payment_id = '')
             AND b.square_card_id IS NOT NULL
             AND b.square_card_id != ''
             AND b.payment_status IN ('payment_deposit_scheduled', 'payment_square_rental_captured')"
        );
        $stmt->execute([
            'latest_start' => $latestStart,
            'today' => $today->format('Y-m-d'),
        ]);

        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        while ($row = $stmt->fetch()) {
            ++$processed;
            $booking = $this->bookings->findBooking((int) $row['id']) ?? $row;
            $auth = $this->authorizeDepositHold($booking);
            if ($auth['ok']) {
                ++$succeeded;
                continue;
            }

            ++$failed;
            $this->markDepositFailed($booking, $auth['message'] ?? 'Deposit authorization failed.');
        }

        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Whether this booking would be picked up if the deposit cron ran right now.
     *
     * @param array<string, mixed> $booking
     */
    public function wouldCronAuthorizeNow(array $booking): bool
    {
        return $this->depositCronSkipReason($booking) === null
            && ($booking['start_date'] ?? '') <= today_date()->modify('+1 day')->format('Y-m-d')
            && ($booking['start_date'] ?? '') >= today_date()->format('Y-m-d');
    }

    /**
     * Why cron skips this booking, or null if it could still authorize automatically.
     *
     * @param array<string, mixed> $booking
     */
    public function depositCronSkipReason(array $booking): ?string
    {
        if ((string) ($booking['square_payment_flow'] ?? '') !== 'short_term_auth') {
            return 'Not a Square short-term booking.';
        }

        if (booking_lifecycle_status($booking) !== 'booking_confirmed') {
            return 'Booking is not confirmed.';
        }

        if (trim((string) ($booking['square_deposit_payment_id'] ?? '')) !== '') {
            return 'Deposit hold is already on file.';
        }

        $payment = booking_payment_status($booking);
        if (!in_array($payment, ['payment_square_rental_captured', 'payment_deposit_scheduled'], true)) {
            return 'Rental deposit is not awaiting authorization.';
        }

        if (trim((string) ($booking['square_card_id'] ?? '')) === '') {
            return 'No card on file.';
        }

        if ((int) ($booking['deposit_cents'] ?? 0) <= 0) {
            return 'No deposit amount.';
        }

        return null;
    }

    /**
     * Admin-facing note about automatic vs manual deposit authorization.
     *
     * @param array<string, mixed> $booking
     * @return ?array{message: string, tone: string}
     */
    public function depositCronBookingNote(array $booking): ?array
    {
        if ((string) ($booking['square_payment_flow'] ?? '') !== 'short_term_auth') {
            return null;
        }

        $skip = $this->depositCronSkipReason($booking);
        if ($skip === 'Deposit hold is already on file.') {
            return [
                'message' => 'Deposit hold is already on file. Nightly cron will not authorize again for this booking.',
                'tone' => 'info',
            ];
        }

        if ($skip !== null) {
            return null;
        }

        $start = parse_date((string) ($booking['start_date'] ?? ''));
        if ($start === null) {
            return null;
        }

        $cronDate = $start->modify('-1 day')->format('Y-m-d');
        if ($this->wouldCronAuthorizeNow($booking)) {
            return [
                'message' => 'If cron is configured, it would authorize this deposit tonight (~1am) because pickup starts tomorrow. '
                    . 'Manual “Authorize deposit hold” is safe — cron only runs for bookings with no deposit on file yet.',
                'tone' => 'warn',
            ];
        }

        return [
            'message' => 'Automatic hold is scheduled for ~1am on ' . $cronDate . ' (24h before pickup). '
                . 'Manual “Authorize deposit hold” now is safe — cron skips bookings that already have a deposit on file.',
            'tone' => 'info',
        ];
    }

    /** @param array<string, mixed> $booking */
    public function releaseDepositOnReturn(array $booking): array
    {
        $flow = (string) ($booking['square_payment_flow'] ?? '');
        $bookingId = (int) $booking['id'];

        if ($flow === 'short_term_auth') {
            $depositPaymentId = (string) ($booking['square_deposit_payment_id'] ?? '');
            if ($depositPaymentId === '') {
                return ['ok' => true, 'message' => 'No deposit authorization to cancel.'];
            }

            $result = $this->square->cancelPayment($depositPaymentId);
            if ($result['ok']) {
                $note = 'Deposit hold released on return.';
                $this->settlePendingDepositHold((int) $booking['id'], $note);
                $this->recordDepositRelease($booking, 'deposit_refund', (int) $booking['deposit_cents'], $note);
            }

            return $result;
        }

        if ($flow === 'long_term_capture') {
            $paymentId = (string) ($booking['square_payment_id'] ?? '');
            $depositCents = (int) $booking['deposit_cents'];
            if ($paymentId === '' || $depositCents <= 0) {
                return ['ok' => true, 'message' => 'No deposit refund required.'];
            }

            $result = $this->square->refundPayment(
                $paymentId,
                $depositCents,
                'booking:' . $bookingId . ' deposit refund',
            );
            if ($result['ok']) {
                $this->recordDepositRelease($booking, 'deposit_refund', $depositCents, 'Deposit refunded on return.');
            }

            return $result;
        }

        if ((int) $booking['deposit_cents'] > 0 && ($booking['payment_method'] ?? '') === 'etransfer') {
            $this->recordDepositRelease($booking, 'deposit_refund', (int) $booking['deposit_cents'], 'Deposit marked for refund on return.');

            return ['ok' => true, 'message' => 'Deposit refund recorded.'];
        }

        return ['ok' => true, 'message' => 'No Square deposit action required.'];
    }

    /**
     * Admin: authorize deposit hold (same as nightly cron at T-24h).
     *
     * @param array<string, mixed> $booking
     * @return array{ok: bool, message?: string}
     */
    public function adminAuthorizeDepositHold(array $booking, int $adminUserId): array
    {
        $error = $this->validateShortTermDepositHoldOrCharge($booking);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }

        $result = $this->authorizeDepositHold($booking, 'Admin #' . $adminUserId . ' authorized deposit hold.');
        if (!$result['ok']) {
            $this->markDepositFailed($booking, $result['message'] ?? 'Deposit authorization failed.');
        }

        return $result;
    }

    /**
     * Admin: charge deposit immediately (capture, not hold).
     *
     * @param array<string, mixed> $booking
     * @return array{ok: bool, message?: string}
     */
    public function adminChargeDeposit(array $booking, int $adminUserId): array
    {
        $error = $this->validateShortTermDepositHoldOrCharge($booking);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }

        return $this->chargeDepositImmediate($booking, 'Admin #' . $adminUserId . ' charged deposit.');
    }

    /**
     * Admin: capture an existing deposit authorization (e.g. customer keeping device longer).
     *
     * @param array<string, mixed> $booking
     * @return array{ok: bool, message?: string}
     */
    public function adminCaptureDepositHold(array $booking, int $adminUserId, ?int $amountCents = null): array
    {
        $error = $this->validateShortTermDepositCapture($booking);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }

        $bookingId = (int) $booking['id'];
        $depositPaymentId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        $depositCents = (int) $booking['deposit_cents'];
        $captureCents = $amountCents ?? $depositCents;
        $captureCents = min(max(0, $captureCents), $depositCents);
        if ($captureCents <= 0) {
            return ['ok' => false, 'message' => 'Deposit amount must be greater than zero.'];
        }

        $capture = $this->square->completePayment($depositPaymentId, $captureCents);
        if (!$capture['ok']) {
            return ['ok' => false, 'message' => $capture['message'] ?? 'Unable to capture deposit hold.'];
        }

        $squarePaymentId = $capture['payment_id'] ?? $depositPaymentId;
        $pendingId = (new BookingPaymentLedgerService())->pendingDepositPaymentId((int) $booking['id']);
        if ($pendingId === null) {
            return ['ok' => false, 'message' => 'No pending deposit hold row found.'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE payments SET status = 'completed', square_payment_id = :square_payment_id, notes = :notes
                 WHERE id = :id"
            )->execute([
                'square_payment_id' => $squarePaymentId,
                'notes' => 'Deposit hold captured (admin #' . $adminUserId . ').',
                'id' => $pendingId,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['ok' => true, 'message' => 'Deposit hold captured (' . PricingService::formatMoney($captureCents) . ').'];
    }

    /**
     * Admin: cancel an active deposit authorization without closing the booking.
     *
     * @param array<string, mixed> $booking
     * @return array{ok: bool, message?: string}
     */
    public function adminReleaseDepositHold(array $booking, int $adminUserId): array
    {
        $error = $this->validateShortTermDepositRelease($booking);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }

        $bookingId = (int) $booking['id'];
        $depositPaymentId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        $note = 'Admin #' . $adminUserId . ' released deposit hold.';

        if (!$this->square->isLocalPaymentId($depositPaymentId)) {
            $cancel = $this->square->cancelPayment($depositPaymentId);
            if (!$cancel['ok']) {
                return ['ok' => false, 'message' => $cancel['message'] ?? 'Unable to release deposit hold in Square.'];
            }
        }

        $this->db->beginTransaction();
        try {
            $this->settlePendingDepositHold($bookingId, $note);
            $this->recordDepositRelease($booking, 'deposit_refund', (int) $booking['deposit_cents'], $note);

            if (booking_payment_status($booking) === 'payment_deposit_scheduled_processed') {
                $this->bookingState->apply($bookingId, [
                    'payment_status' => 'payment_deposit_scheduled_released',
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['ok' => true, 'message' => 'Deposit hold released.'];
    }

    /**
     * Admin UI: when an active deposit hold is scheduled to release.
     *
     * @param array<string, mixed> $booking
     * @return ?array{placed_label: ?string, release_label: string, square_expires_label: ?string}
     */
    public function depositHoldReleaseInfo(array $booking): ?array
    {
        if ((string) ($booking['square_payment_flow'] ?? '') !== 'short_term_auth') {
            return null;
        }

        $ledger = new BookingPaymentLedgerService();
        if (!$ledger->hasPendingDepositHold($booking)) {
            return null;
        }

        $authAt = trim((string) ($booking['deposit_auth_at'] ?? ''));
        $placedLabel = $this->formatAdminDateLabel($authAt);

        $fulfillment = booking_fulfillment_status($booking);
        $end = parse_date((string) ($booking['end_date'] ?? ''));
        $endLabel = $end !== null ? $end->format('M j, Y') : null;

        $releaseLabel = match (true) {
            $fulfillment === 'fulfillment_return_received' => 'Release on QC pass',
            $endLabel !== null => 'After return QC · rental ends ' . $endLabel,
            default => 'After return QC',
        };

        $squareExpiresLabel = null;
        if ($authAt !== '') {
            try {
                $authDt = new \DateTimeImmutable($authAt);
                $holdDays = (int) config('payments.deposit_auth_hold_days', 7);
                $expires = $authDt->modify('+' . $holdDays . ' days');
                $squareExpiresLabel = 'Square authorization expires ~' . $expires->format('M j, Y');
            } catch (\Exception) {
                $squareExpiresLabel = null;
            }
        }

        return [
            'placed_label' => $placedLabel,
            'release_label' => $releaseLabel,
            'square_expires_label' => $squareExpiresLabel,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, message?: string}
     */
    public function chargeDamageFromDeposit(array $booking, int $amountCents): array
    {
        $bookingId = (int) $booking['id'];
        $amountCents = min($amountCents, (int) $booking['deposit_cents']);
        if ($amountCents <= 0) {
            return ['ok' => true, 'message' => 'No deposit to charge.'];
        }

        $flow = (string) ($booking['square_payment_flow'] ?? '');
        $squarePaymentId = null;

        if ($flow === 'short_term_auth') {
            $depositPaymentId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
            if ($depositPaymentId !== '') {
                $capture = $this->square->completePayment($depositPaymentId, $amountCents);
                if (!$capture['ok']) {
                    return ['ok' => false, 'message' => $capture['message'] ?? 'Unable to capture deposit hold.'];
                }
                $squarePaymentId = $capture['payment_id'] ?? $depositPaymentId;
            }
        }

        $this->db->prepare(
            'INSERT INTO payments (
                booking_id, equipment_id, type, amount_cents, square_payment_id, status, notes, created_at
             ) VALUES (
                :booking_id, :equipment_id, :type, :amount_cents, :square_payment_id, :status, :notes, :created_at
             )'
        )->execute([
            'booking_id' => $bookingId,
            'equipment_id' => $booking['equipment_id'],
            'type' => 'damage_charge',
            'amount_cents' => $amountCents,
            'square_payment_id' => $squarePaymentId,
            'status' => 'completed',
            'notes' => 'Damage charge from deposit on QC failure.',
            'created_at' => now_utc(),
        ]);

        return ['ok' => true, 'message' => 'Damage charge recorded.'];
    }

    /** @param array<string, mixed> $booking */
    private function isShortTerm(array $booking): bool
    {
        $maxDays = (int) config('payments.square_short_max_rental_days', 4);

        return $this->bookings->rentalDayCount($booking) <= $maxDays;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, redirect?: string, message?: string}
     */
    private function processShortTermCheckout(array $booking, string $sourceId): array
    {
        $bookingId = (int) $booking['id'];
        $rentalCents = $this->bookings->rentalChargeCents($booking);

        $payment = $this->square->createPayment(
            $sourceId,
            $rentalCents,
            true,
            'booking:' . $bookingId . ' rental',
            'booking:' . $bookingId . ':short-term-rental',
        );
        if (!$payment['ok']) {
            return $this->checkoutFailed($booking, $payment['message'] ?? 'Unable to charge rental fee.');
        }

        $paymentId = (string) $payment['payment_id'];
        $customer = $this->square->createCustomer(
            (string) ($booking['customer_email'] ?? ''),
            (string) ($booking['customer_name'] ?? ''),
        );
        if (!$customer['ok']) {
            return $this->checkoutFailed(
                $booking,
                $customer['message'] ?? 'Unable to save customer profile.',
                $paymentId,
                $rentalCents,
            );
        }

        $card = $this->square->createCardFromPayment((string) $customer['customer_id'], $paymentId);
        if (!$card['ok']) {
            return $this->checkoutFailed(
                $booking,
                $card['message'] ?? 'Unable to save card on file.',
                $paymentId,
                $rentalCents,
            );
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE bookings
                 SET square_payment_id = :square_payment_id,
                     square_customer_id = :square_customer_id,
                     square_card_id = :square_card_id,
                     square_payment_flow = :square_payment_flow,
                     payment_method = :payment_method
                 WHERE id = :id'
            )->execute([
                'square_payment_id' => $paymentId,
                'square_customer_id' => $customer['customer_id'],
                'square_card_id' => $card['card_id'],
                'square_payment_flow' => 'short_term_auth',
                'payment_method' => 'square',
                'id' => $bookingId,
            ]);

            $this->bookingState->apply($bookingId, [
                'payment_status' => 'payment_square_rental_captured',
                'booking_status' => 'booking_confirmed',
                'fulfillment_status' => 'fulfillment_pending',
            ]);

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
                'amount_cents' => $rentalCents,
                'square_payment_id' => $paymentId,
                'status' => 'completed',
                'notes' => 'Short-term rental charged at booking; deposit authorized 24h before start.',
                'created_at' => now_utc(),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            return $this->checkoutFailed(
                $booking,
                'Unable to save booking payment.',
                $paymentId,
                $rentalCents,
            );
        }

        $updated = $this->bookings->findBooking($bookingId) ?? $booking;
        $this->notifications->bookingDepositPending($updated);

        return [
            'ok' => true,
            'redirect' => route_path('booking/success') . '?booking_id=' . $bookingId,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, redirect?: string, message?: string}
     */
    private function processLongTermCheckout(array $booking, string $sourceId): array
    {
        $bookingId = (int) $booking['id'];
        $totalCents = $this->bookings->totalDueCents($booking);

        $payment = $this->square->createPayment(
            $sourceId,
            $totalCents,
            true,
            'booking:' . $bookingId . ' rental+deposit',
            'booking:' . $bookingId . ':long-term-checkout',
        );
        if (!$payment['ok']) {
            return $this->checkoutFailed($booking, $payment['message'] ?? 'Unable to process payment.');
        }

        $paymentId = (string) $payment['payment_id'];

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE bookings
                 SET square_payment_id = :square_payment_id,
                     square_payment_flow = :square_payment_flow,
                     payment_method = :payment_method
                 WHERE id = :id'
            )->execute([
                'square_payment_id' => $paymentId,
                'square_payment_flow' => 'long_term_capture',
                'payment_method' => 'square',
                'id' => $bookingId,
            ]);

            $this->bookingState->apply($bookingId, [
                'payment_status' => 'payment_square_full_captured',
                'booking_status' => 'booking_confirmed',
                'fulfillment_status' => 'fulfillment_pending',
            ]);

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
                'amount_cents' => $totalCents,
                'square_payment_id' => $paymentId,
                'status' => 'completed',
                'notes' => 'Long-term rental and deposit captured at booking.',
                'created_at' => now_utc(),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            return $this->checkoutFailed(
                $booking,
                'Unable to save booking payment.',
                $paymentId,
                $totalCents,
            );
        }

        $updated = $this->bookings->findBooking($bookingId) ?? $booking;
        $this->notifications->bookingConfirmed($updated);

        return [
            'ok' => true,
            'redirect' => route_path('booking/success') . '?booking_id=' . $bookingId,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, message?: string}
     */
    private function authorizeDepositHold(array $booking, ?string $ledgerNote = null): array
    {
        $bookingId = (int) $booking['id'];
        $cardId = (string) ($booking['square_card_id'] ?? '');
        $customerId = trim((string) ($booking['square_customer_id'] ?? ''));
        $depositCents = (int) $booking['deposit_cents'];

        if ($cardId === '' || $depositCents <= 0) {
            return ['ok' => false, 'message' => 'Missing card or deposit amount.'];
        }

        if ($customerId === '') {
            return ['ok' => false, 'message' => 'Missing Square customer — card on file cannot be charged.'];
        }

        $auth = $this->square->authorizeDeposit(
            $cardId,
            $depositCents,
            'booking:' . $bookingId . ' deposit_hold',
            $customerId,
        );
        if (!$auth['ok']) {
            return ['ok' => false, 'message' => $auth['message'] ?? 'Deposit authorization failed.'];
        }

        $this->persistDepositHold($booking, (string) $auth['payment_id'], $ledgerNote);

        $updated = $this->bookings->findBooking($bookingId) ?? $booking;
        $this->notifications->bookingReadyForPickup($updated);

        return ['ok' => true, 'message' => 'Deposit hold authorized (' . PricingService::formatMoney($depositCents) . ').'];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, message?: string}
     */
    private function chargeDepositImmediate(array $booking, string $ledgerNote): array
    {
        $bookingId = (int) $booking['id'];
        $cardId = (string) ($booking['square_card_id'] ?? '');
        $customerId = trim((string) ($booking['square_customer_id'] ?? ''));
        $depositCents = (int) $booking['deposit_cents'];

        if ($cardId === '' || $depositCents <= 0) {
            return ['ok' => false, 'message' => 'Missing card or deposit amount.'];
        }

        if ($customerId === '') {
            return ['ok' => false, 'message' => 'Missing Square customer — card on file cannot be charged.'];
        }

        $payment = $this->square->createPayment(
            $cardId,
            $depositCents,
            true,
            'booking:' . $bookingId . ' deposit_charge',
            null,
            $customerId,
        );
        if (!$payment['ok']) {
            return ['ok' => false, 'message' => $payment['message'] ?? 'Deposit charge failed.'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE bookings
                 SET square_deposit_payment_id = :square_deposit_payment_id,
                     deposit_auth_at = :deposit_auth_at
                 WHERE id = :id'
            )->execute([
                'square_deposit_payment_id' => $payment['payment_id'],
                'deposit_auth_at' => now_utc(),
                'id' => $bookingId,
            ]);

            $this->bookingState->apply($bookingId, [
                'payment_status' => 'payment_deposit_scheduled_processed',
                'booking_status' => 'booking_confirmed',
            ]);

            $this->db->prepare(
                'INSERT INTO payments (
                    booking_id, equipment_id, type, amount_cents, square_payment_id, status, notes, created_at
                 ) VALUES (
                    :booking_id, :equipment_id, :type, :amount_cents, :square_payment_id, :status, :notes, :created_at
                 )'
            )->execute([
                'booking_id' => $bookingId,
                'equipment_id' => $booking['equipment_id'],
                'type' => 'deposit',
                'amount_cents' => $depositCents,
                'square_payment_id' => $payment['payment_id'],
                'status' => 'completed',
                'notes' => $ledgerNote,
                'created_at' => now_utc(),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $updated = $this->bookings->findBooking($bookingId) ?? $booking;
        $this->notifications->bookingReadyForPickup($updated);

        return ['ok' => true, 'message' => 'Deposit charged (' . PricingService::formatMoney($depositCents) . ').'];
    }

    /** @param array<string, mixed> $booking */
    private function persistDepositHold(array $booking, string $paymentId, ?string $ledgerNote = null): void
    {
        $bookingId = (int) $booking['id'];
        $depositCents = (int) $booking['deposit_cents'];

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE bookings
                 SET square_deposit_payment_id = :square_deposit_payment_id,
                     deposit_auth_at = :deposit_auth_at
                 WHERE id = :id'
            )->execute([
                'square_deposit_payment_id' => $paymentId,
                'deposit_auth_at' => now_utc(),
                'id' => $bookingId,
            ]);

            $this->bookingState->apply($bookingId, [
                'payment_status' => 'payment_deposit_scheduled_processed',
                'booking_status' => 'booking_confirmed',
            ]);

            $this->db->prepare(
                'INSERT INTO payments (
                    booking_id, equipment_id, type, amount_cents, square_payment_id, status, notes, created_at
                 ) VALUES (
                    :booking_id, :equipment_id, :type, :amount_cents, :square_payment_id, :status, :notes, :created_at
                 )'
            )->execute([
                'booking_id' => $bookingId,
                'equipment_id' => $booking['equipment_id'],
                'type' => 'deposit',
                'amount_cents' => $depositCents,
                'square_payment_id' => $paymentId,
                'status' => 'pending',
                'notes' => $ledgerNote ?? 'Security deposit authorization hold.',
                'created_at' => now_utc(),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $booking */
    private function validateShortTermDepositHoldOrCharge(array $booking): ?string
    {
        if ((string) ($booking['square_payment_flow'] ?? '') !== 'short_term_auth') {
            return 'Deposit actions apply to Square short-term bookings only.';
        }

        if (in_array(booking_lifecycle_status($booking), ['booking_cancelled', 'booking_closed', 'booking_pending_payment'], true)) {
            return 'Booking is not eligible for deposit actions.';
        }

        if (trim((string) ($booking['square_deposit_payment_id'] ?? '')) !== '') {
            return 'Deposit is already on file for this booking.';
        }

        $payment = booking_payment_status($booking);
        $eligible = in_array($payment, [
            'payment_square_rental_captured',
            'payment_deposit_scheduled',
            'payment_deposit_scheduled_failed',
            'payment_deposit_scheduled_processed',
        ], true);
        if (!$eligible) {
            return 'Rental payment must be captured before deposit hold or charge.';
        }

        if (trim((string) ($booking['square_card_id'] ?? '')) === '') {
            return 'No card on file — customer must pay rental first.';
        }

        if (trim((string) ($booking['square_customer_id'] ?? '')) === '') {
            return 'No Square customer on file — customer must pay rental first.';
        }

        if ((int) ($booking['deposit_cents'] ?? 0) <= 0) {
            return 'This booking has no deposit amount.';
        }

        if (!$this->square->isConfigured()) {
            return 'Square is not configured.';
        }

        return null;
    }

    /** @param array<string, mixed> $booking */
    private function validateShortTermDepositCapture(array $booking): ?string
    {
        if ((string) ($booking['square_payment_flow'] ?? '') !== 'short_term_auth') {
            return 'Deposit capture applies to Square short-term bookings only.';
        }

        if (in_array(booking_lifecycle_status($booking), ['booking_cancelled', 'booking_closed', 'booking_pending_payment'], true)) {
            return 'Booking is not eligible for deposit capture.';
        }

        $depositPaymentId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        if ($depositPaymentId === '') {
            return 'No deposit hold to capture.';
        }

        if ($this->square->isLocalPaymentId($depositPaymentId)) {
            return 'Deposit hold is test/local only — cannot capture in Square.';
        }

        if (!(new BookingPaymentLedgerService())->hasPendingDepositHold($booking)) {
            return 'Deposit hold was already captured or released.';
        }

        if (!$this->square->isConfigured()) {
            return 'Square is not configured.';
        }

        return null;
    }

    /** @param array<string, mixed> $booking */
    private function markDepositFailed(array $booking, string $reason): void
    {
        $bookingId = (int) $booking['id'];

        $this->bookingState->apply($bookingId, [
            'payment_status' => 'payment_deposit_scheduled_failed',
        ]);

        $updateUrl = config('url') . route_path('booking/update-card') . '?booking_id=' . $bookingId;

        $this->notifications->depositAuthorizationFailed($booking, $updateUrl);
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: false, message: string}
     */
    private function checkoutFailed(
        array $booking,
        string $message,
        ?string $squarePaymentId = null,
        ?int $refundCents = null,
    ): array {
        if (
            $squarePaymentId !== null
            && $refundCents !== null
            && $refundCents > 0
            && !$this->square->isLocalPaymentId($squarePaymentId)
        ) {
            $this->square->refundPayment(
                $squarePaymentId,
                $refundCents,
                'booking:' . (int) $booking['id'] . ' checkout rollback',
            );
        }

        $this->notifications->dispatch('payment_checkout_failed', $booking, ['reason' => $message]);

        return ['ok' => false, 'message' => $message];
    }

    /** @param array<string, mixed> $booking */
    private function validateShortTermDepositRelease(array $booking): ?string
    {
        if ((string) ($booking['square_payment_flow'] ?? '') !== 'short_term_auth') {
            return 'Deposit release applies to Square short-term bookings only.';
        }

        if (in_array(booking_lifecycle_status($booking), ['booking_cancelled', 'booking_closed', 'booking_pending_payment'], true)) {
            return 'Booking is not eligible for deposit release.';
        }

        $depositPaymentId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        if ($depositPaymentId === '') {
            return 'No deposit hold to release.';
        }

        if (!(new BookingPaymentLedgerService())->hasPendingDepositHold($booking)) {
            return 'Deposit hold was already captured or released.';
        }

        return null;
    }

    private function settlePendingDepositHold(int $bookingId, string $notes): void
    {
        $pendingId = (new BookingPaymentLedgerService())->pendingDepositPaymentId($bookingId);
        if ($pendingId === null) {
            return;
        }

        $this->db->prepare(
            "UPDATE payments SET status = 'refunded', notes = :notes WHERE id = :id"
        )->execute([
            'notes' => $notes,
            'id' => $pendingId,
        ]);
    }

    private function formatAdminDateLabel(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($iso))->format('M j, Y');
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $booking */
    private function recordDepositRelease(array $booking, string $type, int $amountCents, string $notes): void
    {
        $this->db->prepare(
            'INSERT INTO payments (booking_id, equipment_id, type, amount_cents, square_payment_id, status, notes, created_at)
             VALUES (:booking_id, :equipment_id, :type, :amount_cents, :square_payment_id, :status, :notes, :created_at)'
        )->execute([
            'booking_id' => (int) $booking['id'],
            'equipment_id' => $booking['equipment_id'],
            'type' => $type,
            'amount_cents' => $amountCents,
            'square_payment_id' => $booking['square_deposit_payment_id'] ?? $booking['square_payment_id'] ?? null,
            'status' => 'refunded',
            'notes' => $notes,
            'created_at' => now_utc(),
        ]);
    }
}

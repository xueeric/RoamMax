<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class BookingPaymentLedgerService
{
    private readonly PDO $db;

    public function __construct(
        private readonly BookingService $bookings = new BookingService(),
        private readonly SquareService $square = new SquareService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    /**
     * @param array<string, mixed> $booking
     * @return list<array{
     *   at: string,
     *   label: string,
     *   amount_cents: int,
     *   direction: string,
     *   status: string,
     *   square_id: ?string,
     *   notes: string,
     *   payment_row_id: ?int,
     *   square_verified: bool,
     *   square_can_verify: bool,
     *   square_verified_status: ?string
     * }>
     */
    public function transactionTimeline(int $bookingId, array $booking): array
    {
        $rows = $this->bookings->listBookingPayments($bookingId);
        $events = [];

        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $amount = (int) ($row['amount_cents'] ?? 0);
            $direction = in_array($status, ['refunded', 'reversed'], true) || $type === 'deposit_refund'
                ? 'credit'
                : 'debit';
            $squareId = trim((string) ($row['square_payment_id'] ?? ''));

            $events[] = [
                'at' => (string) ($row['created_at'] ?? ''),
                'label' => $this->labelForPaymentRow($type, $status),
                'amount_cents' => $amount,
                'direction' => $direction,
                'status' => $status,
                'square_id' => $squareId !== '' ? $squareId : null,
                'notes' => trim((string) ($row['notes'] ?? '')),
                'payment_row_id' => (int) ($row['id'] ?? 0) ?: null,
            ];
        }

        $rentalId = trim((string) ($booking['square_payment_id'] ?? ''));
        $depositId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        $ledgerSquareIds = array_filter(array_map(
            static fn (array $e): ?string => $e['square_id'],
            $events,
        ));

        if ($rentalId !== '' && !in_array($rentalId, $ledgerSquareIds, true)) {
            $events[] = [
                'at' => (string) ($booking['created_at'] ?? ''),
                'label' => 'Rental charge (booking record)',
                'amount_cents' => $this->bookings->rentalChargeCents($booking),
                'direction' => 'debit',
                'status' => 'record_only',
                'square_id' => $rentalId,
                'notes' => 'Linked on booking — not in payments table.',
                'payment_row_id' => null,
            ];
        }

        if ($depositId !== '' && !in_array($depositId, $ledgerSquareIds, true)) {
            $events[] = [
                'at' => (string) ($booking['deposit_auth_at'] ?? $booking['created_at'] ?? ''),
                'label' => 'Deposit hold (booking record)',
                'amount_cents' => (int) ($booking['deposit_cents'] ?? 0),
                'direction' => 'hold',
                'status' => 'record_only',
                'square_id' => $depositId,
                'notes' => 'Linked on booking — not in payments table.',
                'payment_row_id' => null,
            ];
        }

        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $this->attachSquareVerification($bookingId, $events);
    }

    /** @return array{ok: bool, message?: string} */
    public function verifyAndStoreSquareTransaction(int $bookingId, string $squarePaymentId): array
    {
        $squarePaymentId = trim($squarePaymentId);
        if ($squarePaymentId === '') {
            return ['ok' => false, 'message' => 'Payment ID is empty.'];
        }

        if ($this->square->isLocalPaymentId($squarePaymentId)) {
            return ['ok' => false, 'message' => 'Test/local payment ID — not in Square.'];
        }

        if (!$this->square->isConfigured()) {
            return ['ok' => false, 'message' => 'Square API not configured.'];
        }

        $result = $this->square->getPayment($squarePaymentId);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => (string) ($result['message'] ?? 'Not found in Square.')];
        }

        $status = (string) (($result['payment']['status'] ?? '') ?: '');
        if (!in_array($status, ['COMPLETED', 'APPROVED'], true)) {
            return ['ok' => false, 'message' => 'Square status: ' . ($status !== '' ? $status : 'unknown')];
        }

        $payment = $result['payment'];
        $note = (string) ($payment['note'] ?? '');
        $reference = (string) ($payment['reference_id'] ?? '');
        if (
            !str_contains($note, 'booking:' . $bookingId)
            && !str_contains($reference, 'booking:' . $bookingId)
        ) {
            return ['ok' => false, 'message' => 'Square payment is not linked to this booking.'];
        }

        $booking = (new BookingService())->findBooking($bookingId);
        if ($booking === null) {
            return ['ok' => false, 'message' => 'Booking not found.'];
        }

        $amountCents = (int) (($payment['amount_money']['amount'] ?? 0) ?: 0);
        $expectedCents = max(
            (new BookingService())->totalDueCents($booking),
            (new BookingService())->rentalChargeCents($booking),
        );
        if ($amountCents > 0 && $amountCents < $expectedCents) {
            return ['ok' => false, 'message' => 'Square payment amount is lower than the booking total.'];
        }

        $this->markSquareVerified($bookingId, $squarePaymentId, $status);

        return ['ok' => true, 'message' => 'Verified in Square (' . $status . ').'];
    }

    public function clearVerificationsForBooking(int $bookingId): void
    {
        $this->db->prepare('DELETE FROM square_payment_verifications WHERE booking_id = :booking_id')
            ->execute(['booking_id' => $bookingId]);
        $this->db->prepare(
            'UPDATE payments SET square_verified_at = NULL WHERE booking_id = :booking_id'
        )->execute(['booking_id' => $bookingId]);
    }

    private function markSquareVerified(int $bookingId, string $squarePaymentId, string $squareStatus): void
    {
        $verifiedAt = now_utc();

        $this->db->prepare(
            'INSERT INTO square_payment_verifications (booking_id, square_payment_id, verified_at, square_status)
             VALUES (:booking_id, :square_payment_id, :verified_at, :square_status)
             ON CONFLICT(booking_id, square_payment_id) DO UPDATE SET
                verified_at = excluded.verified_at,
                square_status = excluded.square_status'
        )->execute([
            'booking_id' => $bookingId,
            'square_payment_id' => $squarePaymentId,
            'verified_at' => $verifiedAt,
            'square_status' => $squareStatus,
        ]);

        $this->db->prepare(
            'UPDATE payments SET square_verified_at = :verified_at
             WHERE booking_id = :booking_id AND square_payment_id = :square_payment_id'
        )->execute([
            'verified_at' => $verifiedAt,
            'booking_id' => $bookingId,
            'square_payment_id' => $squarePaymentId,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function attachSquareVerification(int $bookingId, array $events): array
    {
        $verified = $this->loadVerificationMap($bookingId);

        foreach ($events as &$event) {
            $squareId = $event['square_id'] ?? null;
            if (!is_string($squareId) || $squareId === '') {
                $event['square_verified'] = false;
                $event['square_can_verify'] = false;
                $event['square_verified_status'] = null;
                continue;
            }

            if ($this->square->isLocalPaymentId($squareId)) {
                $event['square_verified'] = false;
                $event['square_can_verify'] = false;
                $event['square_verified_status'] = null;
                continue;
            }

            $record = $verified[$squareId] ?? null;
            $event['square_verified'] = $record !== null;
            $event['square_verified_status'] = is_array($record) ? (string) ($record['square_status'] ?? '') : null;
            $event['square_can_verify'] = !$event['square_verified'] && $this->square->isConfigured();
        }
        unset($event);

        return $events;
    }

    /** @return array<string, array{verified_at: string, square_status: string}> */
    private function loadVerificationMap(int $bookingId): array
    {
        $map = [];

        $stmt = $this->db->prepare(
            'SELECT square_payment_id, verified_at, square_status
             FROM square_payment_verifications WHERE booking_id = :booking_id'
        );
        $stmt->execute(['booking_id' => $bookingId]);
        while ($row = $stmt->fetch()) {
            $id = trim((string) ($row['square_payment_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $map[$id] = [
                'verified_at' => (string) ($row['verified_at'] ?? ''),
                'square_status' => (string) ($row['square_status'] ?? ''),
            ];
        }

        foreach ($this->bookings->listBookingPayments($bookingId) as $row) {
            $id = trim((string) ($row['square_payment_id'] ?? ''));
            $verifiedAt = trim((string) ($row['square_verified_at'] ?? ''));
            if ($id === '' || $verifiedAt === '' || isset($map[$id])) {
                continue;
            }
            $map[$id] = [
                'verified_at' => $verifiedAt,
                'square_status' => 'COMPLETED',
            ];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{
     *   system_payment_status: string,
     *   items: list<array{
     *     role: string,
     *     payment_id: string,
     *     source: string,
     *     square_status: ?string,
     *     amount_cents: ?int,
     *     tone: string,
     *     message: string
     *   }>
     * }
     */
    /** Local/test IDs or paid-like status without a real rental charge — no Square API calls. */
    public function hasPaymentMismatch(array $booking): bool
    {
        return $this->hasRentalPaymentMismatch($booking) || $this->hasDepositPaymentMismatch($booking);
    }

    /** @param array<string, mixed> $booking */
    public function hasRentalPaymentMismatch(array $booking): bool
    {
        $payment = booking_payment_status($booking);
        $rentalId = trim((string) ($booking['square_payment_id'] ?? ''));

        if ($rentalId !== '' && $this->square->isLocalPaymentId($rentalId)) {
            return true;
        }

        if (
            in_array($payment, [
                'payment_square_rental_captured',
                'payment_square_full_captured',
                'payment_deposit_scheduled',
                'payment_deposit_scheduled_processed',
            ], true)
            && ($rentalId === '' || $this->square->isLocalPaymentId($rentalId))
        ) {
            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $booking */
    public function hasDepositPaymentMismatch(array $booking): bool
    {
        $payment = booking_payment_status($booking);
        $depositId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        $depositCents = (int) ($booking['deposit_cents'] ?? 0);

        if ($depositId !== '' && $this->square->isLocalPaymentId($depositId)) {
            return true;
        }

        if ($payment === 'payment_deposit_scheduled_processed' && $depositCents > 0 && $depositId === '') {
            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $booking */
    public function hasPendingDepositHold(array $booking): bool
    {
        return $this->pendingDepositPaymentId((int) $booking['id']) !== null;
    }

    public function pendingDepositPaymentId(int $bookingId): ?int
    {
        foreach (array_reverse($this->bookings->listBookingPayments($bookingId)) as $row) {
            if (($row['type'] ?? '') === 'deposit' && ($row['status'] ?? '') === 'pending') {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $booking */
    public function isDepositCharged(array $booking): bool
    {
        foreach ($this->bookings->listBookingPayments((int) $booking['id']) as $row) {
            if (($row['type'] ?? '') === 'deposit' && ($row['status'] ?? '') === 'completed') {
                return true;
            }
        }

        return false;
    }

    /** @param array{items: list<array{tone: string}>} $verify */
    public function hasVerifyMismatch(array $verify): bool
    {
        foreach ($verify['items'] as $item) {
            if (in_array($item['tone'], ['warn', 'urgent'], true)) {
                return true;
            }
        }

        return false;
    }

    public function verifyWithSquare(array $booking): array
    {
        $items = [];
        $checks = [
            ['role' => 'Rental charge', 'id' => trim((string) ($booking['square_payment_id'] ?? ''))],
            ['role' => 'Deposit hold', 'id' => trim((string) ($booking['square_deposit_payment_id'] ?? ''))],
        ];

        foreach ($checks as $check) {
            $id = $check['id'];
            if ($id === '') {
                continue;
            }

            $items[] = $this->verifyPaymentId($check['role'], $id);
        }

        return [
            'system_payment_status' => booking_payment_status($booking),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{fee_cents: int, max_refund_cents: int, default_refund_cents: int, has_deposit_hold: bool, deposit_cents: int}
     */
    public function cancelPaymentDefaults(array $booking): array
    {
        $estimate = $this->bookings->estimateCancellation($booking);
        $storedRefund = (int) ($booking['cancellation_refund_cents'] ?? 0);
        $defaultRefund = $storedRefund > 0 ? $storedRefund : $estimate['refund_cents'];
        $maxRefund = max(0, $this->bookings->rentalChargeCents($booking) - $estimate['fee_cents']);
        $defaultRefund = min($defaultRefund, $maxRefund);

        return [
            'fee_cents' => $estimate['fee_cents'],
            'max_refund_cents' => $maxRefund,
            'default_refund_cents' => $defaultRefund,
            'has_deposit_hold' => $this->hasActiveDepositHold($booking),
            'deposit_cents' => (int) ($booking['deposit_cents'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $booking */
    public function hasActiveDepositHold(array $booking): bool
    {
        $depositId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        if ($depositId === '') {
            return false;
        }

        $payment = booking_payment_status($booking);
        if (in_array($payment, [
            'payment_deposit_scheduled_released',
            'payment_square_refunded',
        ], true)) {
            return false;
        }

        return in_array($payment, [
            'payment_deposit_scheduled',
            'payment_deposit_scheduled_processed',
            'payment_deposit_scheduled_failed',
        ], true);
    }

    /** @return array{role: string, payment_id: string, source: string, square_status: ?string, amount_cents: ?int, tone: string, message: string} */
    private function verifyPaymentId(string $role, string $paymentId): array
    {
        if ($this->square->isLocalPaymentId($paymentId)) {
            return [
                'role' => $role,
                'payment_id' => $paymentId,
                'source' => 'local',
                'square_status' => null,
                'amount_cents' => null,
                'tone' => 'warn',
                'message' => 'Test/mock payment — not in Square. System status may not match a real charge.',
            ];
        }

        if (!$this->square->isConfigured()) {
            return [
                'role' => $role,
                'payment_id' => $paymentId,
                'source' => 'square',
                'square_status' => null,
                'amount_cents' => null,
                'tone' => 'warn',
                'message' => 'Square API not configured — cannot verify.',
            ];
        }

        $result = $this->square->getPayment($paymentId);
        if (!empty($result['local_only'])) {
            return [
                'role' => $role,
                'payment_id' => $paymentId,
                'source' => 'local',
                'square_status' => null,
                'amount_cents' => null,
                'tone' => 'warn',
                'message' => (string) ($result['message'] ?? 'Local payment ID.'),
            ];
        }

        if (!$result['ok']) {
            return [
                'role' => $role,
                'payment_id' => $paymentId,
                'source' => 'square',
                'square_status' => null,
                'amount_cents' => null,
                'tone' => 'urgent',
                'message' => (string) ($result['message'] ?? 'Not found in Square.'),
            ];
        }

        $payment = $result['payment'] ?? [];
        $status = (string) ($payment['status'] ?? '');
        $amount = (int) ($payment['amount_money']['amount'] ?? 0);

        return [
            'role' => $role,
            'payment_id' => $paymentId,
            'source' => 'square',
            'square_status' => $status,
            'amount_cents' => $amount,
            'tone' => $this->toneForSquareStatus($status),
            'message' => 'Square: ' . $status . ($amount > 0 ? ' · ' . PricingService::formatMoney($amount) : ''),
        ];
    }

    private function toneForSquareStatus(string $status): string
    {
        return match ($status) {
            'COMPLETED', 'APPROVED' => 'info',
            'CANCELED', 'FAILED' => 'urgent',
            default => 'warn',
        };
    }

    private function labelForPaymentRow(string $type, string $status): string
    {
        return match ($type) {
            'checkout' => match ($status) {
                'refunded' => 'Rental refund',
                'reversed' => 'Payment reset',
                'failed' => 'Rental charge failed',
                default => 'Rental charge',
            },
            'rental' => $status === 'refunded' ? 'Rental refund' : 'Rental charge',
            'deposit' => $status === 'refunded' ? 'Deposit released' : 'Deposit hold',
            'deposit_refund' => 'Deposit refund / release',
            'cancellation_fee' => 'Cancellation fee kept',
            'late_fee' => 'Late fee',
            'damage_charge' => 'Damage charge',
            'shipping' => 'Shipping',
            'addon' => 'Add-on',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}

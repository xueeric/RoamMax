<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Booking\BookingStatuses;
use Starlink\Database\Connection;

final class BookingStateService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /**
     * @param array{
     *   payment_status?: string,
     *   booking_status?: string,
     *   fulfillment_status?: string
     * } $states
     */
    public function apply(int $bookingId, array $states): void
    {
        $current = $this->fetchRow($bookingId);
        if ($current === null) {
            throw new BookingUnavailableException('Booking not found.');
        }

        $payment = $states['payment_status'] ?? (string) ($current['payment_status'] ?? '');
        $booking = $states['booking_status'] ?? (string) ($current['booking_status'] ?? '');
        $fulfillment = $states['fulfillment_status'] ?? (string) ($current['fulfillment_status'] ?? '');
        $legacy = $this->legacyStatus($payment, $booking, $fulfillment, $current);

        $this->db->prepare(
            'UPDATE bookings SET
                payment_status = :payment_status,
                booking_status = :booking_status,
                fulfillment_status = :fulfillment_status,
                status = :status
             WHERE id = :id'
        )->execute([
            'payment_status' => $payment,
            'booking_status' => $booking,
            'fulfillment_status' => $fulfillment,
            'status' => $legacy,
            'id' => $bookingId,
        ]);
    }

    public function canCustomerCancel(array $booking): bool
    {
        $bookingStatus = booking_lifecycle_status($booking);
        $fulfillment = booking_fulfillment_status($booking);

        if (in_array($bookingStatus, ['booking_cancelled', 'booking_closed', 'booking_cancellation_pending'], true)) {
            return false;
        }

        if ($fulfillment === 'fulfillment_with_customer') {
            return false;
        }

        if (in_array($fulfillment, ['shipping_received', 'shipping_intransit'], true)) {
            return false;
        }

        return true;
    }

    public function canHandOutHardware(array $booking): bool
    {
        $payment = booking_payment_status($booking);
        if (!BookingStatuses::isPaidPaymentStatus($payment)) {
            return false;
        }

        return in_array(booking_lifecycle_status($booking), ['booking_confirmed', 'booking_active'], true);
    }

    /** @param array<string, mixed> $row */
    public function legacyStatus(string $payment, string $booking, string $fulfillment, array $row = []): string
    {
        if ($booking === 'booking_cancelled') {
            return 'cancelled';
        }
        if ($booking === 'booking_cancellation_pending') {
            return match ($payment) {
                'payment_deposit_scheduled', 'payment_square_rental_captured' => 'booked_deposit_pending',
                'payment_square_full_captured', 'payment_etransfer_confirmed' => 'confirmed',
                default => 'confirmed',
            };
        }
        if ($booking === 'booking_closed') {
            return 'returned';
        }
        if ($booking === 'booking_late') {
            return 'late';
        }
        if ($booking === 'booking_active') {
            if (($row['fulfillment_type'] ?? '') === 'mail_ship' || str_starts_with($fulfillment, 'shipping_')) {
                return 'shipped';
            }

            return 'picked_up';
        }
        if ($fulfillment === 'fulfillment_staged') {
            return 'staged';
        }
        if ($booking === 'booking_pending_payment') {
            return 'pending_payment';
        }

        return match ($payment) {
            'payment_deposit_scheduled', 'payment_square_rental_captured' => 'booked_deposit_pending',
            'payment_deposit_scheduled_failed' => 'deposit_failed',
            'payment_deposit_scheduled_processed' => 'ready_for_pickup',
            'payment_square_full_captured', 'payment_etransfer_confirmed', 'payment_etransfer_partial_confirmed', 'payment_waived' => 'confirmed',
            default => 'pending_payment',
        };
    }

    /** @return ?array<string, mixed> */
    private function fetchRow(int $bookingId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM bookings WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $bookingId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array{payment_status: string, booking_status: string, fulfillment_status: string} */
    public function mapLegacyRow(array $row): array
    {
        $legacy = (string) ($row['status'] ?? 'pending_payment');
        $method = (string) ($row['payment_method'] ?? 'square');
        $flow = (string) ($row['square_payment_flow'] ?? '');

        $payment = match (true) {
            $legacy === 'cancelled' && $method === 'etransfer' => 'payment_etransfer_refunded',
            $legacy === 'cancelled' => 'payment_square_refunded',
            $legacy === 'pending_payment' && $method === 'etransfer' && !empty($row['etransfer_notified_at']) => 'payment_etransfer_sent',
            $legacy === 'pending_payment' && $method === 'etransfer' => 'payment_pending_etransfer',
            $legacy === 'pending_payment' => 'payment_pending_square',
            $legacy === 'booked_deposit_pending' => 'payment_deposit_scheduled',
            $legacy === 'deposit_failed' => 'payment_deposit_scheduled_failed',
            $legacy === 'ready_for_pickup' && $flow === 'long_term_capture' => 'payment_square_full_captured',
            $legacy === 'ready_for_pickup' => 'payment_deposit_scheduled_processed',
            in_array($legacy, ['confirmed', 'staged', 'picked_up', 'shipped', 'late', 'returned'], true) && $method === 'etransfer' => 'payment_etransfer_confirmed',
            in_array($legacy, ['confirmed', 'staged', 'picked_up', 'shipped', 'late', 'returned'], true) && $flow === 'long_term_capture' => 'payment_square_full_captured',
            in_array($legacy, ['confirmed', 'staged', 'picked_up', 'shipped', 'late', 'returned'], true) => 'payment_deposit_scheduled_processed',
            default => 'payment_pending_square',
        };

        $booking = match ($legacy) {
            'cancelled' => 'booking_cancelled',
            'returned' => 'booking_closed',
            'late' => 'booking_late',
            'picked_up', 'shipped' => 'booking_active',
            'pending_payment' => 'booking_pending_payment',
            default => 'booking_confirmed',
        };

        $fulfillment = match ($legacy) {
            'staged' => 'fulfillment_staged',
            'picked_up', 'shipped', 'late' => 'fulfillment_with_customer',
            'returned' => 'fulfillment_return_confirmed',
            'cancelled' => 'fulfillment_pending',
            default => 'fulfillment_pending',
        };

        if ($legacy === 'shipped') {
            $fulfillment = 'shipping_received';
        }

        return [
            'payment_status' => $payment,
            'booking_status' => $booking,
            'fulfillment_status' => $fulfillment,
        ];
    }
}

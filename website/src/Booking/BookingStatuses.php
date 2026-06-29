<?php

declare(strict_types=1);

namespace Starlink\Booking;

final class BookingStatuses
{
    /** @var list<string> */
    public const FULFILLMENT_TYPES = ['pickup', 'pickup_appointment', 'mail_ship', 'city_delivery'];

    /** @var list<string> */
    public const PAID_PAYMENT_STATUSES = [
        'payment_etransfer_confirmed',
        'payment_square_full_captured',
        'payment_deposit_scheduled_processed',
        'payment_waived',
    ];

    public const BOOKING_KIND_CUSTOMER = 'customer';
    public const BOOKING_KIND_OWNER_BLOCK = 'owner_block';
    public const BOOKING_KIND_PARTNER_PERSONAL = 'partner_personal';

    /** @var list<string> */
    public const ACTIVE_BOOKING_STATUSES = [
        'booking_pending_payment',
        'booking_confirmed',
        'booking_active',
        'booking_late',
    ];

    /** @var list<string> */
    public const BLOCKING_BOOKING_STATUSES = [
        'booking_pending_payment',
        'booking_confirmed',
        'booking_active',
        'booking_late',
        'booking_cancellation_pending',
    ];

    /** @var list<string> */
    public const LEGACY_OPEN_STATUSES = [
        'pending_payment',
        'booked_deposit_pending',
        'deposit_failed',
        'ready_for_pickup',
        'confirmed',
        'staged',
        'picked_up',
        'shipped',
        'late',
    ];

    public static function normalizeFulfillmentType(string $type): string
    {
        return match ($type) {
            'store_pickup' => 'pickup',
            'home_appointment' => 'pickup_appointment',
            default => $type,
        };
    }

    public static function normalizeAppointmentStatus(string $status): string
    {
        return match ($status) {
            'n/a' => 'appointment_na',
            'awaiting_admin' => 'appointment_awaiting_admin',
            'proposed' => 'appointment_proposed',
            'confirmed' => 'appointment_confirmed',
            default => $status,
        };
    }

    public static function isPaidPaymentStatus(string $paymentStatus): bool
    {
        return in_array($paymentStatus, self::PAID_PAYMENT_STATUSES, true);
    }

    public static function isOwnerBlockKind(string $kind): bool
    {
        return $kind === self::BOOKING_KIND_OWNER_BLOCK;
    }

    public static function isPartnerPersonalKind(string $kind): bool
    {
        return $kind === self::BOOKING_KIND_PARTNER_PERSONAL;
    }

    public static function customerLabel(string $bookingStatus): string
    {
        return match ($bookingStatus) {
            'booking_pending_payment' => 'Pending',
            'booking_confirmed', 'booking_active', 'booking_late' => 'Confirmed',
            'booking_cancellation_pending' => 'Cancellation pending',
            'booking_cancelled' => 'Cancelled',
            'booking_closed' => 'Completed',
            default => str_replace('_', ' ', $bookingStatus),
        };
    }
}

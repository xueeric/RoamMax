<?php

declare(strict_types=1);

namespace Starlink\Services;

final class PaymentService
{
    public function __construct(
        private readonly SquareService $square = new SquareService(),
    ) {
    }

    public function isSquareAvailable(): bool
    {
        return $this->square->isConfigured();
    }

    public function etransferEmail(): string
    {
        return (string) config('payments.etransfer_email', 'payments@roammax.ca');
    }

    public function normalizeMethod(string $method): ?string
    {
        $method = strtolower(trim($method));

        return in_array($method, ['square', 'etransfer'], true) ? $method : null;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, url?: string, mode?: string, message?: string}
     */
    public function startCheckout(array $booking, BookingService $bookings): array
    {
        $method = (string) ($booking['payment_method'] ?? 'square');
        if ($method === 'etransfer') {
            return [
                'ok' => true,
                'mode' => 'etransfer',
                'url' => route_path('booking/etransfer') . '?booking_id=' . (int) $booking['id'],
            ];
        }

        return $this->square->checkoutUrlForBooking($booking);
    }

    public function etransferMemo(array $booking): string
    {
        return booking_reference($booking);
    }
}

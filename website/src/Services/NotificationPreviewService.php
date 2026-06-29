<?php

declare(strict_types=1);

namespace Starlink\Services;

final class NotificationPreviewService
{
    public function __construct(
        private readonly NotificationRuleService $rules = new NotificationRuleService(),
        private readonly BookingService $bookings = new BookingService(),
        private readonly BrevoMailService $mail = new BrevoMailService(),
        private readonly CustomerBookingEmailComposer $customerEmail = new CustomerBookingEmailComposer(),
    ) {
    }

    /**
     * @return array{
     *   event_key: string,
     *   label: string,
     *   subject: string,
     *   body: string,
     *   html: string,
     *   customer_enabled: bool,
     *   admin_enabled: bool
     * }
     */
    public function preview(string $eventKey, int $bookingId = 0): array
    {
        $rule = $this->rules->ruleFor($eventKey);
        if ($rule === null) {
            throw new \InvalidArgumentException('Unknown notification event.');
        }

        $booking = $this->sampleBooking($bookingId);
        $context = $this->sampleContext($booking, $eventKey);
        $template = $this->rules->render($eventKey, $booking, $context);

        $html = $this->mail->renderHtml($template['body']);
        $plain = $template['body'];
        if (CustomerBookingEmailComposer::supports($eventKey) && (int) ($booking['id'] ?? 0) > 0) {
            $composed = $this->customerEmail->compose($eventKey, $booking, $template['body'], $context);
            $html = $composed['html'];
            $plain = $composed['plain'];
        }

        return [
            'event_key' => $eventKey,
            'label' => (string) ($rule['label'] ?? $eventKey),
            'subject' => $template['subject'],
            'body' => $plain,
            'html' => $html,
            'customer_enabled' => (int) ($rule['notify_customer_email'] ?? 0) === 1,
            'admin_enabled' => (int) ($rule['notify_admin_email'] ?? 0) === 1,
        ];
    }

    /** @return array<string, mixed> */
    public function sampleBooking(int $bookingId = 0): array
    {
        if ($bookingId > 0) {
            $booking = $this->bookings->findBooking($bookingId);
            if ($booking !== null) {
                return $booking;
            }
        }

        return [
            'id' => 999,
            'reference_code' => 'PREVIEW',
            'customer_name' => 'Mylene',
            'customer_email' => 'customer@example.com',
            'start_date' => (new \DateTimeImmutable('+14 days'))->format('Y-m-d'),
            'end_date' => (new \DateTimeImmutable('+17 days'))->format('Y-m-d'),
            'location_name' => 'Edmonton North',
            'location_address' => '123 Sample Street',
            'location_city' => 'Edmonton',
            'location_province' => 'AB',
            'location_latitude' => 53.6312,
            'location_longitude' => -113.5419,
            'fulfillment_type' => 'pickup',
            'payment_method' => 'etransfer',
            'payment_status' => 'payment_etransfer_confirmed',
            'booking_status' => 'booking_confirmed',
            'fulfillment_status' => 'fulfillment_pending',
            'rental_total_cents' => 12000,
            'shipping_fee_cents' => 0,
            'add_ons_total_cents' => 0,
            'tax_cents' => 600,
            'deposit_cents' => 35000,
        ];
    }

    /** @param array<string, mixed> $booking @return array<string, string> */
    public function sampleContext(array $booking, string $eventKey = ''): array
    {
        $reason = 'Preview cancellation reason.';
        if (in_array($eventKey, ['appointment_confirmed', 'appointment_proposed'], true)) {
            $reason = 'Your home pickup window is Jun 12, 2026 from 14:00 to 15:00.'
                . ' Looking forward to seeing you! Just knock on the door when you arrive.'
                . ' Ring the side doorbell please.';
        }

        return [
            'reason' => $reason,
            'amount' => '$150.00',
            'balance_message' => 'Remaining balance is due before pickup.',
            'action_url' => rtrim((string) config('url'), '/') . '/booking/update-card?booking_id=' . (int) ($booking['id'] ?? 0),
            'refund' => '$420.00',
            'fee' => '$50.00',
            'request_id' => '42',
            'day_count' => '45',
            'location_name' => (string) ($booking['location_name'] ?? 'Edmonton North'),
            'fulfillment_type' => 'store pickup',
            'notes_suffix' => ' Notes: Sample long-term request.',
        ];
    }
}

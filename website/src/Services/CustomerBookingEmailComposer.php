<?php

declare(strict_types=1);

namespace Starlink\Services;

final class CustomerBookingEmailComposer
{
    /** @var list<string> */
    private const RICH_EVENTS = [
        'booking_admin_created',
        'booking_cancellation_rejected',
        'booking_cancelled_by_admin',
        'booking_closed',
        'payment_checkout_failed',
        'payment_etransfer_partial_confirmed',
        'payment_etransfer_confirmed',
        'payment_square_rental_captured',
        'payment_square_full_captured',
        'payment_deposit_scheduled_processed',
        'payment_deposit_scheduled_failed',
        'payment_refunded',
        'payment_late_fee_charged',
        'fulfillment_with_customer',
        'fulfillment_shipped',
        'fulfillment_return_confirmed',
        'fulfillment_return_failed',
        'appointment_proposed',
        'appointment_confirmed',
    ];

    public function __construct(
        private readonly BrevoMailService $mail = new BrevoMailService(),
        private readonly BookingService $bookings = new BookingService(),
    ) {
    }

    public static function supports(string $eventKey): bool
    {
        return in_array($eventKey, self::RICH_EVENTS, true);
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $context
     * @return array{html: string, plain: string}
     */
    public function compose(string $eventKey, array $booking, string $plainBody = '', array $context = []): array
    {
        $headline = $this->headline($eventKey, $booking);
        $note = $this->shouldIncludeTemplateNote($eventKey) ? trim($plainBody) : '';
        if ($note !== '' && !str_contains(strtolower($note), strtolower($headline))) {
            $noteHtml = '<p style="margin:16px 0 0;font-size:15px;color:#374151;">' . $this->e($note) . '</p>';
            $notePlain = "\n\n" . $note;
        } else {
            $noteHtml = '';
            $notePlain = $note !== '' ? "\n\n" . $note : '';
        }

        if ($this->isAppointmentEvent($eventKey)) {
            return $this->composeAppointmentEmail($eventKey, $booking, $headline, $plainBody, $context);
        }

        $sectionsHtml = $this->reservationSection($booking)
            . $this->priceSection($booking)
            . $this->paymentSection($booking, $eventKey, $context)
            . $this->wifiSection($booking, $eventKey)
            . $this->footerSection();

        $inner = <<<HTML
<p style="margin:0;font-size:17px;font-weight:600;color:#111827;line-height:1.4;">{$this->e($headline)}</p>
{$noteHtml}
{$sectionsHtml}
HTML;

        $plain = $headline . $notePlain . "\n\n"
            . $this->reservationSectionPlain($booking)
            . $this->priceSectionPlain($booking)
            . $this->paymentSectionPlain($booking, $eventKey)
            . $this->wifiSectionPlain($booking, $eventKey)
            . $this->footerSectionPlain();

        return [
            'html' => $this->mail->wrapContent($inner),
            'plain' => trim($plain),
        ];
    }

    /** @param array<string, mixed> $booking */
    private function headline(string $eventKey, array $booking): string
    {
        $name = $this->firstName((string) ($booking['customer_name'] ?? ''));
        $location = trim((string) ($booking['location_name'] ?? ''));
        if ($location === '') {
            $location = 'your location';
        }

        return match ($eventKey) {
            'payment_etransfer_confirmed', 'payment_square_full_captured', 'payment_square_rental_captured' =>
                "Thanks, {$name}! Your booking in {$location} is confirmed.",
            'payment_deposit_scheduled_processed' =>
                "Thanks, {$name}! Your booking in {$location} is ready for pickup.",
            'booking_admin_created' =>
                "Hi {$name}! Your RoamMax booking in {$location} has been created.",
            'appointment_confirmed' =>
                "Thanks, {$name}! Your pickup appointment in {$location} is confirmed.",
            'appointment_proposed' =>
                "Hi {$name}! We proposed a pickup window for your booking in {$location}.",
            'fulfillment_shipped' =>
                "Thanks, {$name}! Your RoamMax unit for {$location} has shipped.",
            'fulfillment_with_customer' =>
                "Thanks, {$name}! Your RoamMax rental in {$location} is now with you.",
            'fulfillment_return_confirmed' =>
                "Thanks, {$name}! Your return for {$location} passed inspection.",
            'fulfillment_return_failed' =>
                "Hi {$name}, we need to follow up about your return in {$location}.",
            'booking_closed' =>
                "Thanks, {$name}! Your rental in {$location} is complete.",
            'booking_cancelled_by_admin' =>
                "Hi {$name}, your booking in {$location} was cancelled.",
            'booking_cancellation_rejected' =>
                "Hi {$name}, we could not approve the cancellation for your booking in {$location}.",
            'payment_deposit_scheduled_failed' =>
                "Hi {$name}, we could not authorize the deposit for your booking in {$location}.",
            'payment_checkout_failed' =>
                "Hi {$name}, we could not process payment for your booking in {$location}.",
            'payment_etransfer_partial_confirmed' =>
                "Thanks, {$name}! We received a partial payment for your booking in {$location}.",
            'payment_refunded' =>
                "Hi {$name}, a refund was processed for your booking in {$location}.",
            'payment_late_fee_charged' =>
                "Hi {$name}, a late fee was applied to your booking in {$location}.",
            default => "Thanks, {$name}! Update for your booking in {$location}.",
        };
    }

    /** @param array<string, mixed> $booking */
    private function reservationSection(array $booking): string
    {
        return $this->sectionHtml('Reservation details', $this->reservationRows($booking), $booking);
    }

    /** @param array<string, mixed> $booking */
    private function reservationSectionPlain(array $booking): string
    {
        $rows = $this->reservationRows($booking);
        $rows[2] = ['Location', $this->locationPlain($booking)];

        return $this->sectionPlain('Reservation details', $rows);
    }

    /** @param array<string, mixed> $booking @return list<array{0: string, 1: string, 2?: bool}> */
    private function reservationRows(array $booking): array
    {
        $start = $this->formatDate((string) ($booking['start_date'] ?? ''));
        $end = $this->formatDate((string) ($booking['end_date'] ?? ''));
        $dates = $start !== '' && $end !== '' ? "{$start} – {$end}" : ($start !== '' ? $start : '—');
        $days = $this->bookings->rentalDayCount($booking);
        if ($days > 0) {
            $dates .= " ({$days} day" . ($days === 1 ? '' : 's') . ')';
        }

        return [
            ['Reference', booking_reference($booking)],
            ['Dates', $dates],
            ['Location', '', true],
            ['Fulfillment', $this->fulfillmentLabel($booking)],
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $context
     * @return array{html: string, plain: string}
     */
    private function composeAppointmentEmail(
        string $eventKey,
        array $booking,
        string $headline,
        string $plainBody,
        array $context,
    ): array {
        $message = trim((string) ($context['reason'] ?? ''));
        if ($message === '') {
            $message = $this->buildAppointmentFallbackMessage($booking, $eventKey, $plainBody);
        }

        $messageHtml = $message !== ''
            ? '<p style="margin:16px 0 0;font-size:15px;color:#374151;line-height:1.5;">'
                . nl2br($this->e($message))
                . '</p>'
            : '';

        $inner = <<<HTML
<p style="margin:0;font-size:17px;font-weight:600;color:#111827;line-height:1.4;">{$this->e($headline)}</p>
{$messageHtml}
{$this->wifiSection($booking, $eventKey)}
{$this->footerSection()}
HTML;

        $plain = $headline
            . ($message !== '' ? "\n\n" . $message : '')
            . $this->wifiSectionPlain($booking, $eventKey)
            . $this->footerSectionPlain();

        return [
            'html' => $this->mail->wrapContent($inner),
            'plain' => trim($plain),
        ];
    }

    /** @param array<string, mixed> $booking */
    private function buildAppointmentFallbackMessage(array $booking, string $eventKey, string $plainBody): string
    {
        $parts = [];
        $prefix = $eventKey === 'appointment_proposed' ? 'proposed' : 'confirmed';
        $date = trim((string) ($booking[$prefix . '_pickup_date'] ?? ''));
        $timeStart = trim((string) ($booking[$prefix . '_pickup_time_start'] ?? ''));
        $timeEnd = trim((string) ($booking[$prefix . '_pickup_time_end'] ?? ''));

        if ($date !== '') {
            $window = format_pickup_window_phrase($date, $timeStart, $timeEnd, 'Your pickup window is');
            if ($window !== '') {
                $parts[] = $window;
            }
        }

        $adminMessage = trim((string) ($booking['admin_pickup_message'] ?? ''));
        if ($adminMessage !== '') {
            $parts[] = $adminMessage;
        }

        if ($parts === []) {
            return trim($plainBody);
        }

        return implode(' ', $parts);
    }

    private function isAppointmentEvent(string $eventKey): bool
    {
        return in_array($eventKey, ['appointment_confirmed', 'appointment_proposed'], true);
    }

    /** @param array<string, mixed> $booking */
    private function locationPlain(array $booking): string
    {
        $lines = array_values(array_filter([
            trim((string) ($booking['location_name'] ?? '')),
            $this->locationAddressLine($booking),
        ]));
        $mapsUrl = $this->googleMapsUrl($booking);
        if ($mapsUrl !== '') {
            $lines[] = $mapsUrl;
        }

        return $lines !== [] ? implode("\n", $lines) : '—';
    }

    /** @param array<string, mixed> $booking */
    private function locationHtml(array $booking): string
    {
        $parts = [];
        $name = trim((string) ($booking['location_name'] ?? ''));
        if ($name !== '') {
            $parts[] = $this->e($name);
        }
        $address = $this->locationAddressLine($booking);
        if ($address !== '') {
            $parts[] = $this->e($address);
        }
        $mapsUrl = $this->googleMapsUrl($booking);
        if ($mapsUrl !== '') {
            $parts[] = '<a href="' . $this->e($mapsUrl) . '" style="color:#2563eb;text-decoration:underline;">Open in Google Maps</a>';
        }

        return $parts !== [] ? implode('<br>', $parts) : '—';
    }

    /** @param array<string, mixed> $booking */
    private function locationAddressLine(array $booking, bool $includeName = false): string
    {
        $parts = [];
        if ($includeName) {
            $name = trim((string) ($booking['location_name'] ?? ''));
            if ($name !== '') {
                $parts[] = $name;
            }
        }
        $address = trim((string) ($booking['location_address'] ?? ''));
        if ($address !== '') {
            $parts[] = $address;
        }
        $city = trim((string) ($booking['location_city'] ?? ''));
        $province = trim((string) ($booking['location_province'] ?? ''));
        $cityLine = $city !== '' ? $city . ($province !== '' ? ', ' . $province : '') : '';
        if ($cityLine !== '') {
            $parts[] = $cityLine;
        }

        return implode(', ', $parts);
    }

    /** @param array<string, mixed> $booking */
    private function googleMapsUrl(array $booking): string
    {
        $lat = $booking['location_latitude'] ?? null;
        $lng = $booking['location_longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            return 'https://www.google.com/maps/search/?api=1&query='
                . rawurlencode((string) $lat . ',' . (string) $lng);
        }

        $query = $this->locationAddressLine($booking, true);
        if ($query === '') {
            return '';
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }

    /** @param array<string, mixed> $booking */
    private function priceSection(array $booking): string
    {
        return $this->sectionHtml('Price details', $this->priceRows($booking));
    }

    /** @param array<string, mixed> $booking */
    private function priceSectionPlain(array $booking): string
    {
        return $this->sectionPlain('Price details', $this->priceRows($booking));
    }

    /** @param array<string, mixed> $booking @return list<array{0: string, 1: string}> */
    private function priceRows(array $booking): array
    {
        $rental = (int) ($booking['rental_total_cents'] ?? 0);
        $shipping = (int) ($booking['shipping_fee_cents'] ?? 0);
        $addOns = (int) ($booking['add_ons_total_cents'] ?? 0);
        $tax = (int) ($booking['tax_cents'] ?? 0);
        $deposit = (int) ($booking['deposit_cents'] ?? 0);
        $rentalSubtotal = $rental + $shipping + $addOns + $tax;
        $total = $this->bookings->totalDueCents($booking);

        $rows = [
            ['Rental', PricingService::formatMoney($rental)],
        ];
        if ($shipping > 0) {
            $rows[] = ['Shipping / delivery', PricingService::formatMoney($shipping)];
        }
        if ($addOns > 0) {
            $rows[] = ['Add-ons', PricingService::formatMoney($addOns)];
        }
        if ($tax > 0) {
            $rows[] = ['Tax', PricingService::formatMoney($tax)];
        }
        $rows[] = ['Rental subtotal', PricingService::formatMoney($rentalSubtotal)];
        if ($deposit > 0) {
            $rows[] = ['Refundable deposit', PricingService::formatMoney($deposit)];
        }
        $rows[] = ['Total', PricingService::formatMoney($total)];

        return $rows;
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $context
     */
    private function paymentSection(array $booking, string $eventKey, array $context): string
    {
        $rows = $this->paymentRows($booking, $eventKey, $context);
        $html = $this->sectionHtml('Payment info', $rows);

        if ($this->shouldShowPaymentAction($eventKey)) {
            $actionUrl = trim((string) ($context['action_url'] ?? ''));
            if ($actionUrl !== '' && filter_var($actionUrl, FILTER_VALIDATE_URL)) {
                $html .= '<p style="margin:16px 0 0;">'
                    . '<a href="' . $this->e($actionUrl) . '" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 18px;border-radius:6px;">Update payment method</a>'
                    . '</p>';
            }
        }

        return $html;
    }

    private function shouldShowPaymentAction(string $eventKey): bool
    {
        return in_array($eventKey, [
            'payment_deposit_scheduled_failed',
            'payment_checkout_failed',
        ], true);
    }

    /** @param array<string, mixed> $booking */
    private function paymentSectionPlain(array $booking, string $eventKey): string
    {
        return $this->sectionPlain('Payment info', $this->paymentRows($booking, $eventKey, []));
    }

    /** @param array<string, mixed> $booking */
    private function wifiSection(array $booking, string $eventKey): string
    {
        if (!$this->shouldIncludeWifi($eventKey)) {
            return '';
        }

        $wifi = booking_wifi_credentials($booking);
        if ($wifi === null) {
            return '';
        }

        return $this->sectionHtml('WiFi access', [
            ['Network', $wifi['ssid']],
            ['Password', $wifi['password']],
        ]);
    }

    /** @param array<string, mixed> $booking */
    private function wifiSectionPlain(array $booking, string $eventKey): string
    {
        if (!$this->shouldIncludeWifi($eventKey)) {
            return '';
        }

        $wifi = booking_wifi_credentials($booking);
        if ($wifi === null) {
            return '';
        }

        return $this->sectionPlain('WiFi access', [
            ['Network', $wifi['ssid']],
            ['Password', $wifi['password']],
        ]);
    }

    private function shouldIncludeWifi(string $eventKey): bool
    {
        return in_array($eventKey, [
            'payment_etransfer_confirmed',
            'payment_square_rental_captured',
            'payment_square_full_captured',
            'payment_deposit_scheduled_processed',
            'fulfillment_shipped',
            'fulfillment_with_customer',
            'appointment_confirmed',
        ], true);
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $context
     * @return list<array{0: string, 1: string}>
     */
    private function paymentRows(array $booking, string $eventKey, array $context): array
    {
        $pay = booking_admin_payment_summary($booking);

        $rows = [
            ['Method', (string) ($pay['method'] ?? '—')],
            ['Rental', (string) ($pay['rental_state'] ?? '—')],
        ];
        if ((int) ($pay['deposit_cents'] ?? 0) > 0) {
            $rows[] = ['Deposit', (string) ($pay['deposit_state'] ?? '—')];
        }
        $rows[] = ['Booking status', ucwords(str_replace('_', ' ', (string) ($pay['status_label'] ?? '—')))];

        $amount = trim((string) ($context['amount'] ?? ''));
        if ($amount !== '' && in_array($eventKey, ['payment_etransfer_partial_confirmed', 'payment_late_fee_charged'], true)) {
            $rows[] = ['Amount', $amount];
        }

        return $rows;
    }

    private function footerSection(): string
    {
        $contactName = trim((string) config('mail.support_contact_name', 'Eric'));
        $phone = trim((string) config('mail.support_phone', '780-709-9939'));
        $referralUrl = trim((string) config('mail.referral_url', ''));
        $brand = trim((string) config('name', 'RoamMax'));

        $support = 'If you have any questions, please reply to this email'
            . ($phone !== '' ? ' or message ' . $contactName . ' at ' . $phone : '')
            . '.';

        $referralHtml = '';
        if ($referralUrl !== '' && filter_var($referralUrl, FILTER_VALIDATE_URL)) {
            $referralHtml = <<<HTML
<div style="margin-top:20px;padding:16px;background:#f3f4f6;border-radius:8px;border:1px solid #e5e7eb;">
  <div style="font-size:14px;font-weight:600;color:#111827;margin-bottom:6px;">Want your own Starlink?</div>
  <div style="font-size:14px;color:#374151;margin-bottom:10px;">Use our referral link and get 1 month free when you sign up.</div>
  <a href="{$this->e($referralUrl)}" style="font-size:14px;color:#2563eb;text-decoration:underline;word-break:break-all;">{$this->e($referralUrl)}</a>
</div>
HTML;
        }

        return <<<HTML
<div style="margin-top:28px;padding-top:20px;border-top:1px solid #e5e7eb;">
  <p style="margin:0 0 12px;font-size:14px;color:#374151;">{$this->e($support)}</p>
  <p style="margin:0;font-size:13px;color:#6b7280;">Thank you for choosing {$this->e($brand)}.</p>
</div>
{$referralHtml}
HTML;
    }

    private function footerSectionPlain(): string
    {
        $contactName = trim((string) config('mail.support_contact_name', 'Eric'));
        $phone = trim((string) config('mail.support_phone', '780-709-9939'));
        $referralUrl = trim((string) config('mail.referral_url', ''));
        $brand = trim((string) config('name', 'RoamMax'));

        $plain = "\n\nIf you have any questions, please reply to this email";
        if ($phone !== '') {
            $plain .= " or message {$contactName} at {$phone}";
        }
        $plain .= ".\n\nThank you for choosing {$brand}.";
        if ($referralUrl !== '') {
            $plain .= "\n\nWant your own Starlink? Use our referral link for 1 month free:\n{$referralUrl}";
        }

        return $plain;
    }

    /** @param list<array{0: string, 1: string, 2?: bool}> $rows */
    private function sectionHtml(string $title, array $rows, ?array $booking = null): string
    {
        $items = '';
        foreach ($rows as $row) {
            [$label, $value] = $row;
            $useLocationHtml = (bool) ($row[2] ?? false) && $label === 'Location' && $booking !== null;
            if ($useLocationHtml) {
                $cell = $this->locationHtml($booking);
            } elseif ($value === '') {
                continue;
            } else {
                $cell = $this->e($value);
            }
            $items .= '<tr>'
                . '<td style="padding:5px 12px 5px 0;color:#6b7280;width:38%;vertical-align:top;font-size:14px;">' . $this->e($label) . '</td>'
                . '<td style="padding:5px 0;color:#111827;vertical-align:top;font-size:14px;">' . $cell . '</td>'
                . '</tr>';
        }

        if ($items === '') {
            return '';
        }

        return <<<HTML
<div style="margin-top:22px;">
  <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#374151;margin-bottom:10px;">{$this->e($title)}</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #f3f4f6;">{$items}</table>
</div>
HTML;
    }

    /** @param list<array{0: string, 1: string}> $rows */
    private function sectionPlain(string $title, array $rows): string
    {
        $lines = [$title];
        foreach ($rows as [$label, $value]) {
            if ($value === '') {
                continue;
            }
            $lines[] = "  {$label}: {$value}";
        }

        return count($lines) > 1 ? "\n" . implode("\n", $lines) . "\n" : '';
    }

    /** @param array<string, mixed> $booking */
    private function fulfillmentLabel(array $booking): string
    {
        $type = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType(
            (string) ($booking['fulfillment_type'] ?? 'pickup'),
        );

        return match ($type) {
            'mail_ship' => 'Mail shipping',
            'city_delivery' => 'City delivery',
            'pickup_appointment' => 'Pickup (appointment)',
            default => 'Store pickup',
        };
    }

    private function formatDate(string $iso): string
    {
        $date = parse_date($iso);

        return $date !== null ? $date->format('M j, Y') : '';
    }

    private function firstName(string $fullName): string
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return 'there';
        }

        $parts = preg_split('/\s+/', $fullName);

        return is_array($parts) && $parts[0] !== '' ? $parts[0] : $fullName;
    }

    private function shouldIncludeTemplateNote(string $eventKey): bool
    {
        return !in_array($eventKey, [
            'payment_etransfer_confirmed',
            'payment_square_full_captured',
            'payment_square_rental_captured',
            'payment_deposit_scheduled_processed',
            'fulfillment_with_customer',
            'fulfillment_shipped',
            'fulfillment_return_confirmed',
            'booking_closed',
            'appointment_confirmed',
        ], true);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

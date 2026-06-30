<?php

declare(strict_types=1);

namespace Starlink\Services;

final class CustomerBookingPresenter
{
    /**
     * @param array<string, mixed> $booking
     * @return array{
     *   headline: string,
     *   lines: list<string>,
     *   address_block: ?string
     * }
     */
    public function pickupSection(array $booking): array
    {
        $type = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType((string) ($booking['fulfillment_type'] ?? 'pickup'));
        $locationName = (string) ($booking['location_name'] ?? '');
        $address = trim((string) ($booking['location_address'] ?? ''));
        $city = trim((string) ($booking['location_city'] ?? ''));
        $province = trim((string) ($booking['location_province'] ?? ''));
        $instructions = trim((string) ($booking['pickup_instructions'] ?? ''));

        $cityLine = $city !== '' ? $city . ($province !== '' ? ', ' . $province : '') : '';

        return match ($type) {
            'mail_ship' => $this->shippingSection($booking, 'Shipping address'),
            'city_delivery' => $this->shippingSection($booking, 'Delivery address'),
            'pickup_appointment' => [
                'headline' => 'Pickup (appointment)',
                'lines' => array_values(array_filter([
                    $locationName !== '' ? $locationName : null,
                    $address !== '' ? $address : null,
                    $cityLine !== '' ? $cityLine : null,
                    $this->appointmentLine($booking),
                    $instructions !== '' ? $instructions : null,
                ])),
                'address_block' => null,
            ],
            default => [
                'headline' => 'Pickup location',
                'lines' => array_values(array_filter([
                    $locationName !== '' ? $locationName : null,
                    $address !== '' ? $address : null,
                    $cityLine !== '' ? $cityLine : null,
                    $instructions !== '' ? $instructions : null,
                ])),
                'address_block' => null,
            ],
        };
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{headline: string, lines: list<string>}|null
     */
    public function wifiSection(array $booking): ?array
    {
        if (!booking_should_show_wifi($booking)) {
            return null;
        }

        $wifi = booking_wifi_credentials($booking);
        if ($wifi === null) {
            return null;
        }

        return [
            'headline' => 'WiFi access',
            'lines' => [
                'Network: ' . $wifi['ssid'],
                'Password: ' . $wifi['password'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @param list<array<string, mixed>> $paymentRows
     * @return list<array{label: string, amount_cents: int, status: string, detail: string, date_label: ?string}>
     */
    public function paymentTimeline(array $booking, array $paymentRows = []): array
    {
        $payment = booking_payment_status($booking);
        $lifecycle = booking_lifecycle_status($booking);
        $method = (string) ($booking['payment_method'] ?? '');
        $flow = (string) ($booking['square_payment_flow'] ?? '');

        $rentalCents = (int) $booking['rental_total_cents']
            + (int) $booking['shipping_fee_cents']
            + (int) $booking['add_ons_total_cents']
            + (int) ($booking['tax_cents'] ?? 0);
        $depositCents = (int) ($booking['deposit_cents'] ?? 0);

        $steps = [];
        $steps[] = $this->rentalStep($booking, $payment, $lifecycle, $method, $rentalCents, $paymentRows);

        if ($depositCents > 0) {
            $steps[] = $this->depositStep($booking, $payment, $lifecycle, $method, $flow, $depositCents, $paymentRows);
        }

        return array_values(array_filter($steps));
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{
     *   allowed: bool,
     *   free_cancel: bool,
     *   fee_cents: int,
     *   summary: string,
     *   details: list<string>
     * }
     */
    public function cancelPreview(array $booking): array
    {
        $state = new BookingStateService();
        $lifecycle = booking_lifecycle_status($booking);

        if ($lifecycle === 'booking_cancellation_pending') {
            $refundCents = (int) ($booking['cancellation_refund_cents'] ?? 0);

            return [
                'allowed' => false,
                'pending' => true,
                'free_cancel' => false,
                'fee_cents' => (int) ($booking['cancellation_fee_cents'] ?? 0),
                'refund_cents' => $refundCents,
                'summary' => 'Cancellation requested — waiting for admin approval.',
                'details' => [
                    'Estimated refund after approval: ' . PricingService::formatMoney($refundCents) . '.',
                    'You will receive an email when the refund is processed.',
                ],
            ];
        }

        $allowed = $state->canCustomerCancel($booking);

        if (!$allowed) {
            return [
                'allowed' => false,
                'pending' => false,
                'free_cancel' => false,
                'fee_cents' => 0,
                'refund_cents' => 0,
                'summary' => 'This booking can no longer be cancelled online.',
                'details' => [
                    'Once you have the equipment, contact us to discuss changes.',
                ],
            ];
        }

        $estimate = (new BookingService())->estimateCancellation($booking);
        $feeCents = $estimate['fee_cents'];
        $refundCents = $estimate['refund_cents'];
        $freeCancel = $estimate['free_cancel'];
        $method = (string) ($booking['payment_method'] ?? '');

        $details = [];
        if ($lifecycle === 'booking_pending_payment') {
            $details[] = 'No payment has been completed yet — the reservation will be released immediately.';
        } elseif ($estimate['requires_admin_approval']) {
            $details[] = 'Your request will be reviewed by our team before any refund is issued.';
            if ($refundCents > 0) {
                $details[] = 'Estimated refund after approval: ' . PricingService::formatMoney($refundCents) . '.';
            } else {
                $details[] = 'No rental refund is due based on the current cancellation policy.';
            }
            if ($feeCents > 0) {
                $details[] = 'Estimated cancellation fee: ' . PricingService::formatMoney($feeCents) . '.';
            }
            if ($method === 'square') {
                if (trim((string) ($booking['square_deposit_payment_id'] ?? '')) !== '') {
                    $details[] = 'Deposit hold will be released when cancellation is approved.';
                } elseif (booking_payment_status($booking) === 'payment_deposit_scheduled') {
                    $details[] = 'Scheduled deposit hold will not be placed if approved.';
                }
            } elseif ($method === 'etransfer') {
                $details[] = 'e-Transfer refunds are processed manually after approval.';
            }
        } elseif ($method === 'square' && $refundCents > 0) {
            $details[] = 'Rental and fees (' . PricingService::formatMoney($refundCents) . ') will be refunded to your card.';
        }

        $summary = $estimate['requires_admin_approval']
            ? 'Submit a cancellation request for admin approval.'
            : ($freeCancel
                ? 'Free cancellation — reservation released immediately.'
                : 'Cancellation fee may apply — see details below.');

        return [
            'allowed' => true,
            'pending' => false,
            'free_cancel' => $freeCancel,
            'fee_cents' => $feeCents,
            'refund_cents' => $refundCents,
            'requires_admin_approval' => $estimate['requires_admin_approval'],
            'summary' => $summary,
            'details' => $details,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{label: string, amount_cents: int, status: string, detail: string, date_label: ?string}
     */
    private function rentalStep(
        array $booking,
        string $payment,
        string $lifecycle,
        string $method,
        int $rentalCents,
        array $paymentRows,
    ): array {
        $checkoutPaidAt = $this->paymentRowDate($paymentRows, ['checkout', 'rental']);

        if ($lifecycle === 'booking_cancelled') {
            return [
                'label' => 'Rental & fees',
                'amount_cents' => $rentalCents,
                'status' => 'refunded',
                'detail' => 'Cancelled — refund issued or processing',
                'date_label' => $checkoutPaidAt,
            ];
        }

        if (str_starts_with($payment, 'payment_pending_')) {
            return [
                'label' => 'Rental & fees',
                'amount_cents' => $rentalCents,
                'status' => 'pending',
                'detail' => $method === 'etransfer' ? 'Awaiting e-Transfer' : 'Awaiting card payment',
                'date_label' => null,
            ];
        }

        if ($payment === 'payment_etransfer_sent') {
            return [
                'label' => 'Rental & fees',
                'amount_cents' => $rentalCents,
                'status' => 'pending',
                'detail' => 'e-Transfer sent — awaiting confirmation',
                'date_label' => null,
            ];
        }

        if ($payment === 'payment_etransfer_partial_confirmed') {
            return [
                'label' => 'Rental & fees',
                'amount_cents' => $rentalCents,
                'status' => 'pending',
                'detail' => 'Partial payment received — balance still due',
                'date_label' => $checkoutPaidAt,
            ];
        }

        $processedDate = $checkoutPaidAt ?? $this->formatCreatedDate($booking);

        return [
            'label' => 'Rental & fees',
            'amount_cents' => $rentalCents,
            'status' => 'completed',
            'detail' => $processedDate !== null ? 'Processed on ' . $processedDate : 'Processed',
            'date_label' => $processedDate,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{label: string, amount_cents: int, status: string, detail: string, date_label: ?string}
     */
    private function depositStep(
        array $booking,
        string $payment,
        string $lifecycle,
        string $method,
        string $flow,
        int $depositCents,
        array $paymentRows,
    ): array {
        $holdScheduleDate = $this->depositHoldScheduleDate($booking);
        $authAt = trim((string) ($booking['deposit_auth_at'] ?? ''));
        $authLabel = $authAt !== '' ? $this->formatDateLabel($authAt) : null;
        $refundAt = $this->paymentRowDate($paymentRows, ['deposit_refund']);

        if ($lifecycle === 'booking_cancelled') {
            return [
                'label' => 'Deposit (refundable)',
                'amount_cents' => $depositCents,
                'status' => 'refunded',
                'detail' => 'Cancelled — hold released or not placed',
                'date_label' => null,
            ];
        }

        if ($lifecycle === 'booking_closed' || $payment === 'payment_deposit_scheduled_released') {
            return [
                'label' => 'Deposit (refundable)',
                'amount_cents' => $depositCents,
                'status' => 'completed',
                'detail' => $refundAt !== null ? 'Returned on ' . $refundAt : 'Returned',
                'date_label' => $refundAt,
            ];
        }

        if ($payment === 'payment_deposit_scheduled_failed') {
            return [
                'label' => 'Deposit (refundable)',
                'amount_cents' => $depositCents,
                'status' => 'failed',
                'detail' => 'Authorization failed — update your card',
                'date_label' => $authLabel,
            ];
        }

        if ($payment === 'payment_deposit_scheduled_processed' || $authAt !== '') {
            return [
                'label' => 'Deposit (refundable)',
                'amount_cents' => $depositCents,
                'status' => 'active',
                'detail' => $authLabel !== null
                    ? 'Hold placed on ' . $authLabel
                    : 'Hold on card — released after return',
                'date_label' => $authLabel ?? $holdScheduleDate,
            ];
        }

        if ($flow === 'long_term_capture' || ($method === 'etransfer' && !str_starts_with($payment, 'payment_pending_'))) {
            $captured = in_array($payment, [
                'payment_square_full_captured',
                'payment_etransfer_confirmed',
                'payment_deposit_scheduled_processed',
            ], true);

            $paidAt = $this->paymentRowDate($paymentRows, ['checkout', 'deposit']);

            return [
                'label' => 'Deposit (refundable)',
                'amount_cents' => $depositCents,
                'status' => $captured ? 'completed' : 'pending',
                'detail' => $captured ? 'Collected — refunded after return' : 'Due with full payment',
                'date_label' => $captured ? $paidAt : null,
            ];
        }

        if ($payment === 'payment_square_rental_captured' || $payment === 'payment_deposit_scheduled') {
            return [
                'label' => 'Deposit (refundable)',
                'amount_cents' => $depositCents,
                'status' => 'scheduled',
                'detail' => $holdScheduleDate !== null
                    ? 'Hold scheduled on ' . $holdScheduleDate
                    : 'Hold scheduled 24h before pickup',
                'date_label' => $holdScheduleDate,
            ];
        }

        return [
            'label' => 'Deposit (refundable)',
            'amount_cents' => $depositCents,
            'status' => 'pending',
            'detail' => 'Due before or at pickup',
            'date_label' => null,
        ];
    }

    /** @param array<string, mixed> $booking */
    private function depositHoldScheduleDate(array $booking): ?string
    {
        $start = parse_date((string) ($booking['start_date'] ?? ''));
        if ($start === null) {
            return null;
        }

        $leadDays = (int) config('payments.deposit_lead_days', 1);
        $holdDay = $start->modify('-' . $leadDays . ' days');

        return $holdDay->format('M j, Y');
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{headline: string, lines: list<string>, address_block: ?string}
     */
    private function shippingSection(array $booking, string $headline): array
    {
        $shipping = AddressService::decode($booking['shipping_address_json'] ?? null);
        if ($shipping === null) {
            return ['headline' => $headline, 'lines' => ['Address on file'], 'address_block' => null];
        }

        $line = AddressService::formatSingleLine($shipping);

        return [
            'headline' => $headline,
            'lines' => [$line],
            'address_block' => $line,
        ];
    }

    /** @param array<string, mixed> $booking */
    private function appointmentLine(array $booking): ?string
    {
        $rawAppointment = (string) ($booking['appointment_status'] ?? '');
        $status = match ($rawAppointment) {
            'n/a', 'appointment_na' => 'appointment_na',
            'awaiting_admin', 'appointment_awaiting_admin' => 'appointment_awaiting_admin',
            'proposed', 'appointment_proposed' => 'appointment_proposed',
            'confirmed', 'appointment_confirmed' => 'appointment_confirmed',
            default => $rawAppointment,
        };
        $confirmedDate = (string) ($booking['confirmed_pickup_date'] ?? '');
        $proposedDate = (string) ($booking['proposed_pickup_date'] ?? '');

        return match ($status) {
            'appointment_confirmed' => $confirmedDate !== ''
                ? 'Pickup window: ' . $confirmedDate . $this->timeRange($booking, 'confirmed')
                . $this->adminPickupMessage($booking)
                : 'Pickup time confirmed' . $this->adminPickupMessage($booking),
            'appointment_proposed' => $proposedDate !== ''
                ? 'Proposed pickup: ' . $proposedDate . $this->timeRange($booking, 'proposed')
                : 'Pickup time proposed — please accept',
            'appointment_awaiting_admin' => $confirmedDate !== ''
                ? 'Your pickup window: ' . $confirmedDate . $this->timeRange($booking, 'confirmed') . ' — we will confirm shortly'
                : 'Pickup time — we will contact you',
            default => null,
        };
    }

    /** @param array<string, mixed> $booking */
    private function adminPickupMessage(array $booking): string
    {
        $message = trim((string) ($booking['admin_pickup_message'] ?? ''));

        return $message !== '' ? ' · ' . $message : '';
    }

    /** @param array<string, mixed> $booking */
    private function timeRange(array $booking, string $prefix): string
    {
        $start = (string) ($booking[$prefix . '_pickup_time_start'] ?? '');
        $end = (string) ($booking[$prefix . '_pickup_time_end'] ?? '');
        if ($start === '' && $end === '') {
            return '';
        }

        if ($start !== '' && ($end === '' || $end === $start)) {
            return ' · ' . format_pickup_time($start);
        }

        return ' · ' . format_pickup_time($start) . '–' . format_pickup_time($end);
    }

    /** @param list<array<string, mixed>> $rows @param list<string> $types */
    private function paymentRowDate(array $rows, array $types): ?string
    {
        foreach ($rows as $row) {
            if (!in_array((string) ($row['type'] ?? ''), $types, true)) {
                continue;
            }
            $created = (string) ($row['created_at'] ?? '');
            if ($created !== '') {
                return $this->formatDateLabel($created);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $booking */
    private function formatCreatedDate(array $booking): ?string
    {
        $created = (string) ($booking['created_at'] ?? '');

        return $created !== '' ? $this->formatDateLabel($created) : null;
    }

    private function formatDateLabel(string $iso): ?string
    {
        try {
            $dt = new \DateTimeImmutable($iso);

            return $dt->format('M j, Y');
        } catch (\Exception) {
            return null;
        }
    }
}

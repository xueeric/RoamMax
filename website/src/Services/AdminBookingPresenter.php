<?php

declare(strict_types=1);

namespace Starlink\Services;

final class AdminBookingPresenter
{
    /**
     * @param array<string, mixed> $booking
     * @return list<array{key: string, label: string, tone: string}>
     */
    public function attentionItems(array $booking): array
    {
        $items = [];
        $lifecycle = booking_lifecycle_status($booking);
        $payment = booking_payment_status($booking);
        $fulfillment = booking_fulfillment_status($booking);
        $pay = booking_admin_payment_summary($booking);
        $state = new BookingStateService();

        if ($this->isNewOrder($booking)) {
            $items[] = ['key' => 'new', 'label' => 'New order', 'tone' => 'info'];
        }

        if (booking_is_partner_personal($booking)) {
            $items[] = ['key' => 'partner_personal', 'label' => 'Partner personal trip', 'tone' => 'info'];
        }

        if (booking_is_owner_block($booking)) {
            $items[] = ['key' => 'owner_block', 'label' => 'Owner block', 'tone' => 'info'];
        }

        if ($lifecycle === 'booking_pending_payment' && !booking_is_owner_block($booking)) {
            $items[] = ['key' => 'payment', 'label' => 'Payment pending · ' . $this->ageLabel((string) ($booking['created_at'] ?? '')), 'tone' => 'urgent'];
        }

        if ((new BookingPaymentLedgerService())->hasPaymentMismatch($booking)) {
            $items[] = ['key' => 'payment_mismatch', 'label' => 'Payment not verified — reset for re-pay', 'tone' => 'urgent'];
        }

        if ($lifecycle === 'booking_cancellation_pending') {
            $refund = (int) ($booking['cancellation_refund_cents'] ?? 0);
            $label = 'Cancel requested';
            if ($refund > 0) {
                $label .= ' · est. ' . PricingService::formatMoney($refund);
            }
            $items[] = ['key' => 'cancel', 'label' => $label, 'tone' => 'urgent'];
        }

        if (in_array($payment, ['payment_etransfer_sent', 'payment_etransfer_partial_confirmed'], true) && !booking_is_owner_block($booking)) {
            $items[] = ['key' => 'etransfer', 'label' => 'e-Transfer to review', 'tone' => 'urgent'];
        }

        if ($payment === 'payment_deposit_scheduled_failed') {
            $items[] = ['key' => 'deposit_failed', 'label' => 'Deposit hold failed', 'tone' => 'urgent'];
        }

        if ($pay['release_blocked']) {
            $items[] = ['key' => 'hold', 'label' => 'Do not release — deposit pending', 'tone' => 'warn'];
        }

        if ($payment === 'payment_deposit_scheduled') {
            $items[] = ['key' => 'deposit_scheduled', 'label' => 'Deposit hold scheduled', 'tone' => 'warn'];
        }

        if ($payment === 'payment_deposit_scheduled_processed' && in_array($fulfillment, ['fulfillment_with_customer', 'shipping_received'], true)) {
            $items[] = ['key' => 'deposit_held', 'label' => 'Deposit on hold', 'tone' => 'info'];
        }

        if (
            $lifecycle === 'booking_confirmed'
            && $fulfillment === 'fulfillment_pending'
            && empty($booking['equipment_id'])
        ) {
            $items[] = ['key' => 'assign', 'label' => 'Assign unit', 'tone' => 'urgent'];
        }

        if (
            $lifecycle === 'booking_confirmed'
            && $fulfillment === 'fulfillment_pending'
            && $state->canHandOutHardware($booking)
            && !empty($booking['equipment_id'])
        ) {
            $items[] = ['key' => 'stage', 'label' => 'Stage for pickup', 'tone' => 'warn'];
        }

        if (
            $lifecycle === 'booking_confirmed'
            && $fulfillment === 'fulfillment_staged'
            && $state->canHandOutHardware($booking)
        ) {
            $items[] = ['key' => 'handout', 'label' => 'Ready to hand out', 'tone' => 'warn'];
        }

        if ($fulfillment === 'fulfillment_return_received') {
            $items[] = ['key' => 'qc', 'label' => 'Return — QC needed', 'tone' => 'warn'];
        }

        if ($lifecycle === 'booking_late') {
            $items[] = ['key' => 'late', 'label' => 'Late return · ends ' . (string) ($booking['end_date'] ?? ''), 'tone' => 'urgent'];
        } elseif ($lifecycle === 'booking_active') {
            $end = parse_date((string) ($booking['end_date'] ?? ''));
            if ($end !== null && today_date() > $end) {
                $items[] = ['key' => 'overdue', 'label' => 'Past end date', 'tone' => 'urgent'];
            }
        }

        return $items;
    }

    /**
     * Square short-term deposit admin actions.
     *
     * @param array<string, mixed> $booking
     * @return list<array{key: string, label: string, confirm?: string}>
     */
    public function depositActions(array $booking): array
    {
        if (booking_resolved_payment_context($booking)['flow'] !== 'short_term_auth') {
            return [];
        }

        $lifecycle = booking_lifecycle_status($booking);
        if (in_array($lifecycle, ['booking_cancelled', 'booking_closed', 'booking_pending_payment'], true)) {
            return [];
        }

        $depositCents = (int) ($booking['deposit_cents'] ?? 0);
        if ($depositCents <= 0 || trim((string) ($booking['square_card_id'] ?? '')) === ''
            || trim((string) ($booking['square_customer_id'] ?? '')) === '') {
            return [];
        }

        $ledger = new BookingPaymentLedgerService();
        $payment = booking_payment_status($booking);
        $depositId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
        $actions = [];

        $awaitingDeposit = $depositId === '' && in_array($payment, [
            'payment_square_rental_captured',
            'payment_deposit_scheduled',
            'payment_deposit_scheduled_failed',
            'payment_deposit_scheduled_processed',
        ], true);

        if ($awaitingDeposit) {
            $actions[] = ['key' => 'deposit_hold', 'label' => 'Authorize deposit hold'];
            $actions[] = ['key' => 'deposit_charge', 'label' => 'Charge deposit now'];
        }

        if ($depositId !== '' && $ledger->hasPendingDepositHold($booking)) {
            $actions[] = ['key' => 'deposit_capture', 'label' => 'Capture deposit hold'];
            $actions[] = [
                'key' => 'deposit_release',
                'label' => 'Release deposit hold',
                'confirm' => 'Release the deposit hold in Square? The card authorization will be cancelled.',
            ];
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{label: string, href: string}
     */
    public function primaryAction(array $booking): array
    {
        $id = (int) $booking['id'];
        $href = route_path('admin/bookings/view') . '?booking_id=' . $id;
        $lifecycle = booking_lifecycle_status($booking);
        $payment = booking_payment_status($booking);
        $fulfillment = booking_fulfillment_status($booking);
        $state = new BookingStateService();

        if ($lifecycle === 'booking_cancellation_pending') {
            return ['label' => 'Review cancel', 'href' => $href];
        }

        if ($lifecycle === 'booking_pending_payment' || in_array($payment, ['payment_etransfer_sent', 'payment_etransfer_partial_confirmed'], true)) {
            return ['label' => 'Review payment', 'href' => $href];
        }

        if ($lifecycle === 'booking_confirmed' && $fulfillment === 'fulfillment_pending') {
            if (empty($booking['equipment_id'])) {
                return ['label' => 'Assign unit', 'href' => $href . '#assign'];
            }
            if ($state->canHandOutHardware($booking)) {
                return ['label' => 'Stage', 'href' => $href . '#actions'];
            }
        }

        if ($lifecycle === 'booking_confirmed' && $fulfillment === 'fulfillment_staged' && $state->canHandOutHardware($booking)) {
            return ['label' => 'Hand out', 'href' => $href . '#actions'];
        }

        if ($fulfillment === 'fulfillment_return_received') {
            return ['label' => 'QC return', 'href' => $href . '#actions'];
        }

        if (in_array($lifecycle, ['booking_active', 'booking_late'], true)
            && in_array($fulfillment, ['fulfillment_with_customer', 'shipping_received'], true)) {
            return ['label' => 'Mark return', 'href' => $href . '#actions'];
        }

        return ['label' => 'Open', 'href' => $href];
    }

    /** @param array<string, mixed> $booking */
    public function listSubtitle(array $booking): string
    {
        $parts = [
            booking_is_owner_block($booking) ? 'Owner block' : (string) ($booking['customer_name'] ?? 'Customer'),
            (string) ($booking['start_date'] ?? '') . ' → ' . (string) ($booking['end_date'] ?? ''),
            (string) ($booking['location_name'] ?? ''),
        ];
        if (!empty($booking['equipment_name'])) {
            $parts[] = (string) $booking['equipment_name'];
        } elseif (booking_lifecycle_status($booking) !== 'booking_cancelled') {
            $parts[] = 'Unassigned';
        }

        return implode(' · ', array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    /** @param array<string, mixed> $booking */
    public function sortMeta(array $booking, ?string $statusFilter): string
    {
        return match ($statusFilter) {
            'booking_pending_payment' => 'Created ' . $this->formatShortDate((string) ($booking['created_at'] ?? '')),
            'booking_confirmed', 'booking_cancellation_pending' => 'Starts ' . (string) ($booking['start_date'] ?? ''),
            'booking_active', 'booking_late' => 'Ends ' . (string) ($booking['end_date'] ?? ''),
            default => '#' . (int) ($booking['id']),
        };
    }

    /** @param array<string, mixed> $booking */
    private function isNewOrder(array $booking): bool
    {
        if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
            return false;
        }

        $created = parse_date((string) ($booking['created_at'] ?? ''));
        if ($created === null) {
            return false;
        }

        return $created >= today_date()->modify('-2 days');
    }

    private function ageLabel(string $createdAt): string
    {
        $created = parse_date($createdAt);
        if ($created === null) {
            return '—';
        }

        $days = (int) today_date()->diff($created)->days;
        if ($days === 0) {
            return 'today';
        }
        if ($days === 1) {
            return '1 day';
        }

        return $days . ' days';
    }

    private function formatShortDate(string $iso): string
    {
        $dt = parse_date($iso);

        return $dt !== null ? $dt->format('M j') : '—';
    }
}

<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

/** @var array<string, mixed> $booking */
/** @var array<string, mixed> $pickup */
/** @var ?array{headline: string, lines: list<string>} $wifi */
/** @var list<array<string, mixed>> $paymentTimeline */
/** @var array<string, mixed> $cancelPreview */

$pageTitle = 'Booking #: ' . booking_reference($booking);
$money = static fn (int $cents): string => PricingService::formatMoney($cents);
$lifecycle = booking_lifecycle_status($booking);
$payment = booking_payment_status($booking);
$fulfillment = booking_fulfillment_status($booking);

$rentalCents = (int) $booking['rental_total_cents'];
$shippingCents = (int) $booking['shipping_fee_cents'];
$addonsCents = (int) $booking['add_ons_total_cents'];
$taxCents = (int) ($booking['tax_cents'] ?? 0);
$depositCents = (int) $booking['deposit_cents'];
$grandTotal = $rentalCents + $shippingCents + $addonsCents + $taxCents + $depositCents;

$timelineStatusClass = static fn (string $status): string => match ($status) {
    'completed' => 'payment-step--done',
    'active' => 'payment-step--active',
    'scheduled' => 'payment-step--scheduled',
    'failed' => 'payment-step--failed',
    'refunded' => 'payment-step--refunded',
    default => 'payment-step--pending',
};
?>
<div class="micro-label">Account</div>
<p class="lead" style="margin-top:0;">
    <a href="<?= escape(route_path('account/bookings')) ?>">&larr; All bookings</a>
</p>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<div class="booking-detail-header">
    <div>
        <h1 class="page-title" style="margin-bottom:0.25rem;">Booking #: <span class="mono"><?= escape(booking_reference($booking)) ?></span></h1>
        <p class="lead" style="margin:0;">
            <span class="mono"><?= escape($booking['start_date']) ?> → <?= escape($booking['end_date']) ?></span>
            · <?= escape(str_replace('_', ' ', (string) $booking['fulfillment_type'])) ?>
        </p>
    </div>
    <span class="badge badge-active"><?= escape(booking_customer_status_label($booking)) ?></span>
</div>

<div class="booking-detail-grid">
    <div class="card booking-detail-panel">
        <div class="micro-label">Pickup & fulfillment</div>
        <h2 class="booking-detail-heading"><?= escape($pickup['headline']) ?></h2>
        <?php foreach ($pickup['lines'] as $line): ?>
            <p class="booking-detail-line"><?= escape($line) ?></p>
        <?php endforeach; ?>
        <?php if (!empty($booking['equipment_name'])): ?>
            <p class="booking-detail-meta">Unit: <span class="mono"><?= escape($booking['equipment_name']) ?></span></p>
        <?php endif; ?>
        <p class="booking-detail-meta">Fulfillment: <?= escape(str_replace('_', ' ', $fulfillment)) ?></p>
    </div>

    <?php if ($wifi !== null): ?>
    <div class="card booking-detail-panel">
        <div class="micro-label">Connectivity</div>
        <h2 class="booking-detail-heading"><?= escape($wifi['headline']) ?></h2>
        <?php foreach ($wifi['lines'] as $line): ?>
            <p class="booking-detail-line mono"><?= escape($line) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card booking-detail-panel">
        <div class="micro-label">Payment progress</div>
        <ul class="payment-timeline">
            <?php foreach ($paymentTimeline as $step): ?>
                <li class="payment-step <?= escape($timelineStatusClass((string) $step['status'])) ?>">
                    <div class="payment-step-main">
                        <span class="payment-step-label"><?= escape($step['label']) ?></span>
                        <strong class="mono payment-step-amount"><?= escape($money((int) $step['amount_cents'])) ?></strong>
                    </div>
                    <p class="payment-step-detail"><?= escape($step['detail']) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="booking-detail-meta" style="margin-top:1rem;margin-bottom:0;">
            Payment method: <strong><?= escape(($booking['payment_method'] ?? '') === 'etransfer' ? 'Interac e-Transfer' : 'Card (Square)') ?></strong>
        </p>
    </div>

    <div class="card booking-detail-panel">
        <div class="micro-label">Price breakdown</div>
        <div class="quote-lines">
            <div><span>Rental</span><strong class="mono"><?= escape($money($rentalCents)) ?></strong></div>
            <div><span>Shipping / delivery</span><strong class="mono"><?= escape($money($shippingCents)) ?></strong></div>
            <?php if ($addonsCents > 0): ?>
                <div><span>Add-ons</span><strong class="mono"><?= escape($money($addonsCents)) ?></strong></div>
            <?php endif; ?>
            <div><span>Tax</span><strong class="mono"><?= escape($money($taxCents)) ?></strong></div>
            <div><span>Deposit (refundable)</span><strong class="mono"><?= escape($money($depositCents)) ?></strong></div>
        </div>
        <div class="quote-total">
            <span>Booking total</span>
            <strong class="mono"><?= escape($money($grandTotal)) ?></strong>
        </div>
    </div>

    <div class="card booking-detail-panel">
        <div class="micro-label">Actions</div>
        <?php if (in_array($booking['appointment_status'] ?? '', ['appointment_proposed', 'proposed'], true)): ?>
            <form method="post" action="<?= escape(route_path('account/bookings/accept')) ?>" style="margin-bottom:0.75rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                <button class="btn btn-primary" type="submit">Accept proposed pickup time</button>
            </form>
        <?php endif; ?>

        <?php if ($lifecycle === 'booking_pending_payment'): ?>
            <?php if (($booking['payment_method'] ?? '') === 'etransfer'): ?>
                <a class="btn btn-primary" href="<?= escape(route_path('booking/etransfer')) ?>?booking_id=<?= (int) $booking['id'] ?>">E-Transfer instructions</a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?= escape(route_path('account/bookings/pay')) ?>?booking_id=<?= (int) $booking['id'] ?>">Pay now</a>
            <?php endif; ?>
        <?php elseif ($payment === 'payment_deposit_scheduled_failed'): ?>
            <a class="btn btn-primary" href="<?= escape(route_path('booking/update-card')) ?>?booking_id=<?= (int) $booking['id'] ?>">Update card for deposit</a>
        <?php endif; ?>

        <?php if (!empty($cancelPreview['pending'])): ?>
            <div class="cancel-policy-box cancel-policy-box--pending">
                <h3 class="booking-detail-heading">Cancellation pending</h3>
                <p class="lead" style="font-size:0.875rem;margin:0 0 0.5rem;"><?= escape($cancelPreview['summary']) ?></p>
                <?php if ($cancelPreview['details'] !== []): ?>
                    <ul class="cancel-policy-list">
                        <?php foreach ($cancelPreview['details'] as $detail): ?>
                            <li><?= escape($detail) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php elseif ($canCancel): ?>
            <div class="cancel-policy-box">
                <h3 class="booking-detail-heading">Cancel booking</h3>
                <p class="lead" style="font-size:0.875rem;margin:0 0 0.5rem;"><?= escape($cancelPreview['summary']) ?></p>
                <?php if ($cancelPreview['details'] !== []): ?>
                    <ul class="cancel-policy-list">
                        <?php foreach ($cancelPreview['details'] as $detail): ?>
                            <li><?= escape($detail) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ((int) ($cancelPreview['refund_cents'] ?? 0) > 0): ?>
                    <p class="booking-detail-meta">Estimated refund: <strong class="mono"><?= escape($money((int) $cancelPreview['refund_cents'])) ?></strong></p>
                <?php endif; ?>
                <?php if ((int) $cancelPreview['fee_cents'] > 0): ?>
                    <p class="booking-detail-meta">Estimated cancellation fee: <strong class="mono"><?= escape($money((int) $cancelPreview['fee_cents'])) ?></strong></p>
                <?php endif; ?>
                <?php
                $confirmLines = [
                    'Request cancellation for booking ' . booking_reference($booking) . '?',
                    '',
                    $cancelPreview['summary'],
                ];
                if (!empty($cancelPreview['requires_admin_approval'])) {
                    $confirmLines[] = 'An admin must approve before any refund is issued.';
                }
                if ((int) ($cancelPreview['refund_cents'] ?? 0) > 0) {
                    $confirmLines[] = 'Estimated refund: ' . $money((int) $cancelPreview['refund_cents']);
                }
                if ((int) $cancelPreview['fee_cents'] > 0) {
                    $confirmLines[] = 'Estimated fee: ' . $money((int) $cancelPreview['fee_cents']);
                }
                $confirmMessage = implode("\n", $confirmLines);
                ?>
                <form
                    method="post"
                    action="<?= escape(route_path('account/bookings/cancel')) ?>"
                    style="margin-top:0.75rem;"
                    onsubmit="return confirm(<?= json_encode($confirmMessage, JSON_THROW_ON_ERROR) ?>);"
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                    <button class="btn btn-secondary" type="submit">
                        <?= !empty($cancelPreview['requires_admin_approval']) ? 'Request cancellation' : 'Cancel booking' ?>
                    </button>
                </form>
            </div>
        <?php elseif (!$canCancel && $lifecycle !== 'booking_cancelled' && $lifecycle !== 'booking_closed'): ?>
            <p class="lead" style="font-size:0.875rem;margin:0;"><?= escape($cancelPreview['summary']) ?></p>
        <?php endif; ?>
    </div>
</div>

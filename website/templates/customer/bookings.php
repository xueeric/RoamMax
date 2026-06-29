<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

$pageTitle = 'My Bookings';
$money = static fn (int $cents): string => PricingService::formatMoney($cents);
?>
<div class="micro-label">Account</div>
<h1 class="page-title">My bookings</h1>
<p class="lead">Tap a booking for pickup details, payment progress, and cancellation options. Long-term quote requests appear here too. <a href="<?= escape(route_path('account/profile')) ?>">Update profile & addresses</a>.</p>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<?php if ($entries === []): ?>
    <div class="card">
        <p class="lead" style="margin:0;">No bookings yet. <a href="<?= escape(route_path('/') . '#book') ?>">Check availability</a></p>
    </div>
<?php else: ?>
    <div class="booking-list">
        <?php foreach ($entries as $entry): ?>
            <?php if ($entry['type'] === 'long_term_request'): ?>
                <?php
                $request = $entry['data'];
                $detailUrl = route_path('book/long-term-request/success') . '?request_id=' . (int) $request['id'];
                $needsAction = in_array((string) ($request['status'] ?? ''), ['pending', 'contacted', 'quoted'], true);
                ?>
                <a class="booking-list-card<?= $needsAction ? ' booking-list-card--action' : '' ?>" href="<?= escape($detailUrl) ?>">
                    <div class="booking-list-card-top">
                        <span class="mono booking-list-ref">Quote #<?= (int) $request['id'] ?></span>
                        <span class="badge badge-active"><?= escape(long_term_request_status_label((string) ($request['status'] ?? 'pending'))) ?></span>
                    </div>
                    <p class="booking-list-dates mono"><?= escape($request['start_date']) ?> → <?= escape($request['end_date']) ?></p>
                    <p class="booking-list-meta">
                        Long-term quote · <?= escape(str_replace('_', ' ', (string) $request['fulfillment_type'])) ?>
                        <?php if (!empty($request['location_name'])): ?>
                            · <?= escape($request['location_name']) ?>
                        <?php endif; ?>
                    </p>
                    <div class="booking-list-card-bottom">
                        <strong><?= (int) ($request['day_count'] ?? 0) ?> days</strong>
                        <span class="booking-list-cta">View request →</span>
                    </div>
                    <?php if ($needsAction): ?>
                        <p class="booking-list-hint">Quote in progress — we'll follow up with custom commercial rates</p>
                    <?php endif; ?>
                </a>
            <?php else: ?>
                <?php
                $booking = $entry['data'];
                $total = (int) $booking['rental_total_cents']
                    + (int) $booking['shipping_fee_cents']
                    + (int) $booking['add_ons_total_cents']
                    + (int) ($booking['tax_cents'] ?? 0)
                    + (int) $booking['deposit_cents'];
                $lifecycle = booking_lifecycle_status($booking);
                $payment = booking_payment_status($booking);
                $detailUrl = route_path('account/bookings/view') . '?booking_id=' . (int) $booking['id'];
                $needsAction = $lifecycle === 'booking_pending_payment'
                    || $payment === 'payment_deposit_scheduled_failed'
                    || in_array($booking['appointment_status'] ?? '', ['appointment_proposed', 'proposed'], true);
                ?>
                <a class="booking-list-card<?= $needsAction ? ' booking-list-card--action' : '' ?>" href="<?= escape($detailUrl) ?>">
                    <div class="booking-list-card-top">
                        <span class="mono booking-list-ref"><?= escape(booking_reference($booking)) ?></span>
                        <span class="badge badge-active"><?= escape(booking_customer_status_label($booking)) ?></span>
                    </div>
                    <p class="booking-list-dates mono"><?= escape($booking['start_date']) ?> → <?= escape($booking['end_date']) ?></p>
                    <p class="booking-list-meta">
                        <?= escape(str_replace('_', ' ', (string) $booking['fulfillment_type'])) ?>
                        <?php if (!empty($booking['location_name'])): ?>
                            · <?= escape($booking['location_name']) ?>
                        <?php endif; ?>
                    </p>
                    <div class="booking-list-card-bottom">
                        <strong class="mono"><?= escape($money($total)) ?></strong>
                        <span class="booking-list-cta">View details →</span>
                    </div>
                    <?php if ($needsAction): ?>
                        <p class="booking-list-hint">Action needed — open for payment or pickup steps</p>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

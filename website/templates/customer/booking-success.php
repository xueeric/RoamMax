<?php

declare(strict_types=1);

$pageTitle = 'Booking confirmed';
$paymentStatus = booking_payment_status($booking ?? []);
?>
<div class="card" style="max-width:36rem;margin:0 auto;text-align:center;">
    <div class="micro-label">Payment complete</div>
    <?php if ($paymentStatus === 'payment_square_rental_captured' || $paymentStatus === 'payment_deposit_scheduled'): ?>
        <h1 class="page-title">Rental paid</h1>
        <p class="lead">
            Booking <span class="mono"><?= escape(booking_reference($booking)) ?></span> is reserved.
            Your refundable deposit will be authorized 24 hours before your rental starts on
            <span class="mono"><?= escape($booking['start_date']) ?></span>.
        </p>
    <?php elseif (in_array($paymentStatus, ['payment_square_full_captured', 'payment_deposit_scheduled_processed', 'payment_etransfer_confirmed', 'payment_etransfer_partial_confirmed'], true)): ?>
        <h1 class="page-title">Booking confirmed</h1>
        <p class="lead">
            Booking <span class="mono"><?= escape(booking_reference($booking)) ?></span> is confirmed.
            <?php if (!empty($booking['equipment_name'])): ?>
                Unit: <?= escape($booking['equipment_name']) ?>.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <h1 class="page-title">Thank you</h1>
        <p class="lead">Your payment is being processed.</p>
    <?php endif; ?>
    <a class="btn btn-primary" href="<?= escape(route_path('account/bookings')) ?>">View my bookings</a>
</div>

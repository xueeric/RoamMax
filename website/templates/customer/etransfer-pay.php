<?php

declare(strict_types=1);

$pageTitle = 'Interac e-Transfer';
?>
<div class="card payment-instructions-card">
    <div class="micro-label">Payment</div>
    <h1 class="page-title">Send Interac e-Transfer</h1>
    <p class="lead">
        Booking <span class="mono"><?= escape(booking_reference($booking)) ?></span> is reserved while we wait for your transfer.
        Send the exact amount below to complete your booking.
    </p>

    <?php if ($message = flash('error')): ?>
        <div class="alert alert-error"><?= escape($message) ?></div>
    <?php endif; ?>
    <?php if ($message = flash('success')): ?>
        <div class="alert alert-success"><?= escape($message) ?></div>
    <?php endif; ?>

    <div class="payment-instructions-grid">
        <div class="payment-instruction">
            <span class="payment-instruction-label">Send to</span>
            <strong class="mono"><?= escape($etransferEmail) ?></strong>
        </div>
        <div class="payment-instruction">
            <span class="payment-instruction-label">Amount</span>
            <strong class="mono"><?= escape($totalDue) ?></strong>
        </div>
        <div class="payment-instruction">
            <span class="payment-instruction-label">E-transfer reference</span>
            <strong class="mono"><?= escape($etransferMemo) ?></strong>
        </div>
    </div>

    <ol class="payment-steps">
        <li>Log in to your bank and start an Interac e-Transfer.</li>
        <li>Send <strong><?= escape($totalDue) ?></strong> to <strong><?= escape($etransferEmail) ?></strong>.</li>
        <li>Include reference <strong><?= escape($etransferMemo) ?></strong> in the message or memo field.</li>
        <li>Click below once the transfer is sent. We will confirm your booking after the payment arrives.</li>
    </ol>

    <?php if (!empty($booking['etransfer_notified_at'])): ?>
        <div class="alert alert-success">
            We received your notice on <?= escape(substr((string) $booking['etransfer_notified_at'], 0, 16)) ?> UTC.
            Your booking will be confirmed once the e-Transfer is deposited.
        </div>
    <?php else: ?>
        <form method="post" action="<?= escape(route_path('booking/etransfer')) ?>?booking_id=<?= (int) $booking['id'] ?>">
            <?= csrf_field() ?>
            <button class="btn btn-primary" type="submit">I have sent the e-Transfer</button>
        </form>
    <?php endif; ?>

    <p class="lead payment-instructions-footnote">
        Need to pay by card instead?
        <a href="<?= escape(route_path('account/bookings/pay')) ?>?booking_id=<?= (int) $booking['id'] ?>&change=1">Choose a different payment method</a>.
    </p>

    <a class="btn btn-secondary" href="<?= escape(route_path('account/bookings')) ?>">Back to my bookings</a>
</div>

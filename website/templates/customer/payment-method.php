<?php

declare(strict_types=1);

$pageTitle = 'Choose payment method';
$selectedMethod = (string) ($booking['payment_method'] ?? '');
if ($selectedMethod !== 'etransfer') {
    $selectedMethod = $squareAvailable ? 'square' : 'etransfer';
}
?>
<div class="card payment-method-card">
    <div class="micro-label">Payment</div>
    <h1 class="page-title">How would you like to pay?</h1>
    <p class="lead">
        Booking <span class="mono"><?= escape(booking_reference($booking)) ?></span> · total due now
        <strong class="mono"><?= escape($totalDue) ?></strong>
    </p>

    <?php if ($message = flash('error')): ?>
        <div class="alert alert-error"><?= escape($message) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= escape(route_path('account/bookings/pay')) ?>" class="payment-method-form">
    <?= csrf_field() ?>
        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">

        <div class="payment-method-options">
            <?php if ($squareAvailable): ?>
                <label class="payment-method-option">
                    <input type="radio" name="payment_method" value="square" <?= $selectedMethod === 'square' ? 'checked' : '' ?>>
                    <span class="payment-method-copy">
                        <strong>Pay by card (Square)</strong>
                        <span>Secure online checkout with credit or debit card.</span>
                    </span>
                </label>
            <?php endif; ?>

            <label class="payment-method-option">
                <input type="radio" name="payment_method" value="etransfer" <?= $selectedMethod === 'etransfer' ? 'checked' : '' ?>>
                <span class="payment-method-copy">
                    <strong>Interac e-Transfer</strong>
                    <span>Send <?= escape($totalDue) ?> to <?= escape($etransferEmail) ?>.</span>
                </span>
            </label>
        </div>

        <button class="btn btn-primary" type="submit">Continue</button>
    </form>

    <a class="btn btn-secondary" href="<?= escape(route_path('account/bookings')) ?>" style="margin-top:1rem;">Back to my bookings</a>
</div>

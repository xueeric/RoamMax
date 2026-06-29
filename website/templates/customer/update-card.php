<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

$pageTitle = 'Update card';
$depositLabel = PricingService::formatMoney((int) $depositCents);
?>
<div class="card" style="max-width:36rem;margin:0 auto;">
    <div class="micro-label">Deposit authorization</div>
    <h1 class="page-title">Update card for <?= escape(booking_reference($booking)) ?></h1>
    <p class="lead">
        We could not authorize the <?= escape($depositLabel) ?> security deposit on your saved card.
        Enter a new card below — we will retry the authorization immediately. Your card is not charged unless there is damage.
    </p>

    <?php if ($squareCard['isSandbox'] ?? false): ?>
        <p class="lead square-postal-hint" style="font-size:0.8125rem;margin-bottom:0.75rem;">
            Sandbox test cards use US <strong>ZIP</strong> — enter <span class="mono">94103</span> if the field says ZIP.
        </p>
    <?php endif; ?>
    <div id="square-card-container" class="square-card-container"></div>
    <div id="square-pay-error" class="alert alert-error" hidden></div>
    <button class="btn btn-primary" type="button" id="square-pay-button" disabled>Save card &amp; retry deposit</button>
</div>

<script>
    window.STARLINK_SQUARE_PAY = {
        applicationId: <?= json_encode($squareApplicationId, JSON_THROW_ON_ERROR) ?>,
        locationId: <?= json_encode($squareLocationId, JSON_THROW_ON_ERROR) ?>,
        bookingId: <?= (int) $booking['id'] ?>,
        csrfToken: <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>,
        submitUrl: <?= json_encode(route_path('booking/update-card'), JSON_THROW_ON_ERROR) ?>,
        amountLabel: <?= json_encode($depositLabel, JSON_THROW_ON_ERROR) ?>,
        buttonLabel: 'Save card & retry deposit',
        locale: <?= json_encode($squareCard['locale'] ?? 'en-CA', JSON_THROW_ON_ERROR) ?>,
        billingPostalCode: <?= json_encode($squareCard['billingPostalCode'] ?? null, JSON_THROW_ON_ERROR) ?>,
        sandboxDefaultPostal: <?= json_encode($squareCard['sandboxDefaultPostal'] ?? null, JSON_THROW_ON_ERROR) ?>,
    };
</script>
<script src="<?= escape($squareSdkUrl) ?>"></script>
<script src="<?= escape(route_path('assets/js/square-pay.js')) ?>" defer></script>

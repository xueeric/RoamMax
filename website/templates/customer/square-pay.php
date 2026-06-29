<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

$pageTitle = 'Pay by card';
$chargeLabel = PricingService::formatMoney((int) $chargeCents);
$depositLabel = PricingService::formatMoney((int) $depositCents);
?>
<div class="card" style="max-width:36rem;margin:0 auto;">
    <div class="micro-label">Secure checkout</div>
    <h1 class="page-title">Pay for booking <?= escape(booking_reference($booking)) ?></h1>

    <?php if ($isShortTerm): ?>
        <p class="lead">
            We will charge <strong><?= escape($chargeLabel) ?></strong> now (rental, shipping, tax, and add-ons).
            Your <strong><?= escape($depositLabel) ?></strong> refundable deposit will be authorized on your card
            24 hours before your rental starts — not captured unless there is damage.
        </p>
    <?php else: ?>
        <p class="lead">
            We will charge <strong><?= escape($chargeLabel) ?></strong> now, including the
            <strong><?= escape($depositLabel) ?></strong> refundable deposit. The deposit is refunded after return.
        </p>
    <?php endif; ?>

    <?php if (!empty($squareCredentialsError)): ?>
        <div class="alert alert-error"><?= escape($squareCredentialsError) ?></div>
        <?php if ($squareIsSandbox ?? false): ?>
            <p class="lead" style="font-size:0.8125rem;">
                For full sandbox testing, set all three in <code>website/.env</code> from
                <strong>Developer Dashboard → Credentials → Sandbox</strong>:
                Sandbox Application ID, Sandbox Access token, and a sandbox Location ID
                (Seller Dashboard → Locations while signed into your sandbox test account).
            </p>
        <?php endif; ?>
    <?php else: ?>
    <?php if ($squareIsSandbox ?? false): ?>
        <p class="lead square-postal-hint" style="font-size:0.8125rem;margin-bottom:0.75rem;">
            <strong>Sandbox test cards</strong> (e.g. <span class="mono">4111 1111 1111 1111</span>) use a US
            <strong>ZIP</strong> field — enter <span class="mono">94103</span> (not a Canadian postal code).
            Real Canadian cards show <strong>Postal code</strong> and accept your billing code.
        </p>
    <?php endif; ?>
    <div id="square-card-container" class="square-card-container"></div>
    <div id="square-pay-error" class="alert alert-error" hidden></div>
    <button class="btn btn-primary" type="button" id="square-pay-button" disabled>Pay <?= escape($chargeLabel) ?></button>
    <?php endif; ?>
</div>

<?php if (empty($squareCredentialsError)): ?>
<script>
    window.STARLINK_SQUARE_PAY = {
        applicationId: <?= json_encode($squareApplicationId, JSON_THROW_ON_ERROR) ?>,
        locationId: <?= json_encode($squareLocationId, JSON_THROW_ON_ERROR) ?>,
        bookingId: <?= (int) $booking['id'] ?>,
        csrfToken: <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>,
        submitUrl: <?= json_encode(route_path('booking/pay-card'), JSON_THROW_ON_ERROR) ?>,
        amountLabel: <?= json_encode($chargeLabel, JSON_THROW_ON_ERROR) ?>,
        locale: <?= json_encode($squareCard['locale'] ?? 'en-CA', JSON_THROW_ON_ERROR) ?>,
        billingPostalCode: <?= json_encode($squareCard['billingPostalCode'] ?? null, JSON_THROW_ON_ERROR) ?>,
        sandboxDefaultPostal: <?= json_encode($squareCard['sandboxDefaultPostal'] ?? null, JSON_THROW_ON_ERROR) ?>,
    };
</script>
<script src="<?= escape($squareSdkUrl) ?>"></script>
<script src="<?= escape(route_path('assets/js/square-pay.js')) ?>" defer></script>
<?php endif; ?>

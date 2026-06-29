<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

/** @var array<string, mixed> $quote */
/** @var string $fulfillmentType */

$money = static fn (int $cents): string => PricingService::formatMoney($cents);
$taxRateLabel = '';
if (($quote['tax_determined'] ?? false) && isset($quote['tax_rate_percent'])) {
    $taxRateLabel = ' (' . rtrim(rtrim(number_format((float) $quote['tax_rate_percent'], 3), '0'), '.') . '%)';
}
$taxLineLabel = ($quote['tax_determined'] ?? false)
    ? ($quote['tax_label'] . $taxRateLabel)
    : 'Tax';
$taxAmount = ($quote['tax_determined'] ?? false)
    ? $money($quote['tax_cents'])
    : 'To be determined';
?>
<aside class="card price-panel checkout-gate-quote" id="checkout-quote-panel">
    <div class="micro-label">Live quote</div>
    <div class="quote-lines">
        <div><span>Rental (<?= (int) $quote['days'] ?> days)</span><strong class="mono" data-quote="rental"><?= escape($money($quote['rental_total_cents'])) ?></strong></div>
        <div><span><?= $fulfillmentType === 'city_delivery' ? 'Delivery' : 'Shipping' ?></span><strong class="mono" data-quote="shipping"><?= escape($money($quote['shipping_fee_cents'])) ?></strong></div>
        <?php if ((int) $quote['add_ons_total_cents'] > 0): ?>
            <div><span>Add-ons</span><strong class="mono" data-quote="addons"><?= escape($money($quote['add_ons_total_cents'])) ?></strong></div>
        <?php endif; ?>
        <div><span data-quote="tax-label"><?= escape($taxLineLabel) ?></span><strong class="mono" data-quote="tax"><?= escape($taxAmount) ?></strong></div>
        <?php $depositChargedAtCheckout = (bool) ($quote['deposit_charged_at_checkout'] ?? true); ?>
        <div>
            <span>
                Deposit (refundable)
                <span
                    class="quote-deposit-note"
                    data-quote="deposit-note"
                    style="display:<?= $depositChargedAtCheckout ? 'none' : 'block' ?>;font-size:0.7rem;opacity:0.7;"
                >Held on card 24h before pickup</span>
            </span>
            <strong class="mono" data-quote="deposit"><?= escape($money($quote['deposit_cents'])) ?></strong>
        </div>
    </div>
    <div class="quote-total">
        <span>Total due now</span>
        <strong class="mono" data-quote="total"><?= escape($money($quote['total_due_cents'])) ?></strong>
    </div>
    <?php if (!($quote['tax_determined'] ?? false)): ?>
        <p class="lead quote-tax-note" style="font-size:0.75rem;margin-top:0.75rem;margin-bottom:0;">
            Tax is based on your shipping province and will be finalized below.
        </p>
    <?php endif; ?>
</aside>

<?php

declare(strict_types=1);

use Starlink\Services\PricingConfigService;
use Starlink\Services\PricingService;

$m = static fn (int $c): string => PricingService::formatMoney($c);
?>
<section class="home-section">
    <div class="section-heading" id="pricing">
        <h2 class="section-title">Pricing</h2>
        <span class="micro-label">Rental rates</span>
    </div>
    <p class="lead">Longer rentals unlock lower daily rates. Select your dates on the calendar to see your exact total — the price at checkout is what you pay.</p>

    <div class="pricing-display-grid">
        <?php foreach ($pricingTiers as $tier): ?>
            <article class="card pricing-card">
                <div class="pricing-card-label"><?= escape($tier['label'] ?? 'Rental tier') ?></div>
                <div class="pricing-card-rate">
                    <?php if (($tier['pricing_mode'] ?? 'daily') === 'custom_contact'): ?>
                        Custom
                    <?php else: ?>
                        <?= escape($m((int) $tier['rate_cents_per_day'])) ?><span class="pricing-card-unit"> / day</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($tier['notes'])): ?>
                    <p class="pricing-card-notes"><?= escape($tier['notes']) ?></p>
                <?php else: ?>
                    <p class="pricing-card-notes pricing-card-notes-spacer" aria-hidden="true">&nbsp;</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php foreach ($pricingRules as $rule): ?>
            <article class="card pricing-card pricing-card-special">
                <div class="pricing-card-label"><?= escape($rule['label']) ?></div>
                <div class="pricing-card-days mono">
                    <?php if ($rule['rule_type'] === 'weekday_flat'): ?>
                        <?= escape(PricingConfigService::weekdayLabels()[(int) ($rule['start_weekday'] ?? 0)] ?? '') ?>
                        –
                        <?= escape(PricingConfigService::weekdayLabels()[(int) ($rule['end_weekday'] ?? 0)] ?? '') ?>
                        · <?= (int) ($rule['exact_day_count'] ?? 0) ?> days
                    <?php else: ?>
                        <?= escape($rule['period_start'] ?? '') ?><?= !empty($rule['period_end']) ? ' – ' . escape($rule['period_end']) : '' ?>
                    <?php endif; ?>
                </div>
                <div class="pricing-card-rate">
                    <?php if ($rule['rule_type'] === 'weekday_flat'): ?>
                        <?= escape($m((int) ($rule['flat_rate_cents'] ?? 0))) ?><span class="pricing-card-unit"> flat</span>
                    <?php else: ?>
                        Min <?= (int) ($rule['min_booking_days'] ?? 0) ?> days
                    <?php endif; ?>
                </div>
                <?php if (!empty($rule['notes'])): ?>
                    <p class="pricing-card-notes"><?= escape($rule['notes']) ?></p>
                <?php else: ?>
                    <p class="pricing-card-notes pricing-card-notes-spacer" aria-hidden="true">&nbsp;</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="pricing-meta">
        <span><strong>Deposit (refundable):</strong> <?= escape($m((int) $depositCents)) ?></span>
        <span><strong>Pickup:</strong> Edmonton, Calgary &amp; Red Deer, Alberta</span>
    </div>
</section>

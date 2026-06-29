<?php

declare(strict_types=1);

use Starlink\Services\PricingConfigService;
use Starlink\Services\PricingService;

$pageTitle = 'Pricing setup';
$m = static fn (int $c): string => PricingService::formatMoney($c);
$weekdays = PricingConfigService::weekdayLabels();
$headerTitle = 'Pricing setup';
$headerLabel = 'Rates & rules';
$headerLead = 'Configure rental tiers, deposits, shipping, and special rules like long-weekend pricing.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1rem;">
    <div class="card-section-heading">
        <h2 class="section-title">Global settings</h2>
    </div>
    <form method="post" action="<?= escape(route_path('admin/pricing/settings')) ?>" class="form-grid">
    <?= csrf_field() ?>
        <div class="form-row">
            <label>
                <span>Minimum rental days</span>
                <input type="number" min="1" name="minimum_rental_days" value="<?= (int) ($settings['minimum_rental_days'] ?? 3) ?>" required>
            </label>
            <label>
                <span>Max self-serve days</span>
                <input type="number" min="1" name="max_self_serve_days" value="<?= (int) ($settings['max_self_serve_days'] ?? 30) ?>" required>
            </label>
            <label>
                <span>Security deposit (CAD)</span>
                <input type="number" step="0.01" min="0" name="deposit" value="<?= number_format(((int) ($settings['deposit_cents'] ?? 35000)) / 100, 2, '.', '') ?>" required>
            </label>
            <label>
                <span>Canada shipping (CAD)</span>
                <input type="number" step="0.01" min="0" name="shipping" value="<?= number_format(((int) ($settings['shipping_fee_cents'] ?? 15000)) / 100, 2, '.', '') ?>" required>
            </label>
            <label>
                <span>Mail booking lead (days)</span>
                <input type="number" min="0" max="14" name="shipping_lead_days" value="<?= (int) ($settings['shipping_lead_days'] ?? 1) ?>" required>
            </label>
            <label>
                <span>Mail arrival buffer (days)</span>
                <input type="number" min="1" max="30" name="shipping_arrival_lead_days" value="<?= (int) ($settings['shipping_arrival_lead_days'] ?? 7) ?>" required>
            </label>
            <label>
                <span>Local delivery radius (km)</span>
                <input type="number" min="1" max="500" name="city_delivery_radius_km" value="<?= (int) ($settings['city_delivery_radius_km'] ?? 50) ?>" required>
            </label>
            <label>
                <span>Local delivery fee (CAD)</span>
                <input type="number" step="0.01" min="0" name="city_delivery_fee" value="<?= number_format(((int) ($settings['city_delivery_fee_cents'] ?? 2500)) / 100, 2, '.', '') ?>" required>
            </label>
        </div>
        <p class="lead" style="font-size:0.75rem;margin:0;">
            Local delivery is offered within the selected radius of the customer's location city. Mail booking lead is the earliest start date customers can select for Canada-wide shipping.
        </p>
        <label>
            <span>30+ day customer message</span>
            <input type="text" name="long_term_contact_note" value="<?= escape($settings['long_term_contact_note'] ?? '') ?>">
            <span class="lead" style="font-size:0.75rem;margin:0;">Shown on the calendar when a customer selects more than <?= (int) ($settings['max_self_serve_days'] ?? 30) ?> days, before they submit a quote request.</span>
        </label>
        <button class="btn btn-primary" type="submit">Save global settings</button>
    </form>
</div>

<div class="pricing-tier-grid">
    <?php foreach ($tiers as $tier): ?>
        <div class="card">
            <form method="post" action="<?= escape(route_path('admin/pricing/tier')) ?>" class="form-grid">
    <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $tier['id'] ?>">
                <div class="micro-label">Tier #<?= (int) $tier['sort_order'] ?></div>
                <label>
                    <span>Label</span>
                    <input type="text" name="label" value="<?= escape($tier['label'] ?? '') ?>" required>
                </label>
                <div class="form-row">
                    <label>
                        <span>Min days</span>
                        <input type="number" min="1" name="min_days" value="<?= (int) $tier['min_days'] ?>" required>
                    </label>
                    <label>
                        <span>Max days</span>
                        <input type="number" min="1" name="max_days" value="<?= $tier['max_days'] !== null ? (int) $tier['max_days'] : '' ?>" placeholder="No max">
                    </label>
                    <label>
                        <span>Sort order</span>
                        <input type="number" name="sort_order" value="<?= (int) $tier['sort_order'] ?>">
                    </label>
                </div>
                <label>
                    <span>Pricing mode</span>
                    <select name="pricing_mode">
                        <option value="daily" <?= ($tier['pricing_mode'] ?? 'daily') === 'daily' ? 'selected' : '' ?>>Daily rate</option>
                        <option value="custom_contact" <?= ($tier['pricing_mode'] ?? '') === 'custom_contact' ? 'selected' : '' ?>>Custom / contact owner</option>
                    </select>
                </label>
                <label>
                    <span>Daily rate (CAD)</span>
                    <input type="number" step="0.01" min="0" name="rate_per_day" value="<?= ($tier['pricing_mode'] ?? 'daily') === 'daily' ? number_format((int) $tier['rate_cents_per_day'] / 100, 2, '.', '') : '0.00' ?>">
                </label>
                <label>
                    <span>Customer-facing notes</span>
                    <textarea name="notes"><?= escape($tier['notes'] ?? '') ?></textarea>
                </label>
                <label style="display:flex;gap:0.5rem;align-items:center;">
                    <input type="checkbox" name="is_active" value="1" <?= (int) ($tier['is_active'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;">
                    <span>Active</span>
                </label>
                <button class="btn btn-secondary" type="submit">Save tier</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<div class="card" style="margin-top:1rem;">
    <div class="card-section-heading">
        <h2 class="section-title">Special pricing rules</h2>
    </div>
    <p class="lead">Long-weekend flat rates (e.g. Fri–Mon $150) and higher minimums during holiday periods.</p>

    <?php foreach ($rules as $rule): ?>
        <div class="card" style="margin-top:1rem;background:#f9fafb;">
            <form method="post" action="<?= escape(route_path('admin/pricing/rule')) ?>" class="form-grid">
    <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                <label>
                    <span>Rule type</span>
                    <select name="rule_type">
                        <option value="weekday_flat" <?= $rule['rule_type'] === 'weekday_flat' ? 'selected' : '' ?>>Weekday pattern flat rate</option>
                        <option value="period_minimum" <?= $rule['rule_type'] === 'period_minimum' ? 'selected' : '' ?>>Holiday period minimum days</option>
                    </select>
                </label>
                <label><span>Label</span><input type="text" name="label" value="<?= escape($rule['label']) ?>" required></label>

                <?php if ($rule['rule_type'] === 'weekday_flat'): ?>
                    <div class="form-row">
                        <label>
                            <span>Start weekday</span>
                            <select name="start_weekday">
                                <?php foreach ($weekdays as $index => $name): ?>
                                    <option value="<?= $index ?>" <?= (int) ($rule['start_weekday'] ?? -1) === $index ? 'selected' : '' ?>><?= escape($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>End weekday</span>
                            <select name="end_weekday">
                                <?php foreach ($weekdays as $index => $name): ?>
                                    <option value="<?= $index ?>" <?= (int) ($rule['end_weekday'] ?? -1) === $index ? 'selected' : '' ?>><?= escape($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label><span>Exact day count</span><input type="number" min="1" name="exact_day_count" value="<?= (int) ($rule['exact_day_count'] ?? 0) ?>"></label>
                        <label><span>Flat rate (CAD)</span><input type="number" step="0.01" min="0" name="flat_rate" value="<?= number_format(((int) ($rule['flat_rate_cents'] ?? 0)) / 100, 2, '.', '') ?>"></label>
                    </div>
                <?php else: ?>
                    <div class="form-row">
                        <label><span>Period start</span><input type="date" name="period_start" value="<?= escape($rule['period_start'] ?? '') ?>"></label>
                        <label><span>Period end</span><input type="date" name="period_end" value="<?= escape($rule['period_end'] ?? '') ?>"></label>
                        <label><span>Minimum booking days</span><input type="number" min="1" name="min_booking_days" value="<?= (int) ($rule['min_booking_days'] ?? 5) ?>"></label>
                    </div>
                <?php endif; ?>

                <label><span>Notes</span><textarea name="notes"><?= escape($rule['notes'] ?? '') ?></textarea></label>
                <div class="center-actions">
                    <label style="display:flex;gap:0.5rem;align-items:center;margin:0;">
                        <input type="checkbox" name="is_active" value="1" <?= (int) $rule['is_active'] === 1 ? 'checked' : '' ?> style="width:auto;">
                        <span>Active</span>
                    </label>
                    <button class="btn btn-secondary" type="submit">Save rule</button>
                </div>
            </form>
            <form method="post" action="<?= escape(route_path('admin/pricing/rule/delete')) ?>" style="margin-top:0.75rem;">
    <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                <button class="btn btn-secondary" type="submit">Delete rule</button>
            </form>
        </div>
    <?php endforeach; ?>

    <div class="card" style="margin-top:1rem;">
        <div class="micro-label">Add rule</div>
        <form method="post" action="<?= escape(route_path('admin/pricing/rule')) ?>" class="form-grid">
    <?= csrf_field() ?>
            <label>
                <span>Rule type</span>
                <select name="rule_type" id="new-rule-type">
                    <option value="weekday_flat">Weekday pattern flat rate</option>
                    <option value="period_minimum">Holiday period minimum days</option>
                </select>
            </label>
            <label><span>Label</span><input type="text" name="label" placeholder="Long weekend minimum" required></label>
            <div class="form-row">
                <label><span>Start weekday (flat rate)</span>
                    <select name="start_weekday">
                        <?php foreach ($weekdays as $index => $name): ?>
                            <option value="<?= $index ?>" <?= $index === 5 ? 'selected' : '' ?>><?= escape($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>End weekday (flat rate)</span>
                    <select name="end_weekday">
                        <?php foreach ($weekdays as $index => $name): ?>
                            <option value="<?= $index ?>" <?= $index === 1 ? 'selected' : '' ?>><?= escape($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>Exact days (flat rate)</span><input type="number" min="1" name="exact_day_count" value="4"></label>
                <label><span>Flat rate CAD</span><input type="number" step="0.01" min="0" name="flat_rate" value="150.00"></label>
            </div>
            <div class="form-row">
                <label><span>Period start (minimum rule)</span><input type="date" name="period_start"></label>
                <label><span>Period end (minimum rule)</span><input type="date" name="period_end"></label>
                <label><span>Min booking days</span><input type="number" min="1" name="min_booking_days" value="5"></label>
            </div>
            <label><span>Notes</span><textarea name="notes" placeholder="Example: Victoria Day weekend — 5-day minimum"></textarea></label>
            <label style="display:flex;gap:0.5rem;align-items:center;">
                <input type="checkbox" name="is_active" value="1" checked style="width:auto;">
                <span>Active</span>
            </label>
            <button class="btn btn-primary" type="submit">Add rule</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top:1rem;">
    <div class="card-section-heading">
        <h2 class="section-title">Customer preview</h2>
        <span class="micro-label">Public pricing</span>
    </div>
    <table>
        <thead>
        <tr><th>Duration</th><th>Rate</th><th>Notes</th></tr>
        </thead>
        <tbody>
        <?php foreach ($tiers as $tier): ?>
            <?php if ((int) $tier['is_active'] !== 1) continue; ?>
            <tr>
                <td><strong><?= escape($tier['label'] ?? '') ?></strong><br><span class="mono"><?= (int) $tier['min_days'] ?><?= $tier['max_days'] !== null ? '–' . (int) $tier['max_days'] : '+' ?> days</span></td>
                <td class="mono">
                    <?php if (($tier['pricing_mode'] ?? 'daily') === 'custom_contact'): ?>
                        Custom
                    <?php else: ?>
                        <?= escape($m((int) $tier['rate_cents_per_day'])) ?> / day
                    <?php endif; ?>
                </td>
                <td><?= escape($tier['notes'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($rules as $rule): ?>
            <?php if ((int) $rule['is_active'] !== 1) continue; ?>
            <tr>
                <td><strong><?= escape($rule['label']) ?></strong><br><span class="mono"><?= escape($rule['rule_type']) ?></span></td>
                <td class="mono">
                    <?php if ($rule['rule_type'] === 'weekday_flat'): ?>
                        <?= escape($m((int) ($rule['flat_rate_cents'] ?? 0))) ?> flat
                    <?php else: ?>
                        Min <?= (int) ($rule['min_booking_days'] ?? 0) ?> days
                    <?php endif; ?>
                </td>
                <td><?= escape($rule['notes'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

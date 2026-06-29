<?php

declare(strict_types=1);

use Starlink\Services\PartnerPersonalBookingService;
use Starlink\Services\PricingService;

$pageTitle = 'Partner Portal';
$formatMoney = static fn (int $cents): string => PricingService::formatMoney($cents);

$totalRevenue = 0;
$totalProfit = 0;
$totalRemaining = 0;
$totalSubscription = 0;
foreach ($reports as $report) {
    $totalRevenue += (int) ($report['revenue_cents'] ?? 0);
    $totalProfit += (int) ($report['profit_cents'] ?? 0);
    $totalRemaining += (int) ($report['remaining_cents'] ?? 0);
    $totalSubscription += (int) ($report['subscription_cents'] ?? 0);
}
?>
<header class="admin-page-header">
    <div class="section-heading">
        <h1 class="section-title">Partner portal</h1>
        <span class="micro-label"><?= escape($user['name']) ?></span>
    </div>
    <p class="lead">Your units in the shared fleet. Customer revenue goes to whichever unit served the rental. <?= escape(PartnerPersonalBookingService::BORROW_POLICY_MESSAGE) ?></p>
    <nav class="partner-section-nav" aria-label="Partner sections">
        <a class="btn btn-secondary" href="#units">Your units</a>
        <a class="btn btn-secondary" href="#financials">Financials</a>
        <a class="btn btn-secondary" href="#personal-use">Personal trips</a>
    </nav>
</header>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<?php if ($equipment !== []): ?>
    <div class="stats-grid">
        <div class="card">
            <div class="micro-label">Units in pool</div>
            <div class="stat-value"><?= count($equipment) ?></div>
        </div>
        <div class="card">
            <div class="micro-label">Unit rental revenue</div>
            <div class="stat-value"><?= escape($formatMoney($totalRevenue)) ?></div>
        </div>
        <div class="card">
            <div class="micro-label">Starlink plans (est.)</div>
            <div class="stat-value"><?= escape($formatMoney($totalSubscription)) ?></div>
        </div>
        <div class="card">
            <div class="micro-label">Net profit</div>
            <div class="stat-value"><?= escape($formatMoney($totalProfit)) ?></div>
        </div>
        <div class="card">
            <div class="micro-label">Remaining payoff</div>
            <div class="stat-value"><?= escape($formatMoney($totalRemaining)) ?></div>
        </div>
    </div>
<?php endif; ?>

<section id="units" class="home-section">
    <div class="section-heading">
        <h2 class="section-title">Your units</h2>
        <span class="micro-label">Fleet contribution</span>
    </div>
    <p class="lead">Starlink account and plan details for units you contribute. Portal passwords are stored for admin ops only.</p>

    <div class="card table-wrap">
        <table>
            <thead>
            <tr>
                <th>Nickname</th>
                <th>Serial</th>
                <th>Location</th>
                <th>Starlink account</th>
                <th>Plan</th>
                <th>Bill payer</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($equipment === []): ?>
                <tr>
                    <td colspan="7">No units assigned to your partner account yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($equipment as $unit): ?>
                    <tr>
                        <td><?= escape($unit['nickname']) ?></td>
                        <td class="mono"><?= escape($unit['serial_number'] ?? '—') ?></td>
                        <td><?= escape($unit['location_name']) ?></td>
                        <td><?= escape($unit['starlink_account_email'] ?? '—') ?></td>
                        <td>
                            <?= escape($unit['plan_label'] ?? ($unit['data_plan'] ?? '—')) ?>
                            <?php if (!empty($unit['plan_monthly_cents'])): ?>
                                <span class="mono">(<?= escape($formatMoney((int) $unit['plan_monthly_cents'])) ?>/mo)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= escape(($unit['subscription_payer'] ?? 'partner') === 'partner' ? 'Partner' : 'Admin') ?></td>
                        <td><span class="badge badge-info"><?= escape(str_replace('_', ' ', (string) $unit['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section id="financials" class="home-section">
    <div class="section-heading">
        <h2 class="section-title">Unit financials</h2>
        <span class="micro-label">Unit payoff</span>
    </div>
    <p class="lead">Revenue from customer rentals and partner borrow fees on your unit. Borrow fees apply when another partner used your kit for conflict days.</p>

    <div class="card table-wrap">
        <table>
            <thead>
            <tr>
                <th>Unit</th>
                <th>CAPEX</th>
                <th>Unit revenue</th>
                <th>Plans (est.)</th>
                <th>Profit</th>
                <th>Payoff</th>
                <th>Remaining</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($reports === []): ?>
                <tr><td colspan="7">No financial data yet.</td></tr>
            <?php else: ?>
                <?php foreach ($reports as $report): ?>
                    <tr>
                        <td><?= escape($report['equipment']['nickname']) ?></td>
                        <td class="mono"><?= escape($formatMoney((int) $report['capex_cents'])) ?></td>
                        <td class="mono"><?= escape($formatMoney((int) $report['revenue_cents'])) ?></td>
                        <td class="mono"><?= escape($formatMoney((int) ($report['subscription_charge_cents'] ?? $report['subscription_cents'] ?? 0))) ?></td>
                        <td class="mono"><?= escape($formatMoney((int) $report['profit_cents'])) ?></td>
                        <td class="mono"><?= escape(number_format((float) $report['payoff_pct'], 1)) ?>%</td>
                        <td class="mono"><?= escape($formatMoney((int) $report['remaining_cents'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section id="personal-use" class="home-section">
    <div class="section-heading">
        <h2 class="section-title">Personal trips</h2>
        <span class="micro-label">Partner booking</span>
    </div>
    <p class="lead"><?= escape($borrowPolicyMessage) ?> One unit for the full trip — no mid-trip swaps.</p>

    <?php
    $calendarHeading = 'Plan a personal trip';
    $calendarLead = 'Pick location, fulfillment, and dates. If your unit is free the whole time, you use your kit at no charge. If customer rentals overlap your unit, you borrow another partner\'s kit for the full trip and pay the customer daily rate for each conflict day — paid to that partner.';
    require WEBSITE_ROOT . '/templates/customer/_calendar.php';
    ?>

    <div class="card table-wrap" style="margin-top:1.5rem;">
        <div class="card-section-heading">
            <h3 class="section-title">Your trips</h3>
        </div>
        <table>
            <thead>
            <tr>
                <th>Reference</th>
                <th>Dates</th>
                <th>Unit</th>
                <th>Conflict days</th>
                <th>Borrow fee</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($personalBookings === []): ?>
                <tr><td colspan="6">No personal trips yet.</td></tr>
            <?php else: ?>
                <?php foreach ($personalBookings as $row): ?>
                    <tr>
                        <td class="mono"><?= escape(booking_reference($row)) ?></td>
                        <td class="mono"><?= escape($row['start_date']) ?> → <?= escape($row['end_date']) ?></td>
                        <td><?= escape($row['equipment_name'] ?? '') ?></td>
                        <td class="mono"><?= (int) ($row['partner_conflict_days'] ?? 0) ?></td>
                        <td class="mono"><?= escape($formatMoney((int) ($row['partner_borrow_fee_cents'] ?? 0))) ?></td>
                        <td><?= escape(str_replace('_', ' ', booking_lifecycle_status($row) . ' · ' . booking_payment_status($row))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card table-wrap" style="margin-top:1.5rem;">
        <div class="card-section-heading">
            <h3 class="section-title">Your unit lent to partners</h3>
        </div>
        <table>
            <thead>
            <tr>
                <th>Reference</th>
                <th>Partner</th>
                <th>Dates</th>
                <th>Conflict days</th>
                <th>Fee to you</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($lentBookings === []): ?>
                <tr><td colspan="6">No partner borrow trips on your unit yet.</td></tr>
            <?php else: ?>
                <?php foreach ($lentBookings as $row): ?>
                    <tr>
                        <td class="mono"><?= escape(booking_reference($row)) ?></td>
                        <td><?= escape($row['borrower_name'] ?? '') ?></td>
                        <td class="mono"><?= escape($row['start_date']) ?> → <?= escape($row['end_date']) ?></td>
                        <td class="mono"><?= (int) ($row['partner_conflict_days'] ?? 0) ?></td>
                        <td class="mono"><?= escape($formatMoney((int) ($row['partner_borrow_fee_cents'] ?? 0))) ?></td>
                        <td><?= escape(str_replace('_', ' ', booking_lifecycle_status($row) . ' · ' . booking_payment_status($row))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

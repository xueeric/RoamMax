<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

$pageTitle = 'Financials';
$m = static fn (int $c): string => PricingService::formatMoney($c);
$headerTitle = 'Financial reports';
$headerLabel = 'Payoff & ledger';
$headerLead = 'Rental revenue is credited to the unit that served the booking. Refunds count rental refunds only — deposit holds and releases are excluded from payoff math.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<div class="stats-grid">
    <div class="card">
        <div class="micro-label">Rental collected</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['rental_collected_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Rental refunded</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['rental_refunded_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Net rental</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['net_rental_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Deposits on hold</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['deposits_held_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Fleet profit</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['total_profit_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Remaining payoff</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['total_remaining_cents'])) ?></div>
    </div>
</div>

<div class="card table-wrap" style="margin-top:1rem;">
    <div class="card-section-heading">
        <h2 class="section-title">Payoff by unit</h2>
    </div>
    <table>
        <thead>
        <tr>
            <th>Unit</th>
            <th>Owner</th>
            <th>CAPEX</th>
            <th>Collected</th>
            <th>Refunded</th>
            <th>Net rental</th>
            <th>Plans & costs</th>
            <th>Profit</th>
            <th>Payoff</th>
            <th>Remaining</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($reports === []): ?>
            <tr><td colspan="10">No Starlink units yet.</td></tr>
        <?php else: ?>
            <?php foreach ($reports as $report): ?>
                <?php
                $unit = $report['equipment'];
                $ownerLabel = ($unit['owner_type'] ?? '') === 'admin'
                    ? 'Admin'
                    : (string) ($unit['partner_name'] ?? 'Partner');
                ?>
                <tr>
                    <td><?= escape($unit['nickname'] ?? '—') ?></td>
                    <td><?= escape($ownerLabel) ?></td>
                    <td class="mono"><?= escape($m((int) $report['capex_cents'])) ?></td>
                    <td class="mono"><?= escape($m((int) $report['revenue_cents'])) ?></td>
                    <td class="mono"><?= escape($m((int) $report['refund_cents'])) ?></td>
                    <td class="mono"><?= escape($m((int) $report['net_revenue_cents'])) ?></td>
                    <td class="mono"><?= escape($m((int) ($report['subscription_charge_cents'] ?? $report['subscription_cents'] ?? 0))) ?></td>
                    <td class="mono"><?= escape($m((int) $report['profit_cents'])) ?></td>
                    <td class="mono"><?= escape(number_format((float) $report['payoff_pct'], 1)) ?>%</td>
                    <td class="mono"><?= escape($m((int) $report['remaining_cents'])) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card table-wrap" style="margin-top:1rem;">
    <div class="card-section-heading">
        <h2 class="section-title">Net rental by owner</h2>
    </div>
    <table>
        <thead>
        <tr>
            <th>Owner</th>
            <th>Collected</th>
            <th>Refunded</th>
            <th>Net</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($revenueByOwner === []): ?>
            <tr><td colspan="4">No payment data yet.</td></tr>
        <?php else: ?>
            <?php foreach ($revenueByOwner as $row): ?>
                <?php $net = (int) $row['revenue_cents'] - (int) $row['refund_cents']; ?>
                <tr>
                    <td><?= escape($row['owner_name']) ?></td>
                    <td class="mono"><?= escape($m((int) $row['revenue_cents'])) ?></td>
                    <td class="mono"><?= escape($m((int) $row['refund_cents'])) ?></td>
                    <td class="mono"><strong><?= escape($m($net)) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card table-wrap" style="margin-top:1rem;">
    <div class="card-section-heading">
        <h2 class="section-title">Payment ledger</h2>
    </div>
    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Booking</th>
            <th>Unit</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Notes</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($ledger === []): ?>
            <tr><td colspan="7">No transactions yet.</td></tr>
        <?php else: ?>
            <?php foreach ($ledger as $payment): ?>
                <tr>
                    <td class="mono"><?= escape(substr((string) $payment['created_at'], 0, 10)) ?></td>
                    <td><?= escape(str_replace('_', ' ', (string) $payment['type'])) ?></td>
                    <td class="mono">
                        <?php if (!empty($payment['booking_ref'])): ?>
                            <a href="<?= escape(route_path('admin/bookings/view') . '?booking_id=' . (int) $payment['booking_ref']) ?>">
                                <?= escape($payment['booking_reference'] ?? ('#' . (int) $payment['booking_ref'])) ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= escape($payment['equipment_name'] ?? '—') ?></td>
                    <td class="mono"><?= escape($m((int) $payment['amount_cents'])) ?></td>
                    <td><?= escape($payment['status']) ?></td>
                    <td><?= escape($payment['notes'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php

declare(strict_types=1);

$pageTitle = 'Admin Dashboard';

function money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

$headerTitle = 'Dashboard';
$headerLabel = 'Admin operations';
$headerLead = 'Welcome back, ' . ($user['name'] ?? '');
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<div class="stats-grid">
    <div class="card">
        <div class="micro-label">Active equipment</div>
        <div class="stat-value"><?= (int) $stats['equipment'] ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Active locations</div>
        <div class="stat-value"><?= (int) $stats['locations'] ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Partners</div>
        <div class="stat-value"><?= (int) $stats['partners'] ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Active bookings</div>
        <div class="stat-value"><?= (int) $stats['active_bookings'] ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Units out</div>
        <div class="stat-value"><?= (int) $stats['units_out'] ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Revenue</div>
        <div class="stat-value"><?= escape(money((int) $stats['revenue_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Deposits held</div>
        <div class="stat-value"><?= escape(money((int) $stats['deposits_held_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Scheduled staging</div>
        <div class="stat-value"><?= (int) $stats['scheduled_staging'] ?></div>
    </div>
</div>

<?php

declare(strict_types=1);

use Starlink\Services\AdminBookingPresenter;

$pageTitle = 'Bookings';
$presenter = new AdminBookingPresenter();
$headerTitle = 'Bookings';
$headerLabel = 'Operations';
$headerLead = 'New orders, payments, staging, deposits, returns, and cancel requests — open a row for full detail.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';

$statusFilter = $statusFilter ?? '';
$tabCounts = $tabCounts ?? [];
$filters = [
    '' => 'All',
    'booking_pending_payment' => 'Awaiting payment',
    'booking_confirmed' => 'Confirmed',
    'booking_cancellation_pending' => 'Cancel pending',
    'booking_active' => 'Active',
    'booking_late' => 'Late',
    'booking_closed' => 'Completed',
    'booking_cancelled' => 'Cancelled',
];
?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<nav class="admin-bookings-filters" aria-label="Filter bookings by status">
    <?php foreach ($filters as $value => $label): ?>
        <?php
        $query = $value !== '' ? '?status=' . rawurlencode($value) : '';
        $isActive = $statusFilter === $value;
        $count = (int) ($tabCounts[$value] ?? 0);
        ?>
        <a href="<?= escape(route_path('admin/bookings') . $query) ?>" class="<?= $isActive ? 'is-active' : '' ?>">
            <?= escape($label) ?><?= $count > 0 ? ' (' . $count . ')' : '' ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($bookings === []): ?>
    <div class="card">
        <p class="lead" style="margin:0;">No bookings<?= $statusFilter !== '' ? ' with this status' : '' ?>.</p>
    </div>
<?php else: ?>
    <ul class="admin-booking-list">
        <?php foreach ($bookings as $b): ?>
            <?php
            $attention = $presenter->attentionItems($b);
            $action = $presenter->primaryAction($b);
            $detailHref = route_path('admin/bookings/view') . '?booking_id=' . (int) $b['id']
                . ($statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '');
            ?>
            <li>
                <a class="admin-booking-row" href="<?= escape($detailHref) ?>">
                    <div class="admin-booking-row-main">
                        <span class="admin-booking-ref mono"><?= escape(booking_reference($b)) ?></span>
                        <span class="admin-booking-subtitle"><?= escape($presenter->listSubtitle($b)) ?></span>
                        <span class="admin-booking-sort-meta"><?= escape($presenter->sortMeta($b, $statusFilter !== '' ? $statusFilter : null)) ?></span>
                    </div>
                    <?php if ($attention !== []): ?>
                        <div class="admin-booking-row-attention">
                            <?php foreach (array_slice($attention, 0, 3) as $item): ?>
                                <span class="admin-attention-chip admin-attention-chip--<?= escape($item['tone']) ?>"><?= escape($item['label']) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($attention) > 3): ?>
                                <span class="admin-attention-chip admin-attention-chip--info">+<?= count($attention) - 3 ?> more</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <span class="admin-booking-row-cta btn btn-secondary btn-compact"><?= escape($action['label']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

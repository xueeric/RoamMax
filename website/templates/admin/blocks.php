<?php

declare(strict_types=1);

$pageTitle = 'Owner Blocks';
$headerTitle = 'Owner blocks';
$headerLabel = 'Personal use';
$headerLead = 'Reserve fleet capacity like a customer booking — same calendar, location, and fulfillment — but no payment. Ops assigns a unit, then pickup, return, and QC run from the booking detail page.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';

$calendarHeading = 'Choose dates & location';
$calendarLead = 'Pick location and fulfillment, then select dates on the calendar. Unavailable dates cannot be selected.';
require WEBSITE_ROOT . '/templates/customer/_calendar.php';
?>

<div class="card table-wrap" style="margin-top:1.5rem;">
    <div class="section-heading">
        <h2 class="section-title">Personal-use reservations</h2>
        <span class="micro-label">Owner / partner blocks</span>
    </div>
    <table>
        <thead>
        <tr>
            <th>Reference</th>
            <th>Requester</th>
            <th>Dates</th>
            <th>Location</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($reservations === []): ?>
            <tr><td colspan="6">No personal-use reservations yet.</td></tr>
        <?php else: ?>
            <?php foreach ($reservations as $row): ?>
                <?php
                $lifecycle = booking_lifecycle_status($row);
                $fulfillment = booking_fulfillment_status($row);
                ?>
                <tr>
                    <td class="mono"><?= escape(booking_reference($row)) ?></td>
                    <td><?= escape($row['customer_name'] ?? '') ?></td>
                    <td class="mono"><?= escape($row['start_date']) ?> → <?= escape($row['end_date']) ?></td>
                    <td><?= escape($row['location_name'] ?? '') ?></td>
                    <td><?= escape(str_replace('_', ' ', $lifecycle . ' · ' . $fulfillment)) ?></td>
                    <td><a class="btn btn-secondary" href="<?= escape(route_path('admin/bookings/view') . '?booking_id=' . (int) $row['id']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

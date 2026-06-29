<?php

declare(strict_types=1);

$pageTitle = 'Starlink equipment';

function money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

$headerTitle = 'Starlink';
$headerLabel = 'Fleet overview';
$headerLead = 'Rental dish units. Status shows lifecycle. Location is where the unit is listed now. Next commitment shows staging or booking due dates.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>
<div class="center-actions" style="margin-bottom:1rem;">
    <a class="btn btn-primary" href="<?= escape(route_path('admin/equipment/edit')) ?>">Add Starlink unit</a>
</div>

<?php if ($citySummary !== []): ?>
<div class="card" style="margin-bottom:1rem;">
    <div class="micro-label">Units by city</div>
    <table>
        <thead>
        <tr>
            <th>City</th>
            <th>At location</th>
            <th>With customer</th>
            <th>Staging in</th>
            <th>Active bookings</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($citySummary as $row): ?>
            <tr>
                <td><?= escape($row['city']) ?></td>
                <td class="mono"><?= (int) $row['at_location'] ?></td>
                <td class="mono"><?= (int) $row['with_customer'] ?></td>
                <td class="mono"><?= (int) $row['staging_in'] ?></td>
                <td class="mono"><?= (int) $row['booked'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card table-wrap">
    <table>
        <thead>
        <tr>
            <th>Unit</th>
            <th>Owner</th>
            <th>Location</th>
            <th>Status</th>
            <th>Next commitment</th>
            <th>Priority</th>
            <th>Plan</th>
            <th>CAPEX</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($equipment as $unit): ?>
            <?php $commitment = $unit['next_commitment'] ?? null; ?>
            <tr>
                <td>
                    <strong><?= escape($unit['nickname']) ?></strong><br>
                    <span class="mono"><?= escape($unit['serial_number'] ?? '—') ?></span>
                </td>
                <td>
                    <?= escape($unit['owner_type'] === 'admin' ? 'Admin' : ($unit['partner_name'] ?? 'Partner')) ?>
                </td>
                <td><?= escape($unit['location_name']) ?></td>
                <td><span class="badge badge-active"><?= escape(str_replace('_', ' ', (string) $unit['status'])) ?></span></td>
                <td>
                    <?php if ($commitment !== null): ?>
                        <?= escape($commitment['label']) ?>
                        <?php if (!empty($commitment['staging_date'])): ?>
                            <div class="admin-bookings-meta mono">Stage by <?= escape($commitment['staging_date']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td class="mono"><?= (int) $unit['rental_priority'] ?></td>
                <td class="mono"><?= escape($unit['data_plan'] ?? '—') ?></td>
                <td class="mono"><?= escape(money((int) $unit['purchase_cost_cents'])) ?></td>
                <td>
                    <div class="inline-form" style="gap:0.5rem;flex-wrap:nowrap;">
                        <a class="btn btn-secondary" href="<?= escape(route_path('admin/equipment/edit') . '?id=' . (int) $unit['id']) ?>">Edit</a>
                        <form method="post" action="<?= escape(route_path('admin/equipment/delete')) ?>" onsubmit="return confirm('Delete this Starlink unit permanently? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $unit['id'] ?>">
                            <button class="btn btn-secondary" type="submit">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

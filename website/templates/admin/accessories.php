<?php

declare(strict_types=1);

$pageTitle = 'Accessories';

function accessoryMoney(?int $centsPerDay, ?int $centsFlat): string
{
    if ($centsFlat !== null && $centsFlat > 0) {
        return '$' . number_format($centsFlat / 100, 2) . ' flat';
    }
    if ($centsPerDay !== null && $centsPerDay > 0) {
        return '$' . number_format($centsPerDay / 100, 2) . '/day';
    }

    return '—';
}

$headerTitle = 'Accessories';
$headerLabel = 'Fleet add-ons';
$headerLead = 'Each row is a physical accessory unit — same as Starlink equipment. Group checkout by SKU when you add stock. Photos and extra details can wait until you have units on hand.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>
<div class="center-actions" style="margin-bottom:1rem;">
    <a class="btn btn-primary" href="<?= escape(route_path('admin/equipment/accessories/edit')) ?>">Add accessory</a>
</div>

<?php if ($equipment === []): ?>
<div class="card">
    <p class="lead" style="margin:0;">No accessories yet. Add units here when you have stock — they will appear at checkout grouped by SKU.</p>
</div>
<?php else: ?>
<div class="card table-wrap">
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th>SKU</th>
            <th>Location</th>
            <th>Status</th>
            <th>Rental price</th>
            <th>Notes</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($equipment as $unit): ?>
            <tr>
                <td><strong><?= escape($unit['nickname']) ?></strong></td>
                <td class="mono"><?= escape($unit['sku'] ?? '—') ?></td>
                <td><?= escape($unit['location_name']) ?></td>
                <td><span class="badge badge-active"><?= escape(str_replace('_', ' ', (string) $unit['status'])) ?></span></td>
                <td class="mono"><?= escape(accessoryMoney(
                    isset($unit['rental_price_cents_per_day']) ? (int) $unit['rental_price_cents_per_day'] : null,
                    isset($unit['rental_price_cents_flat']) ? (int) $unit['rental_price_cents_flat'] : null,
                )) ?></td>
                <td><?= escape($unit['notes'] ?? '—') ?></td>
                <td>
                    <div class="inline-form" style="gap:0.5rem;flex-wrap:nowrap;">
                        <a class="btn btn-secondary" href="<?= escape(route_path('admin/equipment/accessories/edit') . '?id=' . (int) $unit['id']) ?>">Edit</a>
                        <form method="post" action="<?= escape(route_path('admin/equipment/delete')) ?>" onsubmit="return confirm('Delete this accessory permanently? This cannot be undone.');">
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
<?php endif; ?>

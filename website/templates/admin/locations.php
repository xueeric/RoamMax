<?php

declare(strict_types=1);

$pageTitle = 'Locations';
$headerTitle = 'Locations';
$headerLabel = 'Multi-location';
$headerLead = 'Pickup addresses and instructions. Deactivate a location to hide it from the booking calendar.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>

<div class="card table-wrap">
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th>Slug</th>
            <th>Type</th>
            <th>Address</th>
            <th>Pickup instructions</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($locations as $location): ?>
            <tr>
                <td>
                    <strong><?= escape($location['name']) ?></strong><br>
                    <span class="mono" style="color:#9ca3af;font-size:0.75rem;"><?= escape($location['city']) ?>, <?= escape($location['province']) ?></span>
                </td>
                <td class="mono"><?= escape($location['slug']) ?></td>
                <td><?= escape($location['location_type']) ?></td>
                <td><?= escape($location['address']) ?></td>
                <td><?= !empty($location['pickup_instructions']) ? escape($location['pickup_instructions']) : '—' ?></td>
                <td>
                    <div class="inline-form">
                        <a class="btn btn-secondary" href="<?= escape(route_path('admin/locations/edit')) ?>?id=<?= (int) $location['id'] ?>">Edit</a>
                        <form method="post" action="<?= escape(route_path('admin/locations/toggle')) ?>">
    <?= csrf_field() ?>
                            <input type="hidden" name="location_id" value="<?= (int) $location['id'] ?>">
                            <input type="hidden" name="active" value="<?= (int) $location['is_active'] === 1 ? '0' : '1' ?>">
                            <button class="btn btn-secondary" type="submit"><?= (int) $location['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

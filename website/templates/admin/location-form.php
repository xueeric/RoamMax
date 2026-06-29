<?php

declare(strict_types=1);

$pageTitle = 'Edit location';
$loc = $location;
$headerTitle = (string) ($loc['name'] ?? 'Location');
$headerLabel = 'Pickup details';
$headerLead = 'Customer-facing address and pickup instructions for store locations.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<p class="lead"><a href="<?= escape(route_path('admin/locations')) ?>">← All locations</a></p>

<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>

<div class="card">
    <form method="post" action="<?= escape(route_path('admin/locations/save')) ?>" class="form-grid">
    <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $loc['id'] ?>">

        <div class="form-row">
            <label>
                <span>Name</span>
                <input type="text" name="name" required value="<?= escape($loc['name'] ?? '') ?>">
            </label>
            <label>
                <span>Slug</span>
                <input type="text" name="slug" required value="<?= escape($loc['slug'] ?? '') ?>" pattern="[a-z0-9-]+" title="Lowercase letters, numbers, and hyphens only">
            </label>
            <label>
                <span>Type</span>
                <select name="location_type" required>
                    <option value="store" <?= ($loc['location_type'] ?? '') === 'store' ? 'selected' : '' ?>>Store</option>
                    <option value="home" <?= ($loc['location_type'] ?? '') === 'home' ? 'selected' : '' ?>>Home</option>
                    <option value="warehouse" <?= ($loc['location_type'] ?? '') === 'warehouse' ? 'selected' : '' ?>>Warehouse</option>
                </select>
            </label>
        </div>

        <label>
            <span>Street address</span>
            <input type="text" name="address" required value="<?= escape($loc['address'] ?? '') ?>">
        </label>

        <div class="form-row">
            <label>
                <span>City</span>
                <input type="text" name="city" required value="<?= escape($loc['city'] ?? '') ?>">
            </label>
            <label>
                <span>Province</span>
                <input type="text" name="province" value="<?= escape($loc['province'] ?? 'AB') ?>">
            </label>
        </div>

        <label>
            <span>Pickup instructions</span>
            <textarea name="pickup_instructions" rows="4" placeholder="Entrance details, where to meet, partner perks, etc."><?= escape($loc['pickup_instructions'] ?? '') ?></textarea>
            <span class="lead" style="font-size:0.75rem;margin:0;">Shown to customers when they choose store pickup at this location.</span>
        </label>

        <label style="display:flex;gap:0.5rem;align-items:center;">
            <input type="checkbox" name="is_active" value="1" <?= (int) ($loc['is_active'] ?? 0) === 1 ? 'checked' : '' ?> style="width:auto;">
            <span>Active — show on the customer booking calendar (store and home locations only)</span>
        </label>

        <button class="btn btn-primary" type="submit">Save location</button>
    </form>
</div>

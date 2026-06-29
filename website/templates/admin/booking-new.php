<?php

declare(strict_types=1);

$pageTitle = 'Create admin booking';
$prefill = $prefill ?? [
    'customer_id' => 0,
    'location_id' => 0,
    'fulfillment_type' => 'pickup',
    'start_date' => '',
    'end_date' => '',
    'customer_notes' => '',
];
$headerTitle = 'Admin-created booking';
$headerLabel = 'Long-term / custom';
$headerLead = 'Use for 30+ day rentals or custom pricing beyond self-serve limits.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<div class="card">
    <form method="post" action="<?= escape(route_path('admin/bookings/new')) ?>" class="form-grid">
    <?= csrf_field() ?>
        <div class="form-row">
            <label>
                <span>Customer</span>
                <select name="customer_id" required>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int) $customer['id'] ?>" <?= (int) $prefill['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>>
                            <?= escape($customer['name']) ?> (<?= escape($customer['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Location</span>
                <select name="location_id" required>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>" <?= (int) $prefill['location_id'] === (int) $location['id'] ? 'selected' : '' ?>>
                            <?= escape($location['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Fulfillment</span>
                <select name="fulfillment_type">
                    <?php foreach (['pickup' => 'Store pickup', 'pickup_appointment' => 'Home appointment', 'mail_ship' => 'Mail ship'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($prefill['fulfillment_type'] ?? '') === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="form-row">
            <label><span>Start date</span><input type="date" name="start_date" value="<?= escape($prefill['start_date']) ?>" required></label>
            <label><span>End date</span><input type="date" name="end_date" value="<?= escape($prefill['end_date']) ?>" required></label>
            <label><span>Daily rate (CAD)</span><input type="number" step="0.01" min="0" name="daily_rate" required></label>
        </div>
        <label>
            <span>Override rental total (optional CAD)</span>
            <input type="number" step="0.01" min="0" name="rental_total" placeholder="Leave blank to calculate from daily rate">
        </label>
        <label>
            <span>Notes</span>
            <textarea name="customer_notes"><?= escape($prefill['customer_notes']) ?></textarea>
        </label>
        <button class="btn btn-primary" type="submit">Create booking</button>
    </form>
</div>

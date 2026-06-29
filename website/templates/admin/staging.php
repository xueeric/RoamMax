<?php

declare(strict_types=1);

$pageTitle = 'Staging';
$headerTitle = 'Staging events';
$headerLabel = 'Logistics';
$headerLead = 'All fleet moves — Starlink and accessories, same-city or inter-city. Booking-linked moves are created when you assign a unit; schedule proactive moves here. Units in staging are unavailable on staging days.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<?php
$sameCityLeadDays = (int) pricing_config('staging_lead_days', (int) config('staging_lead_days', 1));
$interCityLeadDays = (int) pricing_config('inter_city_staging_lead_days', (int) config('inter_city_staging_lead_days', 2));
?>

<div class="card">
    <div class="section-heading">
        <h2 class="section-title">Lead times</h2>
        <span class="micro-label">Calendar rules</span>
    </div>
    <p class="lead" style="margin-top:0;">
        The calendar adds lead time before a rental can start when a unit must be moved.
        Home pickup (e.g. Edmonton North) is always tomorrow (+1 day). Store pickup with a unit already at that location can start today.
        Same-city store moves (e.g. North → Premium Outlet) use the same-city lead. Other cities use the inter-city lead.
    </p>
    <form method="post" action="<?= escape(route_path('admin/staging/settings')) ?>" class="form-grid">
    <?= csrf_field() ?>
        <div class="form-row">
            <label>
                <span>Same city (days)</span>
                <input type="number" min="0" max="14" name="staging_lead_days" value="<?= $sameCityLeadDays ?>" required>
            </label>
            <label>
                <span>Different city (days)</span>
                <input type="number" min="0" max="14" name="inter_city_staging_lead_days" value="<?= $interCityLeadDays ?>" required>
            </label>
        </div>
        <button class="btn btn-primary" type="submit">Save lead times</button>
    </form>
</div>

<div class="card">
    <div class="section-heading">
        <h2 class="section-title">Schedule move</h2>
        <span class="micro-label">Proactive staging</span>
    </div>
    <p class="lead" style="margin-top:0;font-size:0.875rem;">
        Pre-position stock without a booking — same flow as inter-city transfers. Ready date is computed from lead-time settings.
    </p>
    <form method="post" action="<?= escape(route_path('admin/staging')) ?>" class="form-grid">
    <?= csrf_field() ?>
        <div class="form-row">
            <label>
                <span>Equipment</span>
                <select name="equipment_id" required>
                    <?php foreach ($equipment as $unit): ?>
                        <option value="<?= (int) $unit['id'] ?>">
                            <?= escape($unit['nickname']) ?>
                            (<?= ($unit['equipment_type'] ?? 'starlink') === 'accessory' ? 'Accessory' : 'Starlink' ?>
                            · <?= escape($unit['location_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>To location</span>
                <select name="to_location_id" required>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['id'] ?>"><?= escape($location['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Staging date</span>
                <input type="date" name="staging_date" required>
            </label>
        </div>
        <button class="btn btn-primary" type="submit">Schedule staging</button>
    </form>
</div>

<div class="card table-wrap">
    <table>
        <thead>
        <tr>
            <th>Unit</th>
            <th>Type</th>
            <th>Route</th>
            <th>Staging</th>
            <th>Ready</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($events === []): ?>
            <tr><td colspan="7">No staging events yet.</td></tr>
        <?php else: ?>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= escape($event['equipment_name']) ?></td>
                    <td><?= escape(($event['equipment_type'] ?? 'starlink') === 'accessory' ? 'Accessory' : 'Starlink') ?></td>
                    <td><?= escape($event['from_name']) ?> → <?= escape($event['to_name']) ?></td>
                    <td class="mono"><?= escape($event['staging_date']) ?></td>
                    <td class="mono"><?= escape($event['ready_date']) ?></td>
                    <td><span class="badge badge-active"><?= escape($event['status']) ?></span></td>
                    <td>
                        <?php if ($event['status'] === 'scheduled'): ?>
                            <form method="post" action="<?= escape(route_path('admin/staging/complete')) ?>">
    <?= csrf_field() ?>
                                <input type="hidden" name="staging_id" value="<?= (int) $event['id'] ?>">
                                <button class="btn btn-secondary" type="submit">Mark complete</button>
                            </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

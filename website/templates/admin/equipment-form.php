<?php
declare(strict_types=1);

use Starlink\Services\PricingService;

$equipmentType = $equipmentType ?? 'starlink';
$isAccessory = $equipmentType === 'accessory';
$pageTitle = $isAccessory ? 'Accessory editor' : 'Starlink editor';
$eq = $equipment;
$starlinkPlans = $starlinkPlans ?? [];
$planPeriods = $planPeriods ?? [];
$currentPlanSlug = (string) ($eq['data_plan'] ?? 'roam_100gb');
$headerTitle = $eq ? ($isAccessory ? 'Edit accessory' : 'Edit unit') : ($isAccessory ? 'Add accessory' : 'Add unit');
$headerLabel = $isAccessory ? 'Fleet accessories' : 'Starlink registry';
$listPath = $isAccessory ? route_path('admin/equipment/accessories') : route_path('admin/equipment');
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>
<div class="card"><form method="post" action="<?= escape(route_path('admin/equipment/save')) ?>" class="form-grid">
    <?= csrf_field() ?>
<input type="hidden" name="equipment_type" value="<?= escape($equipmentType) ?>">
<?php if ($eq): ?><input type="hidden" name="id" value="<?= (int) $eq['id'] ?>"><?php endif; ?>

<?php if ($isAccessory): ?>
<div class="form-row">
<label><span>Name</span><input type="text" name="nickname" required value="<?= escape($eq['nickname'] ?? '') ?>"></label>
<label><span>SKU</span><input type="text" name="sku" value="<?= escape($eq['sku'] ?? '') ?>" placeholder="Optional — groups checkout when duplicated"></label>
<label><span>Location</span><select name="current_storage_location_id" required><?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>" <?= ((int) ($eq['current_storage_location_id'] ?? 0) === (int) $l['id']) ? 'selected' : '' ?>><?= escape($l['name']) ?></option><?php endforeach; ?></select></label>
</div>
<div class="form-row">
<label><span>Status</span><select name="status"><?php foreach (['active', 'maintenance', 'retired', 'with_customer'] as $s): ?><option value="<?= $s ?>" <?= ($eq['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
<label><span>Price per day (CAD)</span><input type="number" step="0.01" name="rental_price_per_day" value="<?= isset($eq['rental_price_cents_per_day']) && $eq['rental_price_cents_per_day'] !== null ? number_format((int) $eq['rental_price_cents_per_day'] / 100, 2, '.', '') : '' ?>"></label>
<label><span>Flat price (CAD)</span><input type="number" step="0.01" name="rental_price_flat" value="<?= isset($eq['rental_price_cents_flat']) && $eq['rental_price_cents_flat'] !== null ? number_format((int) $eq['rental_price_cents_flat'] / 100, 2, '.', '') : '' ?>"></label>
</div>
<div class="form-row">
<label><span>Purchase cost (CAD)</span><input type="number" step="0.01" name="purchase_cost" value="<?= isset($eq['purchase_cost_cents']) ? number_format($eq['purchase_cost_cents'] / 100, 2, '.', '') : '' ?>"></label>
<label><span>Purchase date</span><input type="date" name="purchase_date" value="<?= escape($eq['purchase_date'] ?? '') ?>"></label>
</div>
<label><span>Notes</span><textarea name="notes"><?= escape($eq['notes'] ?? '') ?></textarea></label>
<?php else: ?>
<div class="form-row">
<label><span>Nickname</span><input type="text" name="nickname" required value="<?= escape($eq['nickname'] ?? '') ?>"></label>
<label><span>Serial</span><input type="text" name="serial_number" value="<?= escape($eq['serial_number'] ?? '') ?>"></label>
<label><span>Owner type</span><select name="owner_type"><option value="admin" <?= ($eq['owner_type'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option><option value="partner" <?= ($eq['owner_type'] ?? '') === 'partner' ? 'selected' : '' ?>>Partner</option></select></label>
</div>
<div class="form-row">
<label><span>Partner</span><select name="partner_id"><option value="">—</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= ((int) ($eq['partner_id'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>><?= escape($p['name']) ?></option><?php endforeach; ?></select></label>
<label><span>Location</span><select name="current_storage_location_id" required><?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>" <?= ((int) ($eq['current_storage_location_id'] ?? 0) === (int) $l['id']) ? 'selected' : '' ?>><?= escape($l['name']) ?></option><?php endforeach; ?></select></label>
<label><span>Status</span><select name="status"><?php foreach (['active', 'maintenance', 'retired', 'with_customer'] as $s): ?><option value="<?= $s ?>" <?= ($eq['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
</div>
<div class="form-row">
<label><span>Purchase date</span><input type="date" name="purchase_date" value="<?= escape($eq['purchase_date'] ?? '') ?>"></label>
<label><span>Purchase cost (CAD)</span><input type="number" step="0.01" name="purchase_cost" value="<?= isset($eq['purchase_cost_cents']) ? number_format($eq['purchase_cost_cents'] / 100, 2, '.', '') : '' ?>"></label>
<label><span>Priority</span><input type="number" name="rental_priority" value="<?= (int) ($eq['rental_priority'] ?? 0) ?>"></label>
</div>
<label><span>Starlink account email</span><input type="email" name="starlink_account_email" value="<?= escape($eq['starlink_account_email'] ?? '') ?>"></label>
<div class="form-row">
<label><span>Starlink portal password</span><input type="password" name="starlink_account_password" value="" placeholder="<?= !empty($starlinkPassword) ? 'Saved — enter new password to replace' : 'Store portal password' ?>" autocomplete="new-password"></label>
<label style="align-self:end;"><span><input type="checkbox" name="clear_starlink_password" value="1"> Clear saved password</span></label>
</div>
<div class="form-row">
<label><span>Data plan</span>
<select name="plan_slug">
<?php foreach ($starlinkPlans as $plan): ?>
<option value="<?= escape($plan['slug']) ?>" <?= $currentPlanSlug === (string) $plan['slug'] ? 'selected' : '' ?>><?= escape($plan['label']) ?> — $<?= number_format((int) $plan['monthly_cents'] / 100, 2) ?>/mo<?= (int) $plan['data_gb'] > 0 ? ' · ' . (int) $plan['data_gb'] . ' GB' : '' ?></option>
<?php endforeach; ?>
</select>
</label>
<label><span>Plan effective from</span><input type="date" name="plan_effective_from" value="<?= escape(today_date()->format('Y-m-d')) ?>"></label>
<label><span>Subscription payer</span>
<select name="subscription_payer">
<option value="partner" <?= ($eq['subscription_payer'] ?? 'partner') === 'partner' ? 'selected' : '' ?>>Partner pays Starlink bill</option>
<option value="admin" <?= ($eq['subscription_payer'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin pays Starlink bill</option>
</select>
</label>
<label><span>Billing cycle start day</span><input type="number" min="1" max="31" name="billing_cycle_start_day" value="<?= (int) ($eq['billing_cycle_start_day'] ?? 1) ?>"></label>
</div>
<label><span>Plan change notes</span><input type="text" name="plan_change_notes" placeholder="Optional — e.g. upgraded after heavy usage week"></label>
<label><span>Notes</span><textarea name="notes"><?= escape($eq['notes'] ?? '') ?></textarea></label>
<hr>
<div class="form-row"><label><span>Add cost type</span><select name="cost_type"><option value="subscription">Subscription (manual)</option><option value="repair">Repair</option><option value="accessory">Accessory</option><option value="other">Other</option></select></label><label><span>Amount CAD</span><input type="number" step="0.01" name="cost_amount"></label><label><span>Date</span><input type="date" name="cost_date"></label></div>
<label><span>Cost description</span><input type="text" name="cost_description"></label>
<?php endif; ?>

<div class="form-row" style="align-items:center;gap:1rem;">
<button class="btn btn-primary" type="submit">Save</button>
<a class="btn btn-secondary" href="<?= escape($listPath) ?>">Back to list</a>
</div>
</form>
<?php if ($eq): ?>
<form method="post" action="<?= escape(route_path('admin/equipment/delete')) ?>" class="form-row" style="align-items:center;gap:1rem;margin-top:0.75rem;" onsubmit="return confirm('Delete this <?= $isAccessory ? 'accessory' : 'unit' ?> permanently? This cannot be undone.');">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $eq['id'] ?>">
    <button class="btn btn-secondary" type="submit">Delete</button>
</form>
<?php endif; ?>
</div>
<?php if (!$isAccessory && !empty($planPeriods)): ?>
<div class="card table-wrap">
<div class="card-section-heading"><h2 class="section-title">Plan history</h2></div>
<table><thead><tr><th>Plan</th><th>Monthly</th><th>Payer</th><th>From</th><th>To</th><th>Notes</th></tr></thead><tbody>
<?php foreach ($planPeriods as $period): ?>
<tr>
<td><?= escape($period['plan_label'] ?? $period['plan_slug']) ?></td>
<td class="mono">$<?= number_format((int) $period['monthly_cents'] / 100, 2) ?></td>
<td><?= escape(($period['payer'] ?? '') === 'partner' ? 'Partner' : 'Admin') ?></td>
<td class="mono"><?= escape($period['effective_from']) ?></td>
<td class="mono"><?= escape($period['effective_to'] ?? 'Current') ?></td>
<td><?= escape($period['notes'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>
<?php if (!$isAccessory && !empty($costs)): ?><div class="card table-wrap"><table><thead><tr><th>Type</th><th>Amount</th><th>Date</th><th>Description</th></tr></thead><tbody><?php foreach ($costs as $c): ?><tr><td><?= escape($c['type']) ?></td><td class="mono">$<?= number_format($c['amount_cents'] / 100, 2) ?></td><td><?= escape($c['incurred_date']) ?></td><td><?= escape($c['description'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

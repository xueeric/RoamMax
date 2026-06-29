<?php declare(strict_types=1); $pageTitle='Transfers'; $headerTitle='Transfers'; $headerLabel='Inter-city logistics'; require WEBSITE_ROOT . '/templates/admin/_page-header.php'; ?>
<?php if($message=flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>
<div class="card"><form method="post" action="<?= escape(route_path('admin/transfers')) ?>" class="form-grid">
    <?= csrf_field() ?>
<div class="form-row"><label><span>Equipment</span><select name="equipment_id"><?php foreach($equipment as $e): ?><option value="<?= (int)$e['id'] ?>"><?= escape($e['nickname']) ?></option><?php endforeach; ?></select></label>
<label><span>To location</span><select name="to_location_id"><?php foreach($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= escape($l['name']) ?></option><?php endforeach; ?></select></label>
<label><span>Transfer date</span><input type="date" name="transfer_date" required></label></div>
<div class="form-row"><label><span>Transit days</span><input type="number" name="transit_days" value="1" min="1"></label></div>
<label><span>Notes</span><textarea name="notes"></textarea></label>
<button class="btn btn-primary" type="submit">Schedule transfer</button>
</form></div>
<div class="card table-wrap"><table><thead><tr><th>Unit</th><th>Route</th><th>Date</th><th>Action</th></tr></thead><tbody><?php foreach($transfers as $t): ?><tr><td><?= escape($t['equipment_name']) ?></td><td><?= escape($t['from_name']) ?> → <?= escape($t['to_name']) ?></td><td class="mono"><?= escape($t['transfer_date']) ?></td><td><form method="post" action="<?= escape(route_path('admin/transfers/complete')) ?>">
    <?= csrf_field() ?><input type="hidden" name="transfer_id" value="<?= (int)$t['id'] ?>"><button class="btn btn-secondary" type="submit">Mark arrived</button></form></td></tr><?php endforeach; ?></tbody></table></div>

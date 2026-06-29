<?php declare(strict_types=1); $pageTitle='Inventory'; $headerTitle='Inventory management'; $headerLabel='Add-ons'; require WEBSITE_ROOT . '/templates/admin/_page-header.php'; ?>
<?php if($message=flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>
<div class="card"><form method="post" action="<?= escape(route_path('admin/inventory/save')) ?>" class="form-grid">
    <?= csrf_field() ?>
<div class="form-row"><label><span>SKU</span><input name="sku" required></label><label><span>Name</span><input name="name" required></label><label><span>Location</span><select name="location_id"><?php foreach($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= escape($l['name']) ?></option><?php endforeach; ?></select></label></div>
<div class="form-row"><label><span>Total qty</span><input type="number" name="quantity_total" value="1"></label><label><span>Available qty</span><input type="number" name="quantity_available" value="1"></label><label><span>Price/day CAD</span><input type="number" step="0.01" name="price_per_day"></label></div>
<label><span>Flat price CAD</span><input type="number" step="0.01" name="price_flat"></label>
<label><input type="checkbox" name="is_active" value="1" checked> Active</label>
<button class="btn btn-primary" type="submit">Add inventory item</button>
</form></div>
<div class="card table-wrap"><table><thead><tr><th>SKU</th><th>Name</th><th>Location</th><th>Stock</th><th>Active</th></tr></thead><tbody><?php foreach($items as $i): ?><tr><td><?= escape($i['sku']) ?></td><td><?= escape($i['name']) ?></td><td><?= escape($i['location_name']) ?></td><td class="mono"><?= (int)$i['quantity_available'] ?>/<?= (int)$i['quantity_total'] ?></td><td><?= (int)$i['is_active']===1?'Yes':'No' ?></td></tr><?php endforeach; ?></tbody></table></div>

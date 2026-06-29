<?php

declare(strict_types=1);

$pageTitle = 'Partner Swaps';
$headerTitle = 'Swap requests';
$headerLabel = 'Partner ops';
$headerLead = 'Approve partner personal-use swaps by assigning an admin loaner unit.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<div class="card table-wrap">
    <table>
        <thead>
        <tr>
            <th>Partner</th>
            <th>Partner unit</th>
            <th>Dates</th>
            <th>Status</th>
            <th>Loaner</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($swaps === []): ?>
            <tr><td colspan="6">No swap requests yet.</td></tr>
        <?php else: ?>
            <?php foreach ($swaps as $swap): ?>
                <tr>
                    <td><?= escape($swap['partner_name']) ?></td>
                    <td><?= escape($swap['partner_equipment_name']) ?></td>
                    <td class="mono"><?= escape($swap['start_date']) ?> → <?= escape($swap['end_date']) ?></td>
                    <td><span class="badge badge-active"><?= escape($swap['status']) ?></span></td>
                    <td><?= escape($swap['loaner_equipment_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($swap['status'] === 'requested'): ?>
                            <form method="post" action="<?= escape(route_path('admin/swaps')) ?>" class="inline-form">
    <?= csrf_field() ?>
                                <input type="hidden" name="swap_id" value="<?= (int) $swap['id'] ?>">
                                <label>
                                    <span>Loaner</span>
                                    <select name="loaner_equipment_id" required>
                                        <?php foreach ($adminUnits as $unit): ?>
                                            <option value="<?= (int) $unit['id'] ?>"><?= escape($unit['nickname']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="btn btn-primary" type="submit" name="action" value="approve">Approve</button>
                                <button class="btn btn-secondary" type="submit" name="action" value="deny">Deny</button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

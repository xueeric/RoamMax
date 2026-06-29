<?php

declare(strict_types=1);

$pageTitle = 'Users';
$m = static fn (int $c): string => \Starlink\Services\PricingService::formatMoney($c);
$headerTitle = 'Users';
$headerLabel = 'Accounts';
$headerLead = 'Customer accounts, booking activity, and spend.';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1rem;">
    <form method="get" action="<?= escape(route_path('admin/users')) ?>" class="inline-form">
        <label>
            <span class="micro-label">Role</span>
            <select name="role" onchange="this.form.submit()">
                <option value="customer" <?= ($roleFilter ?? 'customer') === 'customer' ? 'selected' : '' ?>>Customers</option>
                <option value="admin" <?= ($roleFilter ?? '') === 'admin' ? 'selected' : '' ?>>Admins</option>
                <option value="partner" <?= ($roleFilter ?? '') === 'partner' ? 'selected' : '' ?>>Partners</option>
                <option value="" <?= ($roleFilter ?? 'customer') === '' ? 'selected' : '' ?>>All roles</option>
            </select>
        </label>
        <label>
            <span class="micro-label">Search</span>
            <input type="search" name="q" value="<?= escape($search ?? '') ?>" placeholder="Name, email, phone, company…">
        </label>
        <button class="btn btn-secondary" type="submit">Search</button>
        <?php if (($search ?? '') !== '' || ($roleFilter ?? 'customer') !== 'customer'): ?>
            <a class="btn btn-secondary" href="<?= escape(route_path('admin/users')) ?>">Clear</a>
        <?php endif; ?>
        <?php if (in_array($roleFilter ?? 'customer', ['customer', 'admin', 'partner'], true)): ?>
            <?php $newRole = $roleFilter ?? 'customer'; ?>
            <a class="btn btn-primary" href="<?= escape(route_path('admin/users/new') . '?role=' . rawurlencode($newRole)) ?>">New <?= escape(ucfirst($newRole)) ?></a>
        <?php endif; ?>
    </form>
</div>

<div class="card table-wrap">
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th>Joined</th>
            <th>Bookings</th>
            <th>Total paid</th>
            <th>Refunded</th>
            <th>Net spent</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($users === []): ?>
            <tr><td colspan="7">No users found.</td></tr>
        <?php else: ?>
            <?php foreach ($users as $row): ?>
                <tr>
                    <td><strong><?= escape($row['name']) ?></strong></td>
                    <td class="mono"><?= escape(substr((string) $row['created_at'], 0, 10)) ?></td>
                    <td class="mono"><?= (int) $row['booking_count'] ?></td>
                    <td class="mono"><?= escape($m((int) $row['total_paid_cents'])) ?></td>
                    <td class="mono"><?= escape($m((int) $row['total_refunded_cents'])) ?></td>
                    <td class="mono"><strong><?= escape($m((int) $row['net_spent_cents'])) ?></strong></td>
                    <td>
                        <a class="btn btn-secondary" href="<?= escape(route_path('admin/users/view') . '?id=' . (int) $row['id']) ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

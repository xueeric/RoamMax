<?php declare(strict_types=1); $pageTitle='Long-term requests'; $headerTitle='Long-term requests'; $headerLabel='Commercial rentals'; require WEBSITE_ROOT . '/templates/admin/_page-header.php'; ?>
<p class="lead">Customer requests for rentals over <?= (int) pricing_config('max_self_serve_days', 30) ?> days.</p>

<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1rem;">
    <form method="get" action="<?= escape(route_path('admin/long-term-requests')) ?>" class="inline-form">
        <label>
            <span class="micro-label">Status</span>
            <select name="status" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (['pending', 'contacted', 'quoted', 'converted', 'declined', 'cancelled'] as $status): ?>
                    <option value="<?= $status ?>" <?= ($statusFilter ?? '') === $status ? 'selected' : '' ?>><?= escape($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</div>

<div class="card table-wrap">
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Customer</th>
            <th>Dates</th>
            <th>Days</th>
            <th>Location</th>
            <th>Fulfillment</th>
            <th>Status</th>
            <th>Notes</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($requests === []): ?>
            <tr><td colspan="9">No requests yet.</td></tr>
        <?php else: ?>
            <?php foreach ($requests as $row): ?>
                <tr>
                    <td class="mono">#<?= (int) $row['id'] ?></td>
                    <td>
                        <strong><?= escape($row['customer_name']) ?></strong><br>
                        <span class="mono"><?= escape($row['customer_email']) ?></span><br>
                        <?= escape($row['customer_phone'] ?? '—') ?>
                    </td>
                    <td class="mono"><?= escape($row['start_date']) ?> → <?= escape($row['end_date']) ?></td>
                    <td class="mono"><?= (int) $row['day_count'] ?></td>
                    <td><?= escape($row['location_name']) ?></td>
                    <td><?= escape(str_replace('_', ' ', $row['fulfillment_type'])) ?></td>
                    <td><span class="badge badge-active"><?= escape($row['status']) ?></span></td>
                    <td style="max-width:14rem;">
                        <?php if (!empty($row['customer_notes'])): ?>
                            <div><strong>Customer:</strong> <?= escape($row['customer_notes']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($row['admin_notes'])): ?>
                            <div><strong>Admin:</strong> <?= escape($row['admin_notes']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= escape(route_path('admin/long-term-requests/update')) ?>" class="form-grid" style="min-width:12rem;">
    <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                            <select name="status">
                                <?php foreach (['pending', 'contacted', 'quoted', 'converted', 'declined', 'cancelled'] as $status): ?>
                                    <option value="<?= $status ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= escape($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="admin_notes" placeholder="Admin notes" value="<?= escape($row['admin_notes'] ?? '') ?>">
                            <button class="btn btn-secondary" type="submit">Update</button>
                        </form>
                        <a class="btn btn-primary" style="margin-top:0.5rem;display:inline-flex;" href="<?= escape(route_path('admin/bookings/new') . '?request_id=' . (int) $row['id']) ?>">Create booking</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

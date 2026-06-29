<?php

declare(strict_types=1);

use Starlink\Auth\PasswordValidator;
use Starlink\Services\AddressService;

$pageTitle = 'User · ' . ($profile['name'] ?? 'Account');
$m = static fn (int $c): string => \Starlink\Services\PricingService::formatMoney($c);
$formatAddress = static function (?array $address): string {
    if ($address === null || $address === []) {
        return '—';
    }

    return AddressService::formatSingleLine($address);
};
$homeAddress = AddressService::decode($profile['home_address_json'] ?? null);
$shippingAddress = AddressService::decode($profile['shipping_address_json'] ?? null);
$billingAddress = AddressService::decode($profile['billing_address_json'] ?? null);
$defaultAddress = AddressService::decode($profile['default_address_json'] ?? null);
$headerTitle = (string) ($profile['name'] ?? 'Account');
$headerLabel = 'User profile';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<p class="lead">
    <a href="<?= escape(route_path('admin/users')) ?>">← All users</a>
</p>

<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= escape($message) ?></div><?php endif; ?>

<div class="stats-grid">
    <div class="card">
        <div class="micro-label">Bookings</div>
        <div class="stat-value"><?= (int) $summary['booking_count'] ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Total paid</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['total_paid_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Refunded (rental)</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['total_refunded_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Deposit on hold</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['deposit_hold_cents'])) ?></div>
    </div>
    <div class="card">
        <div class="micro-label">Net spent</div>
        <div class="stat-value" style="font-size:1rem;"><?= escape($m((int) $summary['net_spent_cents'])) ?></div>
    </div>
</div>

<div class="card" style="margin-top:1rem;">
    <div class="micro-label">Account details</div>
    <table>
        <tbody>
        <tr>
            <th>Email</th>
            <td class="mono"><?= escape($profile['email']) ?></td>
        </tr>
        <tr><th>Phone</th><td><?= escape($profile['phone'] ?? '—') ?></td></tr>
        <tr><th>Role</th><td><?= escape($profile['role']) ?></td></tr>
        <?php if (($profile['role'] ?? '') === 'customer' && !empty($profile['company_name'])): ?>
            <tr><th>Company</th><td><?= escape($profile['company_name']) ?></td></tr>
        <?php endif; ?>
        <tr><th>User ID</th><td class="mono">#<?= (int) $profile['id'] ?></td></tr>
        <tr><th>Joined</th><td class="mono"><?= escape($profile['created_at']) ?></td></tr>
        <?php if (($profile['role'] ?? '') === 'partner' && !empty($profile['partner_record_id'])): ?>
            <tr><th>Partner ID</th><td class="mono">#<?= (int) $profile['partner_record_id'] ?></td></tr>
        <?php endif; ?>
        <?php if (($profile['role'] ?? '') === 'customer'): ?>
            <tr><th>Home address</th><td><?= escape($formatAddress($homeAddress)) ?></td></tr>
            <tr><th>Shipping address</th><td><?= escape($formatAddress($shippingAddress)) ?></td></tr>
            <tr><th>Billing address</th><td><?= escape($formatAddress($billingAddress)) ?></td></tr>
            <?php if ($defaultAddress !== null): ?>
                <tr><th>Legacy default address</th><td><?= escape($formatAddress($defaultAddress)) ?></td></tr>
            <?php endif; ?>
        <?php elseif ($defaultAddress !== null): ?>
            <tr><th>Default address</th><td><?= escape($formatAddress($defaultAddress)) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($profile['locked_until'])): ?>
            <tr><th>Locked until</th><td class="mono"><?= escape($profile['locked_until']) ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (($profile['role'] ?? '') === 'customer'): ?>
    <div class="card" style="margin-top:1rem;">
        <div class="micro-label">Update email</div>
        <form method="post" action="<?= escape(route_path('admin/users/email')) ?>" class="inline-form">
    <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int) $profile['id'] ?>">
            <label style="flex:2 1 16rem;">
                <span class="micro-label">New email</span>
                <input type="email" name="email" value="<?= escape($profile['email']) ?>" required>
            </label>
            <button class="btn btn-secondary" type="submit">Save email</button>
        </form>
    </div>
<?php endif; ?>

<div class="card" style="margin-top:1rem;">
    <div class="micro-label">Reset password</div>
    <form method="post" action="<?= escape(route_path('admin/users/password')) ?>" class="inline-form">
    <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int) $profile['id'] ?>">
        <label>
            <span class="micro-label">New password</span>
            <input type="password" name="password" required minlength="<?= PasswordValidator::MIN_LENGTH ?>" autocomplete="new-password">
            <span class="field-hint"><?= escape(PasswordValidator::REQUIREMENTS) ?></span>
        </label>
        <label>
            <span class="micro-label">Confirm password</span>
            <input type="password" name="password_confirm" required minlength="<?= PasswordValidator::MIN_LENGTH ?>" autocomplete="new-password">
        </label>
        <button class="btn btn-secondary" type="submit">Reset password</button>
    </form>
</div>

<?php if ((int) ($profile['id'] ?? 0) !== (int) ($user['id'] ?? 0)): ?>
<div class="card" style="margin-top:1rem;">
    <div class="micro-label">Delete account</div>
    <p class="lead" style="margin-top:0;">Permanently remove this account. Only allowed when there are no bookings and no partner equipment assigned.</p>
    <form method="post" action="<?= escape(route_path('admin/users/delete')) ?>" onsubmit="return confirm('Delete this user account permanently? This cannot be undone.');">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int) $profile['id'] ?>">
        <button class="btn btn-secondary" type="submit">Delete user</button>
    </form>
</div>
<?php endif; ?>

<div class="card table-wrap" style="margin-top:1rem;">
    <div class="card-section-heading">
        <h2 class="section-title">Bookings</h2>
    </div>
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Dates</th>
            <th>Location</th>
            <th>Unit</th>
            <th>Fulfillment</th>
            <th>Status</th>
            <th>Total</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($bookings === []): ?>
            <tr><td colspan="8">No bookings yet.</td></tr>
        <?php else: ?>
            <?php foreach ($bookings as $booking): ?>
                <?php
                $total = (int) $booking['rental_total_cents']
                    + (int) $booking['shipping_fee_cents']
                    + (int) $booking['add_ons_total_cents']
                    + (int) ($booking['tax_cents'] ?? 0)
                    + (int) $booking['deposit_cents'];
                $bookingHref = route_path('admin/bookings/view') . '?booking_id=' . (int) $booking['id'];
                ?>
                <tr>
                    <td class="mono">
                        <a href="<?= escape($bookingHref) ?>"><?= escape(booking_reference($booking)) ?></a>
                    </td>
                    <td class="mono"><?= escape($booking['start_date']) ?> → <?= escape($booking['end_date']) ?></td>
                    <td><?= escape($booking['location_name']) ?></td>
                    <td><?= escape($booking['equipment_name'] ?? '—') ?></td>
                    <td><?= escape(str_replace('_', ' ', (string) $booking['fulfillment_type'])) ?></td>
                    <td><span class="badge badge-active"><?= escape(booking_customer_status_label($booking)) ?></span></td>
                    <td class="mono"><?= escape($m($total)) ?></td>
                    <td>
                        <a class="btn btn-secondary" href="<?= escape($bookingHref) ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card table-wrap" style="margin-top:1rem;">
    <div class="card-section-heading">
        <h2 class="section-title">Transactions</h2>
    </div>
    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Booking</th>
            <th>Type</th>
            <th>Unit</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Notes</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($payments === []): ?>
            <tr><td colspan="7">No transactions yet.</td></tr>
        <?php else: ?>
            <?php foreach ($payments as $payment): ?>
                <?php $paymentBookingHref = route_path('admin/bookings/view') . '?booking_id=' . (int) $payment['booking_ref']; ?>
                <tr>
                    <td class="mono"><?= escape(substr((string) $payment['created_at'], 0, 10)) ?></td>
                    <td class="mono">
                        <a href="<?= escape($paymentBookingHref) ?>">#<?= (int) $payment['booking_ref'] ?></a>
                    </td>
                    <td><?= escape($payment['type']) ?></td>
                    <td><?= escape($payment['equipment_name'] ?? '—') ?></td>
                    <td class="mono"><?= escape($m((int) $payment['amount_cents'])) ?></td>
                    <td><?= escape($payment['status']) ?></td>
                    <td><?= escape($payment['notes'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

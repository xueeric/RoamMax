<?php

declare(strict_types=1);

use Starlink\Auth\PasswordValidator;

$roleLabels = [
    'customer' => 'customer',
    'admin' => 'admin',
    'partner' => 'partner',
];
$roleLeads = [
    'customer' => 'Create a customer login for bookings and account access.',
    'admin' => 'Create an admin login with full back-office access.',
    'partner' => 'Create a partner login for fleet partners. Assign Starlink units after saving.',
];
$roleLabel = $roleLabels[$role] ?? 'account';
$pageTitle = 'New ' . $roleLabel;
$headerTitle = 'New ' . $roleLabel;
$headerLabel = ucfirst($roleLabel) . ' account';
$headerLead = $roleLeads[$role] ?? '';
require WEBSITE_ROOT . '/templates/admin/_page-header.php';
?>
<p class="lead"><a href="<?= escape(route_path('admin/users') . '?role=' . rawurlencode($role)) ?>">← All <?= escape($roleLabel) ?>s</a></p>

<?php if ($message = flash('error')): ?><div class="alert alert-error"><?= escape($message) ?></div><?php endif; ?>

<div class="card">
    <form method="post" action="<?= escape(route_path('admin/users/new')) ?>" class="form-grid">
    <?= csrf_field() ?>
        <input type="hidden" name="role" value="<?= escape($role) ?>">

        <div class="form-row">
            <label>
                <span>Name</span>
                <input type="text" name="name" required autocomplete="name">
            </label>
            <label>
                <span>Email</span>
                <input type="email" name="email" required autocomplete="email">
            </label>
            <label>
                <span>Phone</span>
                <input type="tel" name="phone" autocomplete="tel">
            </label>
        </div>

        <div class="form-row">
            <label>
                <span>Password</span>
                <input type="password" name="password" required minlength="<?= PasswordValidator::MIN_LENGTH ?>" autocomplete="new-password">
                <span class="field-hint"><?= escape(PasswordValidator::REQUIREMENTS) ?></span>
            </label>
            <label>
                <span>Confirm password</span>
                <input type="password" name="password_confirm" required minlength="<?= PasswordValidator::MIN_LENGTH ?>" autocomplete="new-password">
            </label>
        </div>

        <button class="btn btn-primary" type="submit">Create <?= escape($roleLabel) ?></button>
    </form>
</div>

<?php

declare(strict_types=1);

$pageTitle = 'My profile';
?>
<div class="micro-label">Account</div>
<h1 class="page-title">Profile & addresses</h1>
<p class="lead">Keep your contact details and addresses up to date for faster checkout and account security.</p>

<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>

<div class="calendar-layout">
    <div class="card">
        <form method="post" action="<?= escape(route_path('account/profile')) ?>" class="form-grid">
    <?= csrf_field() ?>
            <div class="micro-label">Contact</div>
            <label>
                <span>Full name</span>
                <input type="text" name="name" value="<?= escape($profile['name']) ?>" required>
            </label>
            <div class="form-row">
                <label>
                    <span>Email</span>
                    <input type="email" value="<?= escape($profile['email']) ?>" disabled>
                </label>
                <label>
                    <span>Phone</span>
                    <input type="tel" name="phone" value="<?= escape($profile['phone']) ?>">
                </label>
            </div>
            <label>
                <span>Company name (optional)</span>
                <input type="text" name="company_name" value="<?= escape($profile['company_name']) ?>">
            </label>

            <?php
            $prefix = 'home_';
            $address = $profile['home_address'] ?? [];
            $includeName = false;
            $required = true;
            $sectionLabel = 'Home address (required for account security)';
            require WEBSITE_ROOT . '/templates/customer/_address-fields.php';
            ?>

            <?php
            $prefix = 'shipping_';
            $address = $profile['shipping_address'] ?? [];
            $includeName = true;
            $required = false;
            $sectionLabel = 'Default shipping address (optional)';
            require WEBSITE_ROOT . '/templates/customer/_address-fields.php';
            ?>

            <?php
            $prefix = 'billing_';
            $address = $profile['billing_address'] ?? [];
            $includeName = false;
            $required = true;
            $sectionLabel = 'Billing address';
            require WEBSITE_ROOT . '/templates/customer/_address-fields.php';
            ?>

            <button class="btn btn-primary" type="submit">Save profile</button>
        </form>
    </div>
</div>

<?php

declare(strict_types=1);

use Starlink\Auth\PasswordValidator;

$pageTitle = 'Register';
?>
<div class="auth-card card">
    <div class="micro-label">Customer Onboarding</div>
    <h1 class="page-title">Create account</h1>
    <p class="lead">Customer accounts are required to book equipment in later phases.</p>

    <?php if ($message = flash('error')): ?>
        <div class="alert alert-error"><?= escape($message) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= escape(route_path('register')) ?>" class="form-grid">
    <?= csrf_field() ?>
    <?= honeypot_field() ?>
        <label>
            <span>Full name</span>
            <input type="text" name="name" required value="<?= old('name') ?>" autocomplete="name">
        </label>
        <label>
            <span>Email</span>
            <input type="email" name="email" required value="<?= old('email') ?>" autocomplete="email">
            <span class="field-hint">Use a real email address (e.g. name@gmail.com).</span>
        </label>
        <label>
            <span>Phone (optional)</span>
            <input type="tel" name="phone" value="<?= old('phone') ?>" autocomplete="tel">
        </label>
        <label>
            <span>Password</span>
            <input type="password" name="password" required minlength="<?= PasswordValidator::MIN_LENGTH ?>" autocomplete="new-password">
            <span class="field-hint"><?= escape(PasswordValidator::REQUIREMENTS) ?></span>
        </label>
        <button class="btn btn-primary" type="submit">Create account</button>
    </form>
</div>

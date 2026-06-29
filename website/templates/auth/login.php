<?php

declare(strict_types=1);

use Starlink\Auth\PasswordValidator;

$pageTitle = 'Sign in';
?>
<div class="auth-card card">
    <div class="micro-label">Access Control</div>
    <h1 class="page-title">Sign in</h1>
    <p class="lead">Admin, partner, and customer accounts use the same login form.</p>

    <?php if ($message = flash('error')): ?>
        <div class="alert alert-error"><?= escape($message) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= escape(route_path('login')) ?>" class="form-grid">
    <?= csrf_field() ?>
        <?php if (!empty($next)): ?>
            <input type="hidden" name="next" value="<?= escape($next) ?>">
        <?php endif; ?>
        <label>
            <span>Email</span>
            <input type="email" name="email" required value="<?= old('email') ?>" autocomplete="email">
        </label>
        <label>
            <span>Password</span>
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <label class="checkbox-inline auth-remember">
            <input type="checkbox" name="remember" value="1">
            <span>Remember me for 30 days</span>
        </label>
        <button class="btn btn-primary" type="submit">Sign in</button>
    </form>

    <p class="auth-footnote lead">
        After 5 failed sign-in attempts, your account is locked for 15 minutes.
    </p>

    <p class="lead" style="margin-top:1.5rem;margin-bottom:0;">
        Need a customer account?
        <a href="<?= escape(route_path('register')) ?>">Register</a>
    </p>
</div>

<div class="card" style="max-width:28rem;margin:1rem auto 0;">
    <?php if (is_version_footer_visible()): ?>
    <div class="micro-label">Dev seed logins</div>
    <p class="lead" style="margin:0;">
        Admin: <span class="mono">admin@starlink.local</span><br>
        Partner: <span class="mono">partner@starlink.local</span><br>
        Password: <span class="mono">changeme</span>
    </p>
    <?php endif; ?>
</div>

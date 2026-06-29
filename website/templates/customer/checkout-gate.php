<?php

declare(strict_types=1);

use Starlink\Auth\PasswordValidator;

$pageTitle = 'Continue to checkout';

$fulfillmentLabel = match ($fulfillmentType) {
    'mail_ship' => 'Mail shipping',
    'city_delivery' => 'Local delivery',
    'pickup_appointment', 'pickup', 'home_appointment', 'store_pickup' => 'Pickup',
    default => str_replace('_', ' ', $fulfillmentType),
};
?>
<div class="micro-label">Checkout</div>
<h1 class="page-title">Continue to checkout</h1>
<p class="lead">
    Sign in or create an account to complete your booking for
    <span class="mono"><?= escape($startDate) ?> → <?= escape($endDate) ?></span>
    (<?= escape($fulfillmentLabel) ?>).
</p>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>

<div class="checkout-gate-layout">
    <div class="checkout-gate-main">
        <div class="checkout-auth-grid">
            <div class="card checkout-auth-card">
                <div class="micro-label">Returning customer</div>
                <h2 class="checkout-auth-title">Sign in</h2>
                <form method="post" action="<?= escape(route_path('login')) ?>" class="form-grid checkout-auth-form">
    <?= csrf_field() ?>
                    <input type="hidden" name="next" value="<?= escape($checkoutReturn) ?>">
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
                    <button class="btn btn-primary checkout-auth-submit" type="submit">Sign in & continue</button>
                </form>
            </div>

            <div class="card checkout-auth-card">
                <div class="micro-label">New customer</div>
                <h2 class="checkout-auth-title">Create account</h2>
                <p class="checkout-auth-lead">Fill in your details below — addresses come on the next step.</p>
                <form method="post" action="<?= escape(route_path('register')) ?>" class="form-grid checkout-auth-form">
    <?= csrf_field() ?>
    <?= honeypot_field() ?>
                    <input type="hidden" name="next" value="<?= escape($checkoutReturn) ?>">
                    <label>
                        <span>Full name</span>
                        <input type="text" name="name" required value="<?= old('name') ?>" autocomplete="name">
                    </label>
                    <label>
                        <span>Email</span>
                        <input type="email" name="email" required value="<?= old('email') ?>" autocomplete="email">
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
                    <button class="btn btn-primary checkout-auth-submit" type="submit">Create account & continue</button>
                </form>
            </div>
        </div>
    </div>

    <?php require WEBSITE_ROOT . '/templates/customer/_quote-panel.php'; ?>
</div>

<p class="lead" style="font-size:0.8125rem;margin-top:1rem;margin-bottom:0;">
    <a href="<?= escape(route_path('/') . '#book') ?>">&larr; Change dates</a>
</p>

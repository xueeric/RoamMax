<?php

declare(strict_types=1);

$pageTitle = 'Starlink Mini Rental — Alberta';
?>
<section class="hero hero-compact">
    <h1>Starlink Mini rental in Alberta</h1>
    <p>Pickup in Edmonton, Calgary, or Red Deer — book online for camping, RV trips, remote work, and events.</p>
    <?php if ($message = flash('error')): ?>
        <div class="alert alert-error"><?= escape($message) ?></div>
    <?php endif; ?>
</section>

<?php require WEBSITE_ROOT . '/templates/customer/_pricing-display.php'; ?>
<?php require WEBSITE_ROOT . '/templates/customer/_calendar.php'; ?>
<?php require WEBSITE_ROOT . '/templates/customer/_faq.php'; ?>

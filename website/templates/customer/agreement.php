<?php

declare(strict_types=1);

$pageTitle = 'Rental Agreement';
$pendingMigrations = $pendingMigrations ?? [];
$isAdmin = $isAdmin ?? false;
?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
    <div class="card agreement-admin-tools">
        <div class="micro-label">Admin</div>
        <p class="lead agreement-admin-tools-lead">
            <?php if ($pendingMigrations === []): ?>
                Database migrations are up to date.
            <?php else: ?>
                <?= count($pendingMigrations) ?> pending migration<?= count($pendingMigrations) === 1 ? '' : 's' ?>:
                <span class="mono"><?= escape(implode(', ', $pendingMigrations)) ?></span>
            <?php endif; ?>
        </p>
        <form method="post" action="<?= escape(route_path('agreement/migrate')) ?>" class="agreement-admin-tools-form">
    <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary">
                <?= $pendingMigrations === [] ? 'Run migration check' : 'Apply pending migrations' ?>
            </button>
        </form>
    </div>
<?php endif; ?>

<div class="card agreement-card">
    <div class="micro-label">Legal</div>
    <h1 class="page-title">Rental agreement</h1>
    <?php require WEBSITE_ROOT . '/templates/customer/_agreement-body.php'; ?>
</div>

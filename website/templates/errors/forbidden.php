<?php

declare(strict_types=1);

$pageTitle = 'Forbidden';
$message = $message ?? 'You do not have access to this page.';
?>
<div class="card" style="max-width:36rem;margin:0 auto;">
    <div class="micro-label">403</div>
    <h1 class="page-title">Access denied</h1>
    <p class="lead"><?= escape($message) ?></p>
    <a class="btn btn-secondary" href="<?= escape(route_path('/')) ?>">Back home</a>
</div>

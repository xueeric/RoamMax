<?php

declare(strict_types=1);

$pageTitle = 'Not found';
?>
<div class="card" style="max-width:36rem;margin:0 auto;">
    <div class="micro-label">404</div>
    <h1 class="page-title">Page not found</h1>
    <p class="lead">That route is not available yet.</p>
    <a class="btn btn-secondary" href="<?= escape(route_path('/')) ?>">Back home</a>
</div>

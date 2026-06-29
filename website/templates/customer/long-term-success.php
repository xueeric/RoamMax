<?php

declare(strict_types=1);

$pageTitle = 'Request submitted';
?>
<div class="micro-label">Long-term rental</div>
<h1 class="page-title">Request sent</h1>

<div class="card">
    <p class="lead" style="margin-bottom:1rem;">
        Your <?= (int) ($request['day_count'] ?? 0) ?>-day rental request
        (<span class="mono"><?= escape($request['start_date'] ?? '') ?> → <?= escape($request['end_date'] ?? '') ?></span>)
        at <?= escape($request['location_name'] ?? '') ?> has been submitted.
    </p>
    <p class="lead" style="margin-bottom:0;">
        Reference <span class="mono">#<?= (int) ($request['id'] ?? 0) ?></span>.
        We'll follow up with custom commercial rates — no need to contact us separately.
    </p>
</div>

<div class="center-actions" style="margin-top:1rem;">
    <a class="btn btn-primary" href="<?= escape(route_path('/') . '#book') ?>">Back to calendar</a>
    <a class="btn btn-secondary" href="<?= escape(route_path('account/bookings')) ?>">My bookings</a>
</div>

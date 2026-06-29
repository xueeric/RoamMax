<?php

declare(strict_types=1);

/** @var string $headerTitle */
/** @var string $headerLabel */
/** @var string|null $headerLead */
?>
<header class="admin-page-header">
    <div class="section-heading">
        <h1 class="section-title"><?= escape($headerTitle) ?></h1>
        <span class="micro-label"><?= escape($headerLabel) ?></span>
    </div>
    <?php if (!empty($headerLead)): ?>
        <p class="lead"><?= escape($headerLead) ?></p>
    <?php endif; ?>
</header>

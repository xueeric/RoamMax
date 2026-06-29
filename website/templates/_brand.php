<?php

declare(strict_types=1);
?>
<a class="brand" href="<?= escape(route_path('/')) ?>">
    <?php require WEBSITE_ROOT . '/templates/_brand-mark.php'; ?>
    <span>
        <div class="brand-title"><?= escape(config('name')) ?></div>
        <div class="brand-sub"><?= escape(config('brand_domain')) ?></div>
    </span>
</a>

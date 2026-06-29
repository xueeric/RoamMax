<?php

declare(strict_types=1);

/** @var string $contentTemplate */
$pageTitle = $pageTitle ?? config('name');
$seo = seo_meta($contentTemplate, is_string($pageTitle) ? $pageTitle : null);
$isAdminArea = str_contains($contentTemplate, '/admin/');
$isPartnerArea = str_contains($contentTemplate, '/partner/');
$showSectionNav = !$isAdminArea && !$isPartnerArea;
$homePath = route_path('/');
$siteName = (string) config('name');
$documentTitle = $seo['title'] === $siteName ? $siteName : $seo['title'] . ' · ' . $siteName;
?>
<!doctype html>
<html lang="en-CA">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($documentTitle) ?></title>
    <meta name="description" content="<?= escape($seo['description']) ?>">
    <meta name="robots" content="<?= escape($seo['robots']) ?>">
    <link rel="canonical" href="<?= escape($seo['canonical']) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_CA">
    <meta property="og:site_name" content="<?= escape($siteName) ?>">
    <meta property="og:title" content="<?= escape($documentTitle) ?>">
    <meta property="og:description" content="<?= escape($seo['description']) ?>">
    <meta property="og:url" content="<?= escape($seo['canonical']) ?>">
    <meta property="og:image" content="<?= escape($seo['og_image']) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= escape($documentTitle) ?>">
    <meta name="twitter:description" content="<?= escape($seo['description']) ?>">
    <meta name="twitter:image" content="<?= escape($seo['og_image']) ?>">
    <?php foreach ($seo['json_ld'] as $schema): ?>
        <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endforeach; ?>
    <link rel="icon" href="<?= escape(route_path('favicon.ico')) ?>" sizes="any">
    <link rel="icon" href="<?= escape(route_path('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= escape(route_path('favicon-32x32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="icon" href="<?= escape(route_path('favicon-16x16.png')) ?>" type="image/png" sizes="16x16">
    <link rel="apple-touch-icon" href="<?= escape(route_path('apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= escape(route_path('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= escape(route_path('assets/css/brand-mark.css')) ?>">
</head>
<body>
<div class="app-shell">
    <header class="site-header">
        <div class="site-header-inner">
            <?php require WEBSITE_ROOT . '/templates/_brand.php'; ?>
            <?php if ($showSectionNav): ?>
                <nav class="nav-section-links" aria-label="Site sections">
                    <a class="nav-link" href="<?= escape($homePath) ?>#pricing">Pricing</a>
                    <a class="nav-link" href="<?= escape($homePath) ?>#book">Booking</a>
                    <a class="nav-link" href="<?= escape($homePath) ?>#faq">FAQ</a>
                </nav>
            <?php endif; ?>
            <div class="nav-account-links">
                <?php if (!empty($user)): ?>
                    <?php if (($user['role'] ?? '') === 'customer'): ?>
                        <a class="nav-link" href="<?= escape(route_path('account/bookings')) ?>">My bookings</a>
                        <a class="nav-link" href="<?= escape(route_path('account/profile')) ?>">Profile</a>
                    <?php elseif (($user['role'] ?? '') === 'admin'): ?>
                        <a class="nav-link" href="<?= escape(route_path('admin')) ?>">Admin</a>
                    <?php elseif (($user['role'] ?? '') === 'partner'): ?>
                        <a class="nav-link" href="<?= escape(route_path('partner')) ?>">Partner</a>
                    <?php endif; ?>
                    <form method="post" action="<?= escape(route_path('logout')) ?>" style="display:inline;">
    <?= csrf_field() ?>
                        <button class="btn btn-secondary" type="submit">Logout</button>
                    </form>
                <?php else: ?>
                    <a class="nav-link" href="<?= escape(route_path('login')) ?>">Login</a>
                    <a class="btn btn-primary" href="<?= escape(route_path('register')) ?>">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
        <?php if (!empty($user) && ($user['role'] ?? '') === 'admin' && $isAdminArea): ?>
            <div class="admin-subnav-wrap">
                <div class="page-wrap">
                    <?php require WEBSITE_ROOT . '/templates/admin/_subnav.php'; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="page-wrap<?= $isAdminArea ? ' admin-page' : '' ?>">
            <?php require $contentTemplate; ?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="page-wrap site-footer-inner">
            <div><?= escape(config('name')) ?> · <?= escape(config('brand_domain')) ?></div>
            <?php if (is_version_footer_visible()): ?>
                <?php $pageVersion = page_version_for_template($contentTemplate); ?>
                <div class="site-footer-dev-notes" aria-label="Development page version">
                    <a class="<?= escape(page_version_badge_class($pageVersion['version'])) ?> site-footer-version-link" href="<?= escape(route_path('version.php')) ?>">v<?= escape($pageVersion['version']) ?></a>
                    <span class="site-footer-dev-sep" aria-hidden="true">·</span>
                    <span>Release v<?= escape(config('version')) ?></span>
                    <span class="site-footer-dev-sep" aria-hidden="true">·</span>
                    <span>Last modified <?= escape($pageVersion['modified']) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </footer>
</div>
</body>
</html>

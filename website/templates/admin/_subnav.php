<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($base === '/' || $base === '\\') {
    $base = '';
}
$currentPath = $base !== '' && str_starts_with($requestPath, $base)
    ? substr($requestPath, strlen($base)) ?: '/'
    : $requestPath;
$currentPath = '/' . ltrim($currentPath, '/');

$pathActive = static function (string $href, bool $prefix = false) use ($currentPath): bool {
    if ($prefix) {
        return str_starts_with($currentPath, $href);
    }

    return $currentPath === $href;
};

$bookingsPath = route_path('admin/bookings');
$appointmentsPath = route_path('admin/appointments');
$newBookingPath = route_path('admin/bookings/new');
$equipmentPath = route_path('admin/equipment');
$accessoriesPath = route_path('admin/equipment/accessories');

$inBookings = $pathActive($bookingsPath, true) || $pathActive($appointmentsPath, true)
    || $pathActive(route_path('admin/long-term-requests'), true);
$inFleet = $pathActive($equipmentPath, true)
    || $pathActive($accessoriesPath, true)
    || $pathActive(route_path('admin/inventory'), true)
    || $pathActive(route_path('admin/blocks'), true)
    || $pathActive(route_path('admin/staging'), true);

/** @var list<array{label:string, href:string, active:bool, children?:list<array{label:string, href:string, active:bool}>}> */
$adminSections = [
    [
        'label' => 'Dashboard',
        'href' => route_path('admin'),
        'active' => $pathActive(route_path('admin')),
    ],
    [
        'label' => 'Bookings',
        'href' => $bookingsPath,
        'active' => $inBookings,
        'children' => [
            [
                'label' => 'All bookings',
                'href' => $bookingsPath,
                'active' => $pathActive($bookingsPath) || ($pathActive($bookingsPath, true) && !$pathActive($newBookingPath) && !$pathActive($appointmentsPath, true)),
            ],
            [
                'label' => 'Appointments',
                'href' => $appointmentsPath,
                'active' => $pathActive($appointmentsPath, true),
            ],
            [
                'label' => 'New booking',
                'href' => $newBookingPath,
                'active' => $pathActive($newBookingPath),
            ],
            [
                'label' => 'Long-term requests',
                'href' => route_path('admin/long-term-requests'),
                'active' => $pathActive(route_path('admin/long-term-requests'), true),
            ],
        ],
    ],
    [
        'label' => 'Fleet',
        'href' => $equipmentPath,
        'active' => $inFleet,
        'children' => [
            [
                'label' => 'Starlink',
                'href' => $equipmentPath,
                'active' => ($pathActive($equipmentPath) || $pathActive($equipmentPath . '/edit', true))
                    && !$pathActive($accessoriesPath, true),
            ],
            [
                'label' => 'Accessories',
                'href' => $accessoriesPath,
                'active' => $pathActive($accessoriesPath, true),
            ],
            [
                'label' => 'Blocks',
                'href' => route_path('admin/blocks'),
                'active' => $pathActive(route_path('admin/blocks'), true),
            ],
            [
                'label' => 'Staging',
                'href' => route_path('admin/staging'),
                'active' => $pathActive(route_path('admin/staging'), true),
            ],
        ],
    ],
    [
        'label' => 'Users',
        'href' => route_path('admin/users'),
        'active' => $pathActive(route_path('admin/users'), true),
    ],
    [
        'label' => 'Locations',
        'href' => route_path('admin/locations'),
        'active' => $pathActive(route_path('admin/locations'), true),
    ],
    [
        'label' => 'Pricing',
        'href' => route_path('admin/pricing'),
        'active' => $pathActive(route_path('admin/pricing'), true),
    ],
    [
        'label' => 'Financials',
        'href' => route_path('admin/financials'),
        'active' => $pathActive(route_path('admin/financials'), true),
    ],
    [
        'label' => 'Notifications',
        'href' => route_path('admin/notifications'),
        'active' => $pathActive(route_path('admin/notifications'), true),
    ],
];

$activeSection = null;
foreach ($adminSections as $section) {
    if ($section['active'] && !empty($section['children'])) {
        $activeSection = $section;
        break;
    }
}
?>
<nav class="admin-subnav" aria-label="Admin sections">
    <?php foreach ($adminSections as $section): ?>
        <a
            class="admin-subnav-link<?= $section['active'] ? ' is-active' : '' ?>"
            href="<?= escape($section['href']) ?>"
            <?= $section['active'] && empty($section['children']) ? 'aria-current="page"' : '' ?>
        ><?= escape($section['label']) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($activeSection !== null): ?>
    <nav class="admin-subnav admin-subnav-secondary" aria-label="<?= escape($activeSection['label']) ?> sections">
        <?php foreach ($activeSection['children'] as $link): ?>
            <a
                class="admin-subnav-link admin-subnav-link-secondary<?= $link['active'] ? ' is-active' : '' ?>"
                href="<?= escape($link['href']) ?>"
                <?= $link['active'] ? 'aria-current="page"' : '' ?>
            ><?= escape($link['label']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

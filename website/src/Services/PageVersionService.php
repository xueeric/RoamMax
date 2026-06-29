<?php

declare(strict_types=1);

namespace Starlink\Services;

final class PageVersionService
{
    /** @var array<string, array{label: string, route: string}> */
    private const PAGE_META = [
        'customer/home' => ['label' => 'Home', 'route' => '/'],
        'customer/checkout' => ['label' => 'Checkout', 'route' => '/checkout'],
        'customer/checkout-gate' => ['label' => 'Checkout gate', 'route' => '/checkout'],
        'customer/agreement' => ['label' => 'Rental agreement', 'route' => '/agreement'],
        'customer/booking-success' => ['label' => 'Booking success', 'route' => '/booking/success'],
        'customer/etransfer-pay' => ['label' => 'e-Transfer payment', 'route' => '/booking/etransfer'],
        'customer/square-pay' => ['label' => 'Card payment', 'route' => '/booking/pay-card'],
        'customer/update-card' => ['label' => 'Update card', 'route' => '/booking/update-card'],
        'customer/long-term-success' => ['label' => 'Long-term request sent', 'route' => '/book/long-term-request/success'],
        'customer/bookings' => ['label' => 'My bookings', 'route' => '/account/bookings'],
        'customer/booking-detail' => ['label' => 'Booking detail', 'route' => '/account/bookings/view'],
        'customer/profile' => ['label' => 'Profile', 'route' => '/account/profile'],
        'customer/payment-method' => ['label' => 'Payment method', 'route' => '/account/bookings/pay'],
        'auth/login' => ['label' => 'Login', 'route' => '/login'],
        'auth/register' => ['label' => 'Register', 'route' => '/register'],
        'admin/dashboard' => ['label' => 'Admin dashboard', 'route' => '/admin'],
        'admin/bookings' => ['label' => 'Admin bookings', 'route' => '/admin/bookings'],
        'admin/booking-detail' => ['label' => 'Admin booking detail', 'route' => '/admin/bookings/view'],
        'admin/booking-new' => ['label' => 'New booking', 'route' => '/admin/bookings/new'],
        'admin/equipment' => ['label' => 'Starlink fleet', 'route' => '/admin/equipment'],
        'admin/equipment-form' => ['label' => 'Starlink editor', 'route' => '/admin/equipment/edit'],
        'admin/accessories' => ['label' => 'Accessories', 'route' => '/admin/equipment/accessories'],
        'admin/locations' => ['label' => 'Locations', 'route' => '/admin/locations'],
        'admin/location-form' => ['label' => 'Location form', 'route' => '/admin/locations/edit'],
        'admin/long-term-requests' => ['label' => 'Long-term requests', 'route' => '/admin/long-term-requests'],
        'admin/pricing' => ['label' => 'Pricing', 'route' => '/admin/pricing'],
        'admin/financials' => ['label' => 'Financials', 'route' => '/admin/financials'],
        'admin/notifications' => ['label' => 'Notifications', 'route' => '/admin/notifications'],
        'admin/users' => ['label' => 'Users', 'route' => '/admin/users'],
        'admin/user-detail' => ['label' => 'User detail', 'route' => '/admin/users/view'],
        'admin/user-new' => ['label' => 'New user', 'route' => '/admin/users/new'],
        'admin/staging' => ['label' => 'Staging', 'route' => '/admin/staging'],
        'admin/blocks' => ['label' => 'Owner blocks', 'route' => '/admin/blocks'],
        'admin/appointments' => ['label' => 'Appointments', 'route' => '/admin/appointments'],
        'admin/inventory' => ['label' => 'Inventory', 'route' => '/admin/inventory'],
        'admin/transfers' => ['label' => 'Transfers', 'route' => '/admin/transfers'],
        'admin/swaps' => ['label' => 'Swap requests', 'route' => '/admin/swaps'],
        'partner/dashboard' => ['label' => 'Partner portal', 'route' => '/partner'],
        'errors/not-found' => ['label' => '404 Not found', 'route' => '—'],
        'errors/forbidden' => ['label' => '403 Forbidden', 'route' => '—'],
        'system/version' => ['label' => 'Version registry', 'route' => '/version.php'],
    ];

    /** @var array{app_version: string, released: string, pages: array<string, array{version?: string}>}|null */
    private static ?array $manifest = null;

    public function isDevDisplayHost(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        if ($host === '' || $host === 'roammax.ca' || $host === 'www.roammax.ca') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '[::1]', 'dev.roammax.ca'], true)) {
            return true;
        }

        return str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_contains($host, 'dev.roammax');
    }

    public function appVersion(): string
    {
        return (string) ($this->manifest()['app_version'] ?? '0.0.0');
    }

    public function releasedDate(): string
    {
        return (string) ($this->manifest()['released'] ?? '');
    }

    /** Page was bumped past the current app release (you have worked on it since ship). */
    public function isAheadOfAppRelease(string $pageVersion): bool
    {
        return $this->compareVersions($pageVersion, $this->appVersion()) > 0;
    }

    /** Page version was not bumped when app release moved forward. */
    public function isBehindAppRelease(string $pageVersion): bool
    {
        return $this->compareVersions($pageVersion, $this->appVersion()) < 0;
    }

    public function versionBadgeClass(string $pageVersion): string
    {
        if ($this->isAheadOfAppRelease($pageVersion)) {
            return 'version-badge version-badge--ahead';
        }

        if ($this->isBehindAppRelease($pageVersion)) {
            return 'version-badge version-badge--behind';
        }

        return 'version-badge version-badge--current';
    }

    public function compareVersions(string $left, string $right): int
    {
        [$leftMajor, $leftMinor] = $this->parseVersion($left);
        [$rightMajor, $rightMinor] = $this->parseVersion($right);

        if ($leftMajor !== $rightMajor) {
            return $leftMajor <=> $rightMajor;
        }

        return $leftMinor <=> $rightMinor;
    }

    /**
     * @return array{key: string, label: string, route: string, open_url: ?string, version: string, ahead_of_release: bool, modified: string, template: string}
     */
    public function forTemplatePath(string $contentTemplate): array
    {
        $key = $this->templateKeyFromPath($contentTemplate);
        $catalog = $this->catalog();
        if (isset($catalog[$key])) {
            return $catalog[$key];
        }

        $version = $this->pageVersion($key);
        $modified = $this->fileModifiedAt($contentTemplate);

        $route = '—';

        return $this->entryMeta($key, $this->humanizeKey($key), $route, $version, $modified);
    }

    /**
     * @return array<string, array{key: string, label: string, route: string, open_url: ?string, version: string, ahead_of_release: bool, modified: string, template: string}>
     */
    public function catalog(): array
    {
        $entries = [];
        foreach (array_keys(self::PAGE_META) as $key) {
            $templatePath = WEBSITE_ROOT . '/templates/' . $key . '.php';
            $route = self::PAGE_META[$key]['route'];
            $version = $this->pageVersion($key);
            $entries[$key] = $this->entryMeta(
                $key,
                self::PAGE_META[$key]['label'],
                $route,
                $version,
                $this->fileModifiedAt($templatePath),
            );
        }

        uasort($entries, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $entries;
    }

    private function templateKeyFromPath(string $contentTemplate): string
    {
        $prefix = WEBSITE_ROOT . '/templates/';
        if (str_starts_with($contentTemplate, $prefix)) {
            return substr($contentTemplate, strlen($prefix), -4);
        }

        return basename($contentTemplate, '.php');
    }

    private function pageVersion(string $key): string
    {
        $pages = $this->manifest()['pages'] ?? [];
        if (isset($pages[$key]['version'])) {
            return (string) $pages[$key]['version'];
        }

        return $this->appVersion();
    }

    private function fileModifiedAt(string $path): string
    {
        if (!is_file($path)) {
            return '—';
        }

        $mtime = filemtime($path);
        if ($mtime === false) {
            return '—';
        }

        return gmdate('Y-m-d', $mtime);
    }

    private function humanizeKey(string $key): string
    {
        $segment = basename(str_replace('/', '-', $key));

        return ucwords(str_replace('-', ' ', $segment));
    }

    private function openUrlForRoute(string $route): ?string
    {
        if ($route === '' || $route === '—') {
            return null;
        }

        return route_path(ltrim($route, '/'));
    }

    /**
     * @return array{key: string, label: string, route: string, open_url: ?string, version: string, ahead_of_release: bool, modified: string, template: string}
     */
    private function entryMeta(
        string $key,
        string $label,
        string $route,
        string $version,
        string $modified,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'route' => $route,
            'open_url' => $this->openUrlForRoute($route),
            'version' => $version,
            'ahead_of_release' => $this->isAheadOfAppRelease($version),
            'modified' => $modified,
            'template' => $key,
        ];
    }

    /** @return array{0: int, 1: int} */
    private function parseVersion(string $version): array
    {
        $version = ltrim($version, 'vV');
        $parts = array_map(static fn (string $part): int => (int) $part, explode('.', $version, 2));

        return [$parts[0] ?? 0, $parts[1] ?? 0];
    }

    /** @return array{app_version: string, released: string, pages: array<string, array{version?: string}>} */
    private function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = WEBSITE_ROOT . '/version.php';
        if (!is_file($path)) {
            self::$manifest = [
                'app_version' => '0.0.0',
                'released' => '',
                'pages' => [],
            ];

            return self::$manifest;
        }

        /** @var array{app_version?: string, released?: string, pages?: array<string, array{version?: string}>} $data */
        $data = require $path;
        self::$manifest = [
            'app_version' => (string) ($data['app_version'] ?? '0.0.0'),
            'released' => (string) ($data['released'] ?? ''),
            'pages' => is_array($data['pages'] ?? null) ? $data['pages'] : [],
        ];

        return self::$manifest;
    }
}

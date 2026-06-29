<?php

declare(strict_types=1);

namespace Starlink\Services;

final class SeoService
{
    /** @var array<string, array{title?: string, description?: string, index?: bool}> */
    private const PAGE_OVERRIDES = [
        'customer/home' => [
            'title' => 'Starlink Mini Rental — Alberta',
            'description' => 'Rent a Starlink Mini in Alberta. Pickup in Edmonton, Calgary, or Red Deer — book online for camping, RV trips, remote work, and events.',
            'index' => true,
        ],
        'customer/agreement' => [
            'title' => 'Rental Agreement',
            'description' => 'RoamMax Starlink Mini rental terms for Alberta — deposits, cancellations, pickup, late returns, and customer responsibilities.',
            'index' => true,
        ],
    ];

    /** @var list<string> */
    private const SITEMAP_PATHS = [
        '/',
        '/agreement',
    ];

    public function isProductionSite(): bool
    {
        if (config('env') !== 'production') {
            return false;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return in_array($host, ['roammax.ca', 'www.roammax.ca'], true);
    }

    /**
     * @return array{
     *   title: string,
     *   description: string,
     *   robots: string,
     *   canonical: string,
     *   og_image: string,
     *   json_ld: list<array<string, mixed>>
     * }
     */
    public function forTemplate(string $contentTemplate, ?string $pageTitle = null): array
    {
        $key = $this->templateKey($contentTemplate);
        $override = self::PAGE_OVERRIDES[$key] ?? [];
        $brand = (string) config('name');
        $title = (string) ($override['title'] ?? $pageTitle ?? $brand);
        $description = (string) ($override['description'] ?? config('seo.default_description', (string) config('brand_tagline')));
        $indexable = $this->isProductionSite()
            && (($override['index'] ?? false) === true);

        return [
            'title' => $title,
            'description' => $description,
            'robots' => $indexable ? 'index, follow' : 'noindex, nofollow',
            'canonical' => $this->canonicalUrl(),
            'og_image' => $this->ogImageUrl(),
            'json_ld' => $indexable && $key === 'customer/home' ? $this->homeJsonLd($description) : [],
        ];
    }

    public function robotsTxt(): string
    {
        $siteUrl = rtrim((string) config('url'), '/');

        if (!$this->isProductionSite()) {
            return "User-agent: *\nDisallow: /\n";
        }

        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /partner',
            'Disallow: /account',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /checkout',
            'Disallow: /book/',
            'Disallow: /booking/',
            'Disallow: /availability',
            'Disallow: /quote',
            'Disallow: /version',
            'Disallow: /webhooks/',
            '',
            'Sitemap: ' . $siteUrl . '/sitemap.xml',
        ];

        return implode("\n", $lines) . "\n";
    }

    public function sitemapXml(): string
    {
        $siteUrl = rtrim((string) config('url'), '/');
        $today = gmdate('Y-m-d');
        $urls = '';

        if ($this->isProductionSite()) {
            foreach (self::SITEMAP_PATHS as $path) {
                $loc = $siteUrl . ($path === '/' ? '' : $path);
                $priority = $path === '/' ? '1.0' : '0.5';
                $urls .= "  <url>\n"
                    . '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n"
                    . '    <lastmod>' . $today . "</lastmod>\n"
                    . '    <changefreq>weekly</changefreq>' . "\n"
                    . '    <priority>' . $priority . "</priority>\n"
                    . "  </url>\n";
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . $urls
            . "</urlset>\n";
    }

    private function templateKey(string $contentTemplate): string
    {
        $root = WEBSITE_ROOT . '/templates/';
        if (!str_starts_with($contentTemplate, $root)) {
            return '';
        }

        $relative = substr($contentTemplate, strlen($root));
        return preg_replace('/\.php$/', '', $relative) ?? $relative;
    }

    private function canonicalUrl(): string
    {
        $siteUrl = rtrim((string) config('url'), '/');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        return $siteUrl . ($path === '/' ? '' : $path);
    }

    private function ogImageUrl(): string
    {
        $siteUrl = rtrim((string) config('url'), '/');
        $relative = (string) config('seo.og_image_path', 'assets/brand/social-og-1200x630.png');

        return $siteUrl . route_path($relative);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function homeJsonLd(string $description): array
    {
        $siteUrl = rtrim((string) config('url'), '/');
        $business = config('seo.business', []);
        $cities = is_array($business['pickup_cities'] ?? null) ? $business['pickup_cities'] : ['Edmonton', 'Calgary', 'Red Deer'];
        $region = (string) ($business['region'] ?? 'AB');

        $pickupPlaces = [];
        foreach ($cities as $city) {
            $pickupPlaces[] = [
                '@type' => 'Place',
                'name' => $city . ', ' . $region,
            ];
        }

        return [
            [
                '@context' => 'https://schema.org',
                '@type' => 'LocalBusiness',
                'name' => (string) config('name'),
                'url' => $siteUrl,
                'description' => $description,
                'image' => $this->ogImageUrl(),
                'areaServed' => [
                    '@type' => 'State',
                    'name' => (string) ($business['area_served'] ?? 'Alberta'),
                ],
                'location' => $pickupPlaces,
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressRegion' => $region,
                    'addressCountry' => 'CA',
                ],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'Service',
                'name' => 'Starlink Mini rental',
                'description' => $description,
                'provider' => [
                    '@type' => 'LocalBusiness',
                    'name' => (string) config('name'),
                    'url' => $siteUrl,
                ],
                'areaServed' => (string) ($business['area_served'] ?? 'Alberta'),
                'serviceType' => 'Satellite internet equipment rental',
            ],
        ];
    }
}

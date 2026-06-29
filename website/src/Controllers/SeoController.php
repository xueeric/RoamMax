<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use Starlink\Services\SeoService;

final class SeoController
{
    public function __construct(
        private readonly SeoService $seo = new SeoService(),
    ) {
    }

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $this->seo->robotsTxt();
    }

    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=UTF-8');
        echo $this->seo->sitemapXml();
    }
}

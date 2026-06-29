<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use Starlink\Services\PageVersionService;

final class VersionController
{
    public function __construct(
        private readonly PageVersionService $versions = new PageVersionService(),
    ) {
    }

    public function index(): void
    {
        if (!$this->versions->isDevDisplayHost()) {
            http_response_code(404);
            view('errors/not-found');
            return;
        }

        view('system/version', [
            'pageTitle' => 'Page versions',
            'catalog' => $this->versions->catalog(),
            'appVersion' => $this->versions->appVersion(),
            'releasedDate' => $this->versions->releasedDate(),
        ]);
    }
}

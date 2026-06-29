<?php

declare(strict_types=1);

namespace Starlink\Services;

final class AgreementService
{
    /** @return array{version: string, html: string, text: string} */
    public function render(): array
    {
        $text = $this->content();

        return [
            'version' => $this->currentVersion(),
            'html' => AgreementMarkdown::toHtml($text),
            'text' => $text,
        ];
    }

    public function content(): string
    {
        $path = WEBSITE_ROOT . '/content/rental-agreement.md';
        if (!is_file($path)) {
            return 'Agreement not found.';
        }

        $content = file_get_contents($path);

        return is_string($content) ? $content : '';
    }

    public function currentVersion(): string
    {
        $path = WEBSITE_ROOT . '/content/agreement-version.php';
        if (!is_file($path)) {
            return '0.0';
        }

        $version = require $path;

        return is_string($version) && trim($version) !== '' ? trim($version) : '0.0';
    }
}

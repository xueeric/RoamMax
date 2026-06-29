<?php

declare(strict_types=1);

namespace Starlink\Services;

final class RateLimitService
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? WEBSITE_ROOT . '/data/rate-limits';
    }

    public function tooManyAttempts(string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        $path = $this->pathFor($bucket);
        $now = time();
        $attempts = $this->readAttempts($path, $now - $windowSeconds);
        $attempts[] = $now;
        $this->writeAttempts($path, $attempts, $now - $windowSeconds);

        return count($attempts) > $maxAttempts;
    }

    public function clear(string $bucket): void
    {
        $path = $this->pathFor($bucket);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @return list<int> */
    private function readAttempts(string $path, int $since): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $attempts = json_decode($raw, true);
        if (!is_array($attempts)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($value): int => (int) $value, $attempts),
            static fn (int $timestamp): bool => $timestamp >= $since,
        ));
    }

    /** @param list<int> $attempts */
    private function writeAttempts(string $path, array $attempts, int $since): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            return;
        }

        $attempts = array_values(array_filter($attempts, static fn (int $timestamp): bool => $timestamp >= $since));
        file_put_contents($path, json_encode($attempts, JSON_THROW_ON_ERROR));
    }

    private function pathFor(string $bucket): string
    {
        return $this->directory . '/' . hash('sha256', $bucket) . '.json';
    }
}

<?php

declare(strict_types=1);

namespace Starlink\Services;

final class DepositCronService
{
    private readonly string $stateFile;

    public function __construct(?string $stateFile = null)
    {
        $this->stateFile = $stateFile ?? WEBSITE_ROOT . '/data/deposit-cron.last.json';
    }

    /**
     * @param array{processed: int, succeeded: int, failed: int} $result
     */
    public function recordRun(array $result): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            return;
        }

        $payload = [
            'ran_at' => now_utc(),
            'processed' => (int) $result['processed'],
            'succeeded' => (int) $result['succeeded'],
            'failed' => (int) $result['failed'],
        ];

        file_put_contents(
            $this->stateFile,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }

    /**
     * PHP cannot read the server crontab. We only know the job ran if this script wrote a heartbeat.
     *
     * @return array{
     *   ran_at: ?string,
     *   processed: ?int,
     *   succeeded: ?int,
     *   failed: ?int,
     *   healthy: bool,
     *   label: string,
     *   tone: string,
     *   crontab_hint: string
     * }
     */
    public function status(): array
    {
        $hint = 'Expected: 0 1 * * * php ' . WEBSITE_ROOT . '/bin/process-deposit-holds.php';

        if (!is_readable($this->stateFile)) {
            return [
                'ran_at' => null,
                'processed' => null,
                'succeeded' => null,
                'failed' => null,
                'healthy' => false,
                'label' => 'No deposit cron run recorded yet',
                'tone' => 'warn',
                'crontab_hint' => $hint,
            ];
        }

        $raw = file_get_contents($this->stateFile);
        if ($raw === false) {
            return [
                'ran_at' => null,
                'processed' => null,
                'succeeded' => null,
                'failed' => null,
                'healthy' => false,
                'label' => 'Could not read deposit cron heartbeat',
                'tone' => 'urgent',
                'crontab_hint' => $hint,
            ];
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true) ?? [];
        $ranAt = trim((string) ($data['ran_at'] ?? ''));
        if ($ranAt === '') {
            return [
                'ran_at' => null,
                'processed' => null,
                'succeeded' => null,
                'failed' => null,
                'healthy' => false,
                'label' => 'Deposit cron heartbeat file is empty',
                'tone' => 'warn',
                'crontab_hint' => $hint,
            ];
        }

        $ran = $this->parseRunTimestamp($ranAt);
        $hoursAgo = $ran !== null ? (time() - $ran->getTimestamp()) / 3600 : 999;
        $healthy = $hoursAgo <= 36;
        $processed = isset($data['processed']) ? (int) $data['processed'] : null;
        $succeeded = isset($data['succeeded']) ? (int) $data['succeeded'] : null;
        $failed = isset($data['failed']) ? (int) $data['failed'] : null;

        $stats = $processed !== null
            ? ' · last run processed ' . $processed . ', ok ' . (int) $succeeded . ', failed ' . (int) $failed
            : '';

        return [
            'ran_at' => $ranAt,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'healthy' => $healthy,
            'label' => ($healthy ? 'Deposit cron ran ' : 'Deposit cron last ran ')
                . $this->relativeTimeLabel($ranAt)
                . $stats,
            'tone' => $healthy ? 'info' : 'warn',
            'crontab_hint' => $hint,
        ];
    }

    private function relativeTimeLabel(string $iso): string
    {
        $ran = $this->parseRunTimestamp($iso);
        if ($ran === null) {
            return $iso;
        }

        $hours = (int) floor((time() - $ran->getTimestamp()) / 3600);
        if ($hours < 1) {
            return 'within the last hour';
        }
        if ($hours < 24) {
            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }

        $days = (int) floor($hours / 24);

        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    private function parseRunTimestamp(string $iso): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $iso);
        if ($parsed !== false) {
            return $parsed;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $iso);

        return $parsed !== false ? $parsed->setTime(0, 0) : null;
    }
}

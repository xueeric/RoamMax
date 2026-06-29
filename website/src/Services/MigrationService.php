<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use RuntimeException;
use Starlink\Database\Connection;
use Throwable;

final class MigrationService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /** @return list<string> */
    public function pending(): array
    {
        $applied = $this->appliedNames();
        $pending = [];

        foreach ($this->migrationFiles() as $file) {
            $name = basename($file);
            if (!in_array($name, $applied, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * @return array{
     *     applied: list<string>,
     *     pending: list<string>,
     *     backfilled: int,
     *     notifications_synced: bool,
     *     failed: ?string
     * }
     */
    public function runPending(): array
    {
        $result = [
            'applied' => [],
            'pending' => [],
            'backfilled' => 0,
            'notifications_synced' => false,
            'failed' => null,
        ];

        try {
            $this->ensureMigrationsTable();

            $appliedNames = $this->appliedNames();

            foreach ($this->migrationFiles() as $file) {
                $name = basename($file);
                if (in_array($name, $appliedNames, true)) {
                    continue;
                }

                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException('Unable to read migration: ' . $name);
                }

                $requiresFkOff = str_contains($sql, 'DROP TABLE bookings');

                if ($requiresFkOff) {
                    $this->db->exec('PRAGMA foreign_keys=OFF');
                }

                $this->db->beginTransaction();
                try {
                    $this->db->exec($sql);
                    $stmt = $this->db->prepare('INSERT INTO migrations (name, applied_at) VALUES (:name, :applied_at)');
                    $stmt->execute([
                        'name' => $name,
                        'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                    $this->db->commit();
                    $result['applied'][] = $name;
                    $appliedNames[] = $name;
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                } finally {
                    if ($requiresFkOff) {
                        $this->db->exec('PRAGMA foreign_keys=ON');
                    }
                }
            }

            $result['backfilled'] = (new BookingReferenceService())->backfillMissing($this->db);
            (new NotificationRuleService())->syncCatalog();
            $result['notifications_synced'] = true;
            $result['pending'] = $this->pending();
        } catch (Throwable $e) {
            $result['failed'] = $e->getMessage();
            $result['pending'] = $this->pending();
        }

        return $result;
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL
            )'
        );
    }

    /** @return list<string> */
    private function appliedNames(): array
    {
        $this->ensureMigrationsTable();

        return $this->db->query('SELECT name FROM migrations ORDER BY name')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $migrationDir = WEBSITE_ROOT . '/database/migrations';
        $files = glob($migrationDir . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Unable to read migrations directory.');
        }

        sort($files);

        return $files;
    }
}

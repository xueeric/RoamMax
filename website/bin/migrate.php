<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Starlink\Services\MigrationService;

$result = (new MigrationService())->runPending();

if ($result['failed'] !== null) {
    fwrite(STDERR, 'Migration failed: ' . $result['failed'] . PHP_EOL);
    exit(1);
}

if ($result['applied'] === []) {
    echo "No pending migrations.\n";
} else {
    echo "Applied migrations:\n";
    foreach ($result['applied'] as $name) {
        echo "  - {$name}\n";
    }
}

if ($result['backfilled'] > 0) {
    echo "Backfilled {$result['backfilled']} booking reference code(s).\n";
}

if ($result['notifications_synced']) {
    echo "Notification rules and templates synced.\n";
}

exit(0);

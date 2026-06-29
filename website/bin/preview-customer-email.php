#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Starlink\Services\NotificationPreviewService;
use Starlink\Services\NotificationRuleService;

$args = array_slice($argv, 1);
$sendTo = null;
$filtered = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--send=')) {
        $sendTo = trim(substr($arg, 7));
        continue;
    }
    if ($arg === '--list') {
        $rules = new NotificationRuleService();
        echo "Customer email events (use event_key):\n";
        foreach ($rules->listRules() as $rule) {
            echo '  ' . $rule['event_key'] . ' — ' . $rule['label'] . PHP_EOL;
        }
        exit(0);
    }
    $filtered[] = $arg;
}

if ($filtered === []) {
    fwrite(STDERR, "Usage: php bin/preview-customer-email.php <event_key> [booking_id] [--send=you@example.com]\n");
    fwrite(STDERR, "       php bin/preview-customer-email.php --list\n");
    exit(1);
}

$eventKey = $filtered[0];
$bookingId = isset($filtered[1]) && ctype_digit($filtered[1]) ? (int) $filtered[1] : 0;

try {
    $preview = (new NotificationPreviewService())->preview($eventKey, $bookingId);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$previewPath = WEBSITE_ROOT . '/data/preview-customer-email.html';
$document = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
    . '<meta charset="UTF-8">' . "\n"
    . '<title>' . htmlspecialchars($preview['subject'], ENT_QUOTES) . "</title>\n"
    . "</head>\n<body>\n"
    . '<p style="font-family:sans-serif;background:#fffbeb;border:1px solid #fcd34d;padding:12px;margin:0;">'
    . '<strong>Preview only</strong> — event <code>' . htmlspecialchars($eventKey, ENT_QUOTES) . '</code>'
    . ' · subject: <strong>' . htmlspecialchars($preview['subject'], ENT_QUOTES) . '</strong>'
    . "</p>\n"
    . $preview['html'] . "\n</body>\n</html>\n";

file_put_contents($previewPath, $document);

echo "Subject: {$preview['subject']}\n";
echo "Preview saved: {$previewPath}\n";
echo "Open in a browser: file://{$previewPath}\n";

if ($sendTo !== null && $sendTo !== '') {
    $mail = new \Starlink\Services\BrevoMailService();
    if (!$mail->isConfigured()) {
        fwrite(STDERR, "Brevo not configured — preview file only.\n");
        exit(1);
    }

    $result = $mail->sendRich(
        $sendTo,
        '[Preview] ' . $preview['subject'],
        $preview['html'],
        $preview['body'],
        'Preview Customer',
    );
    if (!$result['ok'] && !str_contains($preview['html'], 'Reservation details')) {
        $result = $mail->send($sendTo, '[Preview] ' . $preview['subject'], $preview['body'], 'Preview Customer');
    }
    if (!$result['ok']) {
        fwrite(STDERR, 'Send failed: ' . ($result['message'] ?? 'unknown error') . "\n");
        exit(1);
    }

    echo "Sent preview to {$sendTo}\n";
}

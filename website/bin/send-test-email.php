#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Starlink\Services\BrevoMailService;

$to = trim((string) ($argv[1] ?? config('payments.admin_email', '')));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/send-test-email.php [recipient@example.com]\n");
    exit(1);
}

$mail = new BrevoMailService();
if (!$mail->isConfigured()) {
    fwrite(STDERR, "Brevo is not configured. Set BREVO_API_KEY and MAIL_FROM in .env\n");
    exit(1);
}

$result = $mail->send(
    $to,
    'RoamMax — Brevo test email',
    "This is a test message from RoamMax.\n\nIf you received it, Brevo is configured correctly.",
);

if (!$result['ok']) {
    fwrite(STDERR, 'Send failed: ' . ($result['message'] ?? 'unknown error') . "\n");
    exit(1);
}

echo "Test email sent to {$to}";
if (!empty($result['message_id'])) {
    echo " (messageId: {$result['message_id']})";
}
echo "\n";

#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Starlink\Services\BookingTelegramCardService;
use Starlink\Services\TelegramService;

$_ENV['NOTIFICATIONS_LIVE'] = 'true';
putenv('NOTIFICATIONS_LIVE=true');

$telegram = new TelegramService();
if (!$telegram->isConfigured()) {
    fwrite(STDERR, "Set TELEGRAM_BOT_TOKEN and TELEGRAM_ADMIN_CHAT_ID in .env\n");
    exit(1);
}

$arg = trim((string) ($argv[1] ?? ''));
if ($arg !== '' && ctype_digit($arg)) {
    $bookingId = (int) $arg;
    $result = (new BookingTelegramCardService())->sync($bookingId, 'test_message');
    if ($result['status'] !== 'sent') {
        fwrite(STDERR, 'Sync failed: ' . ($result['error'] ?? $result['status']) . "\n");
        exit(1);
    }

    echo 'Booking card ' . ($result['action'] ?? 'synced') . ' in chat ' . $telegram->adminChatId();
    if (!empty($result['message_id'])) {
        echo ' (messageId: ' . $result['message_id'] . ')';
    }
    echo "\n";
    exit(0);
}

$result = $telegram->send(
    'RoamMax — Telegram test',
    "This is a test alert from RoamMax.\n\nIf you received it, Telegram is configured correctly.",
    'test_message',
);

if (!$result['ok']) {
    fwrite(STDERR, 'Send failed: ' . ($result['message'] ?? 'unknown error') . "\n");
    exit(1);
}

echo 'Test Telegram message sent to chat ' . $telegram->adminChatId();
if (!empty($result['message_id'])) {
    echo ' (messageId: ' . $result['message_id'] . ')';
}
echo "\n";

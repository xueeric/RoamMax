#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Starlink\Services\TelegramService;

$telegram = new TelegramService();
if ($telegram->botToken() === '') {
    fwrite(STDERR, "Set TELEGRAM_BOT_TOKEN in .env first.\n");
    exit(1);
}

$result = $telegram->recentUpdates(20);
if (!$result['ok']) {
    fwrite(STDERR, 'Unable to read updates: ' . ($result['message'] ?? 'unknown error') . "\n");
    exit(1);
}

$updates = $result['updates'] ?? [];
if ($updates === []) {
    echo "No recent updates.\n\n";
    echo "1. Open Telegram and send any message to your bot.\n";
    echo "2. For a group alert channel, add the bot to the group and send a message there.\n";
    echo "3. Run this script again.\n";
    exit(0);
}

$seen = [];
echo "Recent Telegram chats:\n\n";
foreach ($updates as $update) {
    $message = is_array($update['message'] ?? null) ? $update['message'] : null;
    if ($message === null && is_array($update['my_chat_member'] ?? null)) {
        $message = ['chat' => $update['my_chat_member']['chat'] ?? []];
    }
    if (!is_array($message)) {
        continue;
    }

    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $chatId = (string) ($chat['id'] ?? '');
    if ($chatId === '' || isset($seen[$chatId])) {
        continue;
    }
    $seen[$chatId] = true;

    $type = (string) ($chat['type'] ?? 'unknown');
    $title = (string) ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? 'Chat');
    echo "- {$title} ({$type})\n";
    echo "  TELEGRAM_ADMIN_CHAT_ID={$chatId}\n\n";
}

echo "Copy the chat ID you want into .env as TELEGRAM_ADMIN_CHAT_ID.\n";

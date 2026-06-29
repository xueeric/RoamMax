<?php

declare(strict_types=1);

namespace Starlink\Services;

final class TelegramService
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed>|null $telegramConfig */
    public function __construct(?array $telegramConfig = null)
    {
        if ($telegramConfig !== null) {
            $this->config = $telegramConfig;
        } else {
            $app = require WEBSITE_ROOT . '/config/app.php';
            $this->config = $app['telegram'] ?? [];
        }
    }

    public function isConfigured(): bool
    {
        return $this->botToken() !== '' && $this->adminChatId() !== '';
    }

    public function adminChatId(): string
    {
        return trim((string) ($this->config['admin_chat_id'] ?? ''));
    }

    public function botToken(): string
    {
        return trim((string) ($this->config['bot_token'] ?? ''));
    }

    /**
     * @return array{ok: bool, message_id?: ?string, message?: string}
     */
    public function sendText(string $text): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Telegram is not configured (set TELEGRAM_BOT_TOKEN and TELEGRAM_ADMIN_CHAT_ID).'];
        }

        return $this->request('sendMessage', [
            'chat_id' => $this->adminChatId(),
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * @return array{ok: bool, message_id?: ?string, message?: string}
     */
    public function editMessage(string $chatId, int $messageId, string $text): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Telegram is not configured.'];
        }

        $result = $this->request('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if (!$result['ok'] && str_contains(strtolower((string) ($result['message'] ?? '')), 'message is not modified')) {
            return ['ok' => true, 'message_id' => (string) $messageId];
        }

        return $result;
    }

    /**
     * @return array{ok: bool, message_id?: ?string, message?: string}
     */
    public function send(string $subject, string $body, ?string $eventKey = null): array
    {
        return $this->sendText($this->formatMessage($subject, $body, $eventKey));
    }

    /**
     * @return array{ok: bool, updates?: list<array<string, mixed>>, message?: string}
     */
    public function recentUpdates(int $limit = 10): array
    {
        if ($this->botToken() === '') {
            return ['ok' => false, 'message' => 'TELEGRAM_BOT_TOKEN is not set.'];
        }

        $result = $this->request('getUpdates', ['limit' => max(1, min($limit, 100))], unwrapResult: false);
        if (!$result['ok']) {
            return $result;
        }

        $data = $result['data'] ?? [];
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => (string) ($data['description'] ?? 'Unable to read Telegram updates.')];
        }

        /** @var list<array<string, mixed>> $updates */
        $updates = is_array($data['result'] ?? null) ? $data['result'] : [];

        return ['ok' => true, 'updates' => $updates];
    }

    private function formatMessage(string $subject, string $body, ?string $eventKey): string
    {
        $brand = $this->escapeHtml((string) config('name', 'RoamMax'));
        $subjectLine = $this->escapeHtml(trim($subject));
        $bodyLine = $this->escapeHtml(trim($body));

        $lines = ["<b>{$brand}</b>", "<b>{$subjectLine}</b>"];
        if ($eventKey !== null && trim($eventKey) !== '') {
            $lines[] = '<code>' . $this->escapeHtml(trim($eventKey)) . '</code>';
        }
        if ($bodyLine !== '') {
            $lines[] = '';
            $lines[] = $bodyLine;
        }

        return implode("\n", $lines);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message_id?: ?string, message?: string, data?: array<string, mixed>}
     */
    private function request(string $method, array $payload, bool $unwrapResult = true): array
    {
        $token = $this->botToken();
        if ($token === '') {
            return ['ok' => false, 'message' => 'TELEGRAM_BOT_TOKEN is not set.'];
        }

        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Unable to initialize Telegram request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (!is_string($body)) {
            return ['ok' => false, 'message' => $curlError !== '' ? $curlError : 'Empty Telegram response.'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'Invalid Telegram response.'];
        }

        if (!$unwrapResult) {
            return [
                'ok' => ($decoded['ok'] ?? false) === true,
                'data' => $decoded,
                'message' => ($decoded['ok'] ?? false) === true ? null : (string) ($decoded['description'] ?? 'Telegram API error.'),
            ];
        }

        if (($decoded['ok'] ?? false) !== true || $status >= 400) {
            return ['ok' => false, 'message' => (string) ($decoded['description'] ?? 'Telegram API error.')];
        }

        $result = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        $messageId = isset($result['message_id']) ? (string) $result['message_id'] : null;

        return ['ok' => true, 'message_id' => $messageId];
    }
}

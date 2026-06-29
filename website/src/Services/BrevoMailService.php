<?php

declare(strict_types=1);

namespace Starlink\Services;

final class BrevoMailService
{
    private const API_BASE = 'https://api.brevo.com/v3';

    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed>|null $mailConfig */
    public function __construct(?array $mailConfig = null)
    {
        if ($mailConfig !== null) {
            $this->config = $mailConfig;
        } else {
            $app = require WEBSITE_ROOT . '/config/app.php';
            $this->config = $app['mail'] ?? [];
        }
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['brevo_api_key'] ?? '')) !== ''
            && trim((string) ($this->config['from_email'] ?? '')) !== '';
    }

    /**
     * @return array{ok: bool, message_id?: string, message?: string}
     */
    public function send(string $to, string $subject, string $body, ?string $toName = null): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Invalid recipient email.'];
        }

        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Brevo is not configured (set BREVO_API_KEY and MAIL_FROM).'];
        }

        $recipient = ['email' => $to];
        if ($toName !== null && trim($toName) !== '') {
            $recipient['name'] = trim($toName);
        }

        return $this->request('POST', '/smtp/email', [
            'sender' => [
                'email' => (string) $this->config['from_email'],
                'name' => (string) ($this->config['from_name'] ?? 'RoamMax'),
            ],
            'to' => [$recipient],
            'subject' => $subject,
            'htmlContent' => $this->wrapHtml($body),
            'textContent' => $body,
        ]);
    }

    public function renderHtml(string $body): string
    {
        return $this->wrapHtml($body);
    }

    public function wrapContent(string $innerHtml): string
    {
        return $this->emailShell($innerHtml);
    }

    /**
     * @return array{ok: bool, message_id?: string, message?: string}
     */
    public function sendRich(
        string $to,
        string $subject,
        string $htmlContent,
        string $textContent,
        ?string $toName = null,
    ): array {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Invalid recipient email.'];
        }

        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Brevo is not configured (set BREVO_API_KEY and MAIL_FROM).'];
        }

        $recipient = ['email' => $to];
        if ($toName !== null && trim($toName) !== '') {
            $recipient['name'] = trim($toName);
        }

        return $this->request('POST', '/smtp/email', [
            'sender' => [
                'email' => (string) $this->config['from_email'],
                'name' => (string) ($this->config['from_name'] ?? 'RoamMax'),
            ],
            'to' => [$recipient],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
            'textContent' => $textContent,
        ]);
    }

    private function wrapHtml(string $body): string
    {
        $escaped = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return $this->emailShell($escaped);
    }

    private function emailShell(string $contentHtml): string
    {
        $brand = htmlspecialchars((string) config('name', 'RoamMax'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $domain = htmlspecialchars((string) config('brand_domain', 'roammax.ca'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logoUrl = htmlspecialchars($this->logoUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;line-height:1.5;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f9fafb;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
          <tr>
            <td style="padding:24px 28px 8px;">
              <table role="presentation" cellspacing="0" cellpadding="0">
                <tr>
                  <td style="padding-right:12px;vertical-align:middle;">
                    <img src="{$logoUrl}" width="48" height="48" alt="{$brand}" style="display:block;border:0;outline:none;text-decoration:none;border-radius:9999px;">
                  </td>
                  <td style="vertical-align:middle;">
                    <div style="font-size:18px;font-weight:700;line-height:1.2;color:#111827;">{$brand}</div>
                    <div style="font-size:12px;line-height:1.3;color:#6b7280;margin-top:2px;">{$domain}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:8px 28px 24px;font-size:15px;">{$contentHtml}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private function logoUrl(): string
    {
        return rtrim((string) config('url'), '/') . '/assets/brand/email-logo.png';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message_id?: string, message?: string}
     */
    private function request(string $method, string $path, array $payload): array
    {
        $url = self::API_BASE . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Unable to initialize Brevo request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'api-key: ' . (string) $this->config['brevo_api_key'],
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
            return ['ok' => false, 'message' => $curlError !== '' ? $curlError : 'Empty Brevo response.'];
        }

        $decoded = json_decode($body, true);
        if ($status >= 200 && $status < 300) {
            $messageId = is_array($decoded) ? (string) ($decoded['messageId'] ?? '') : '';

            return ['ok' => true, 'message_id' => $messageId !== '' ? $messageId : null];
        }

        $message = 'Brevo API error.';
        if (is_array($decoded)) {
            $message = (string) ($decoded['message'] ?? $decoded['code'] ?? $message);
        }

        return ['ok' => false, 'message' => $message];
    }
}

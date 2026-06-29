<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class SquareService
{
    /** @var array<string, mixed> */
    private array $config;

    private readonly PDO $db;

    public function __construct(?array $squareConfig = null, ?PDO $db = null)
    {
        if ($squareConfig !== null) {
            $this->config = $squareConfig;
        } else {
            $app = require WEBSITE_ROOT . '/config/app.php';
            $this->config = $app['square'] ?? [];
        }

        $this->db = $db ?? Connection::get();
    }

    public function isConfigured(): bool
    {
        return ($this->config['access_token'] ?? '') !== ''
            && ($this->config['location_id'] ?? '') !== ''
            && ($this->config['application_id'] ?? '') !== '';
    }

    public function applicationId(): string
    {
        return (string) ($this->config['application_id'] ?? '');
    }

    public function isSandbox(): bool
    {
        return ($this->config['environment'] ?? 'sandbox') !== 'production';
    }

    public function webPaymentsSdkUrl(): string
    {
        return $this->isSandbox()
            ? 'https://sandbox.web.squarecdn.com/v1/square.js'
            : 'https://web.squarecdn.com/v1/square.js';
    }

    /** Application ID must match SQUARE_ENVIRONMENT (sandbox vs production). */
    public function webCredentialsEnvironmentError(): ?string
    {
        $appId = $this->applicationId();
        if ($appId === '') {
            return null;
        }

        $isSandboxAppId = str_starts_with($appId, 'sandbox-');

        if ($this->isSandbox() && !$isSandboxAppId) {
            return 'SQUARE_APPLICATION_ID is for production (sq0idp-…) but SQUARE_ENVIRONMENT=sandbox. '
                . 'In the Square Developer Dashboard → your app → Credentials, copy the Sandbox Application ID '
                . '(starts with sandbox-sq0idb-) into .env.';
        }

        if (!$this->isSandbox() && $isSandboxAppId) {
            return 'SQUARE_APPLICATION_ID is for sandbox but SQUARE_ENVIRONMENT=production. '
                . 'Use the Production Application ID or set SQUARE_ENVIRONMENT=sandbox for testing.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: bool, url?: string, mode?: string, message?: string, amount_cents?: int}
     */
    public function checkoutUrlForBooking(array $booking): array
    {
        $bookingId = (int) $booking['id'];

        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Card payment is not available. Square is not configured on this server.',
            ];
        }

        return [
            'ok' => true,
            'mode' => 'square_card',
            'url' => config('url') . route_path('booking/pay-card') . '?booking_id=' . $bookingId,
        ];
    }

    /**
     * @return array{ok: bool, payment_id?: string, message?: string, data?: array<string, mixed>}
     */
    public function createPayment(
        string $sourceId,
        int $amountCents,
        bool $autocomplete,
        string $note,
        ?string $idempotencyKey = null,
        ?string $customerId = null,
    ): array {
        if ($amountCents <= 0) {
            return ['ok' => false, 'message' => 'Payment amount must be greater than zero.'];
        }

        $payload = [
            'idempotency_key' => $idempotencyKey ?? bin2hex(random_bytes(16)),
            'source_id' => $sourceId,
            'amount_money' => [
                'amount' => $amountCents,
                'currency' => 'CAD',
            ],
            'autocomplete' => $autocomplete,
            'location_id' => $this->config['location_id'],
            'note' => $note,
        ];

        if ($customerId !== null && trim($customerId) !== '') {
            $payload['customer_id'] = trim($customerId);
        } elseif (str_starts_with($sourceId, 'ccof:')) {
            return [
                'ok' => false,
                'message' => 'Square customer ID is required when charging a card on file.',
            ];
        }

        $response = $this->request('POST', '/v2/payments', $payload);
        if (!$response['ok']) {
            return $response;
        }

        $payment = $response['data']['payment'] ?? null;
        if (!is_array($payment) || !is_string($payment['id'] ?? null) || $payment['id'] === '') {
            return ['ok' => false, 'message' => 'Square did not return a payment ID.', 'data' => $response['data'] ?? []];
        }

        $status = (string) ($payment['status'] ?? '');
        $allowed = $autocomplete ? ['COMPLETED', 'APPROVED', 'PENDING'] : ['APPROVED', 'PENDING'];
        if (!in_array($status, $allowed, true)) {
            return [
                'ok' => false,
                'message' => 'Payment was not approved (status: ' . $status . ').',
                'data' => $response['data'],
            ];
        }

        return [
            'ok' => true,
            'payment_id' => $payment['id'],
            'status' => $status,
            'data' => $response['data'],
        ];
    }

    /**
     * @return array{ok: bool, customer_id?: string, message?: string}
     */
    public function createCustomer(string $email, string $givenName): array
    {
        $payload = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'email_address' => $email,
            'given_name' => $givenName !== '' ? $givenName : null,
        ];

        $response = $this->request('POST', '/v2/customers', $payload);
        if (!$response['ok']) {
            return $response;
        }

        $customerId = $response['data']['customer']['id'] ?? null;
        if (!is_string($customerId) || $customerId === '') {
            return ['ok' => false, 'message' => 'Square did not return a customer ID.'];
        }

        return ['ok' => true, 'customer_id' => $customerId];
    }

    /**
     * @return array{ok: bool, card_id?: string, message?: string}
     */
    public function createCardFromPayment(string $customerId, string $paymentId): array
    {
        return $this->createCard($customerId, $paymentId);
    }

    /**
     * @return array{ok: bool, card_id?: string, message?: string}
     */
    public function createCardFromToken(string $customerId, string $sourceId): array
    {
        return $this->createCard($customerId, $sourceId);
    }

    /**
     * @return array{ok: bool, card_id?: string, message?: string}
     */
    private function createCard(string $customerId, string $sourceId): array
    {
        $payload = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'source_id' => $sourceId,
            'card' => [
                'customer_id' => $customerId,
            ],
        ];

        $response = $this->request('POST', '/v2/cards', $payload);
        if (!$response['ok']) {
            return $response;
        }

        $cardId = $response['data']['card']['id'] ?? null;
        if (!is_string($cardId) || $cardId === '') {
            return ['ok' => false, 'message' => 'Square did not return a card ID.'];
        }

        return ['ok' => true, 'card_id' => $cardId];
    }

    /**
     * @return array{ok: bool, payment_id?: string, message?: string}
     */
    public function authorizeDeposit(string $cardId, int $amountCents, string $note, ?string $customerId = null): array
    {
        return $this->createPayment($cardId, $amountCents, false, $note, null, $customerId);
    }

    public function isLocalPaymentId(string $paymentId): bool
    {
        $paymentId = trim($paymentId);

        return $paymentId === ''
            || str_starts_with($paymentId, 'mock_')
            || str_starts_with($paymentId, 'checkout_')
            || str_starts_with($paymentId, 'etransfer_');
    }

    /**
     * @return array{ok: bool, local_only?: bool, message?: string, payment?: array<string, mixed>}
     */
    public function getPayment(string $paymentId): array
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            return ['ok' => false, 'message' => 'Payment ID is empty.'];
        }

        if ($this->isLocalPaymentId($paymentId)) {
            return [
                'ok' => false,
                'local_only' => true,
                'message' => 'This is a local/test payment ID (not in Square).',
            ];
        }

        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Square is not configured.'];
        }

        $response = $this->request('GET', '/v2/payments/' . rawurlencode($paymentId));
        if (!$response['ok']) {
            return $response;
        }

        $payment = $response['data']['payment'] ?? null;
        if (!is_array($payment)) {
            return ['ok' => false, 'message' => 'Square payment not found.'];
        }

        return ['ok' => true, 'payment' => $payment];
    }

    /** @return array{ok: bool, message?: string} */
    public function cancelPayment(string $paymentId): array
    {
        $response = $this->request('POST', '/v2/payments/' . rawurlencode($paymentId) . '/cancel');
        if (!$response['ok']) {
            return $response;
        }

        return ['ok' => true, 'message' => 'Payment authorization cancelled.'];
    }

    /**
     * @return array{ok: bool, payment_id?: string, message?: string}
     */
    public function completePayment(string $paymentId, ?int $amountCents = null): array
    {
        $payload = ['idempotency_key' => bin2hex(random_bytes(16))];
        if ($amountCents !== null && $amountCents > 0) {
            $payload['amount_money'] = [
                'amount' => $amountCents,
                'currency' => 'CAD',
            ];
        }

        $response = $this->request('POST', '/v2/payments/' . rawurlencode($paymentId) . '/complete', $payload);
        if (!$response['ok']) {
            return $response;
        }

        $payment = $response['data']['payment'] ?? null;
        if (!is_array($payment) || !is_string($payment['id'] ?? null) || $payment['id'] === '') {
            return ['ok' => false, 'message' => 'Square did not return a payment ID.'];
        }

        return ['ok' => true, 'payment_id' => $payment['id']];
    }

    /**
     * @return array{ok: bool, refund_id?: string, message?: string}
     */
    public function refundPayment(string $paymentId, int $amountCents, string $note): array
    {
        $payload = [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'payment_id' => $paymentId,
            'amount_money' => [
                'amount' => $amountCents,
                'currency' => 'CAD',
            ],
            'reason' => $note,
        ];

        $response = $this->request('POST', '/v2/refunds', $payload);
        if (!$response['ok']) {
            return $response;
        }

        $refundId = $response['data']['refund']['id'] ?? null;

        return [
            'ok' => true,
            'refund_id' => is_string($refundId) ? $refundId : null,
        ];
    }

    public function verifyWebhookSignature(string $rawBody): bool
    {
        $signatureKey = trim((string) ($this->config['webhook_signature_key'] ?? ''));
        if ($signatureKey === '') {
            return config('env') !== 'production';
        }

        $signature = (string) ($_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '');
        if ($signature === '') {
            return false;
        }

        $notificationUrl = rtrim((string) config('url'), '/') . route_path('webhooks/square');
        $payload = $notificationUrl . $rawBody;
        $expected = base64_encode(hash_hmac('sha256', $payload, $signatureKey, true));

        return hash_equals($expected, $signature);
    }

    /** @param array<string, mixed> $payload */
    public function handleWebhook(array $payload): array
    {
        $type = (string) ($payload['type'] ?? '');

        if ($type === 'payment.updated' || $type === 'payment.created') {
            $payment = $payload['data']['object']['payment'] ?? null;
            if (is_array($payment) && ($payment['status'] ?? '') === 'COMPLETED') {
                $note = (string) ($payment['note'] ?? '');
                $bookingId = $this->extractBookingId($payment);
                $booking = $bookingId !== null
                    ? (new BookingService(db: $this->db))->findBooking($bookingId)
                    : null;

                if ($this->isDepositFlowNote($note)) {
                    return ['ok' => true, 'message' => 'Deposit-flow payment ignored.', 'type' => $type];
                }

                if ($booking !== null) {
                    $lifecycle = booking_lifecycle_status($booking);
                    if ($lifecycle !== 'booking_pending_payment') {
                        return ['ok' => true, 'message' => 'Booking not awaiting payment.', 'type' => $type];
                    }

                    if (($booking['payment_method'] ?? '') === 'square' && $lifecycle !== 'booking_pending_payment') {
                        return ['ok' => true, 'message' => 'Square checkout handled by deposit service.', 'type' => $type];
                    }
                }
            }

            return ['ok' => true, 'message' => 'Card webhook ignored; deposit checkout handles payment.', 'type' => $type];
        }

        return ['ok' => true, 'message' => 'Webhook received.', 'type' => $type];
    }

    private function isDepositFlowNote(string $note): bool
    {
        return str_contains($note, 'deposit')
            || str_contains($note, 'rental+deposit')
            || str_contains($note, 'deposit_hold');
    }

    /** @param array<string, mixed> $payment */
    private function extractBookingId(array $payment): ?int
    {
        $note = (string) ($payment['note'] ?? '');
        if (preg_match('/booking:(\d+)/', $note, $matches)) {
            return (int) $matches[1];
        }

        $reference = (string) ($payment['reference_id'] ?? '');
        if (preg_match('/booking:(\d+)/', $reference, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{ok: bool, message?: string, data?: array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim((string) ($this->config['api_base'] ?? ''), '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Unable to initialize Square request.'];
        }

        $headers = [
            'Authorization: Bearer ' . ($this->config['access_token'] ?? ''),
            'Content-Type: application/json',
            'Square-Version: 2024-10-17',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_string($body)) {
            return ['ok' => false, 'message' => 'Empty Square response.'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'Invalid Square response.'];
        }

        if ($status >= 400) {
            $message = $decoded['errors'][0]['detail'] ?? $decoded['errors'][0]['code'] ?? 'Square API error.';

            return ['ok' => false, 'message' => (string) $message, 'data' => $decoded];
        }

        return ['ok' => true, 'data' => $decoded];
    }
}

<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class NotificationService
{
    private readonly PDO $db;

    public function __construct(
        private readonly NotificationRuleService $rules = new NotificationRuleService(),
        private readonly BrevoMailService $mail = new BrevoMailService(),
        private readonly TelegramService $telegram = new TelegramService(),
        private readonly BookingTelegramCardService $telegramCards = new BookingTelegramCardService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    /** @param array<string, mixed> $booking @param array<string, mixed> $context */
    public function dispatch(string $eventKey, array $booking, array $context = []): void
    {
        $rule = $this->rules->ruleFor($eventKey);
        if ($rule === null) {
            return;
        }

        $template = $this->rules->render($eventKey, $booking, $context);

        if ((int) ($rule['notify_customer_email'] ?? 0) === 1) {
            $email = trim((string) ($booking['customer_email'] ?? ''));
            if ($email !== '') {
                $this->recordDelivery(
                    'email',
                    $eventKey,
                    $email,
                    $template['subject'],
                    $template['body'],
                    $booking,
                    (string) ($booking['customer_name'] ?? ''),
                    true,
                    $context,
                );
            }
        }

        if ((int) ($rule['notify_admin_email'] ?? 0) === 1) {
            $adminEmail = (string) config('payments.admin_email', config('mail.from_email', 'payments@roammax.ca'));
            $this->recordDelivery('email', $eventKey, $adminEmail, $template['subject'], $template['body'], $booking);
        }

        $this->syncTelegram($eventKey, $booking, $template, $rule, $context);
    }

    /** @param array<string, mixed> $booking @param array<string, string> $template @param array<string, mixed> $rule @param array<string, mixed> $context */
    private function syncTelegram(string $eventKey, array $booking, array $template, array $rule, array $context = []): void
    {
        if ((int) ($rule['notify_admin_telegram'] ?? 0) !== 1) {
            return;
        }

        if (!$this->telegram->isConfigured()) {
            return;
        }

        $chatId = $this->telegram->adminChatId();
        $recipient = $chatId !== '' ? 'telegram:' . $chatId : 'telegram:unconfigured';
        $bookingId = (int) ($booking['id'] ?? 0);

        if ($bookingId > 0) {
            $this->recordDelivery(
                'telegram',
                $eventKey,
                $recipient,
                booking_reference($booking),
                '',
                $booking,
            );

            return;
        }

        if ($eventKey === 'long_term_request_created') {
            $this->recordDelivery(
                'telegram',
                $eventKey,
                $recipient,
                $template['subject'],
                $template['body'],
                $booking,
                null,
                false,
                $context,
            );
        }
    }

    /** @param array<string, mixed> $context @param array<string, mixed> $emailContext */
    private function recordDelivery(
        string $channel,
        string $eventKey,
        string $recipient,
        string $subject,
        string $body,
        array $context,
        ?string $recipientName = null,
        bool $isCustomerEmail = false,
        array $emailContext = [],
    ): void {
        $deliveryStatus = 'logged';
        $errorMessage = null;
        $externalId = null;
        $externalKey = null;

        if ($channel === 'email') {
            $result = $this->deliverEmail($recipient, $subject, $body, $recipientName, $isCustomerEmail, $eventKey, $context, $emailContext);
            $deliveryStatus = $result['status'];
            $errorMessage = $result['error'];
            $externalId = $result['message_id'];
            $externalKey = 'brevo_message_id';
        } elseif ($channel === 'telegram') {
            $bookingId = (int) ($context['id'] ?? 0);
            if ($eventKey === 'long_term_request_created') {
                $result = $this->deliverLongTermTelegram($context, $emailContext);
            } elseif ($bookingId > 0) {
                $result = $this->deliverTelegramBookingCard($bookingId, $eventKey);
            } else {
                $result = $this->deliverTelegram($subject, $body, $eventKey);
            }
            $deliveryStatus = $result['status'];
            $errorMessage = $result['error'];
            $externalId = $result['message_id'];
            $externalKey = 'telegram_message_id';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO notification_log (
                recipient, subject, body, context_json, channel, event_key, delivery_status, error_message, created_at
             ) VALUES (
                :recipient, :subject, :body, :context_json, :channel, :event_key, :delivery_status, :error_message, :created_at
             )'
        );
        $contextJson = ['booking_id' => (int) ($context['id'] ?? 0), 'event_key' => $eventKey];
        if ($externalId !== null && $externalId !== '' && $externalKey !== null) {
            $contextJson[$externalKey] = $externalId;
        }

        $stmt->execute([
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'context_json' => json_encode($contextJson, JSON_THROW_ON_ERROR),
            'channel' => $channel,
            'event_key' => $eventKey,
            'delivery_status' => $deliveryStatus,
            'error_message' => $errorMessage,
            'created_at' => now_utc(),
        ]);

        $logLine = sprintf(
            "[%s] %s TO:%s EVENT:%s STATUS:%s SUBJECT:%s BODY:%s%s\n",
            now_utc(),
            strtoupper($channel),
            $recipient,
            $eventKey,
            $deliveryStatus,
            $subject,
            str_replace("\n", ' ', $body),
            $errorMessage !== null ? ' ERROR:' . $errorMessage : '',
        );
        file_put_contents(WEBSITE_ROOT . '/data/notifications.log', $logLine, FILE_APPEND);
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $emailContext
     * @return array{status: string, error: ?string, message_id: ?string}
     */
    private function deliverEmail(
        string $recipient,
        string $subject,
        string $body,
        ?string $recipientName,
        bool $isCustomerEmail = false,
        string $eventKey = '',
        array $booking = [],
        array $emailContext = [],
    ): array {
        if (!notifications_live() || !$this->mail->isConfigured()) {
            return ['status' => 'logged', 'error' => null, 'message_id' => null];
        }

        if ($isCustomerEmail
            && (int) ($booking['id'] ?? 0) > 0
            && CustomerBookingEmailComposer::supports($eventKey)
        ) {
            $composed = (new CustomerBookingEmailComposer())->compose($eventKey, $booking, $body, $emailContext);
            $result = $this->mail->sendRich(
                $recipient,
                $subject,
                $composed['html'],
                $composed['plain'],
                $recipientName,
            );
        } else {
            $result = $this->mail->send($recipient, $subject, $body, $recipientName);
        }
        if ($result['ok']) {
            return [
                'status' => 'sent',
                'error' => null,
                'message_id' => $result['message_id'] ?? null,
            ];
        }

        return [
            'status' => 'failed',
            'error' => $result['message'] ?? 'Email send failed.',
            'message_id' => null,
        ];
    }

    /**
     * @return array{status: string, error: ?string, message_id: ?string}
     */
    private function deliverTelegramBookingCard(int $bookingId, string $eventKey): array
    {
        $result = $this->telegramCards->sync($bookingId, $eventKey);

        return [
            'status' => $result['status'],
            'error' => $result['error'],
            'message_id' => $result['message_id'],
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $context
     * @return array{status: string, error: ?string, message_id: ?string}
     */
    private function deliverLongTermTelegram(array $booking, array $context): array
    {
        if (!notifications_live() || !$this->telegram->isConfigured()) {
            return ['status' => 'logged', 'error' => null, 'message_id' => null];
        }

        $requestId = (int) ($context['request_id'] ?? 0);
        $dayCount = (int) ($context['day_count'] ?? 0);
        $customerName = trim((string) ($booking['customer_name'] ?? '—'));
        $customerEmail = trim((string) ($booking['customer_email'] ?? '—'));
        $customerPhone = trim((string) ($booking['customer_phone'] ?? ''));
        $startDate = (string) ($booking['start_date'] ?? '—');
        $endDate = (string) ($booking['end_date'] ?? '—');
        $locationName = (string) ($context['location_name'] ?? $booking['location_name'] ?? '—');
        $fulfillmentType = (string) ($context['fulfillment_type'] ?? str_replace('_', ' ', (string) ($booking['fulfillment_type'] ?? '—')));
        $notes = trim((string) ($booking['customer_notes'] ?? ''));
        $adminUrl = rtrim((string) config('url'), '/') . '/admin/long-term-requests';

        $lines = [
            '📋 <b>Long-term quote request</b>',
            '<b>Request #' . htmlspecialchars((string) $requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>',
            'Customer: ' . htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'Email: ' . htmlspecialchars($customerEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];
        if ($customerPhone !== '') {
            $lines[] = 'Phone: ' . htmlspecialchars($customerPhone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $lines[] = 'Dates: ' . htmlspecialchars($startDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' → ' . htmlspecialchars($endDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' (' . htmlspecialchars((string) $dayCount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' days)';
        $lines[] = 'Location: ' . htmlspecialchars($locationName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines[] = 'Fulfillment: ' . htmlspecialchars($fulfillmentType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($notes !== '') {
            $lines[] = 'Notes: ' . htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $lines[] = '';
        $lines[] = '<a href="' . htmlspecialchars($adminUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Open in admin</a>';

        $result = $this->telegram->sendText(implode("\n", $lines));
        if ($result['ok']) {
            return [
                'status' => 'sent',
                'error' => null,
                'message_id' => $result['message_id'] ?? null,
            ];
        }

        return [
            'status' => 'failed',
            'error' => $result['message'] ?? 'Telegram send failed.',
            'message_id' => null,
        ];
    }

    /**
     * @return array{status: string, error: ?string, message_id: ?string}
     */
    private function deliverTelegram(string $subject, string $body, string $eventKey): array
    {
        if (!notifications_live() || !$this->telegram->isConfigured()) {
            return ['status' => 'logged', 'error' => null, 'message_id' => null];
        }

        $result = $this->telegram->send($subject, $body, $eventKey);
        if ($result['ok']) {
            return [
                'status' => 'sent',
                'error' => null,
                'message_id' => $result['message_id'] ?? null,
            ];
        }

        return [
            'status' => 'failed',
            'error' => $result['message'] ?? 'Telegram send failed.',
            'message_id' => null,
        ];
    }

    /** @param array<string, mixed> $booking */
    public function bookingConfirmed(array $booking): void
    {
        $event = match (booking_payment_status($booking)) {
            'payment_square_full_captured' => 'payment_square_full_captured',
            'payment_etransfer_partial_confirmed' => 'payment_etransfer_partial_confirmed',
            default => 'payment_etransfer_confirmed',
        };
        $this->dispatch($event, $booking);
    }

    public function appointmentUpdate(array $booking, string $message): void
    {
        $event = match ($booking['appointment_status'] ?? '') {
            'appointment_proposed' => 'appointment_proposed',
            'appointment_confirmed' => 'appointment_confirmed',
            default => 'appointment_awaiting_admin',
        };
        $this->dispatch($event, $booking, ['reason' => $message]);
    }

    public function bookingCancelled(array $booking, string $message, bool $byAdmin = false): void
    {
        $this->dispatch(
            $byAdmin ? 'booking_cancelled_by_admin' : 'booking_cancelled_by_customer',
            $booking,
            ['reason' => $message],
        );
    }

    public function bookingDepositPending(array $booking): void
    {
        $this->dispatch('payment_square_rental_captured', $booking);
    }

    public function bookingReadyForPickup(array $booking): void
    {
        $this->dispatch('payment_deposit_scheduled_processed', $booking);
    }

    public function depositAuthorizationFailed(array $booking, string $updateCardUrl): void
    {
        $this->dispatch('payment_deposit_scheduled_failed', $booking, ['action_url' => $updateCardUrl]);
    }

    /** @param array<string, mixed> $booking */
    public function notify(string $recipient, string $subject, string $body, array $context = []): void
    {
        $result = $this->deliverEmail($recipient, $subject, $body, null);

        $stmt = $this->db->prepare(
            'INSERT INTO notification_log (recipient, subject, body, context_json, channel, delivery_status, error_message, created_at)
             VALUES (:recipient, :subject, :body, :context_json, :channel, :delivery_status, :error_message, :created_at)'
        );
        $contextJson = $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR);
        if ($result['message_id'] !== null && $result['message_id'] !== '') {
            $payload = $context === [] ? [] : $context;
            $payload['brevo_message_id'] = $result['message_id'];
            $contextJson = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        $stmt->execute([
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'context_json' => $contextJson,
            'channel' => 'email',
            'delivery_status' => $result['status'],
            'error_message' => $result['error'],
            'created_at' => now_utc(),
        ]);

        $logLine = sprintf(
            "[%s] TO:%s STATUS:%s SUBJECT:%s BODY:%s%s\n",
            now_utc(),
            $recipient,
            $result['status'],
            $subject,
            str_replace("\n", ' ', $body),
            $result['error'] !== null ? ' ERROR:' . $result['error'] : '',
        );
        file_put_contents(WEBSITE_ROOT . '/data/notifications.log', $logLine, FILE_APPEND);
    }
}

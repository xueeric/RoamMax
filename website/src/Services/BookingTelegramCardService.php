<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class BookingTelegramCardService
{
    private readonly PDO $db;

    public function __construct(
        private readonly TelegramService $telegram = new TelegramService(),
        private readonly BookingService $bookings = new BookingService(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    /**
     * @return array{status: string, error: ?string, message_id: ?string, action: ?string}
     */
    public function sync(int $bookingId, ?string $triggerEvent = null): array
    {
        if (!notifications_live() || !$this->telegram->isConfigured()) {
            return ['status' => 'logged', 'error' => null, 'message_id' => null, 'action' => null];
        }

        $booking = $this->bookings->findBooking($bookingId);
        if ($booking === null) {
            return ['status' => 'failed', 'error' => 'Booking not found.', 'message_id' => null, 'action' => null];
        }

        $text = $this->render($booking, $triggerEvent);
        $chatId = $this->telegram->adminChatId();
        $stored = $this->findStoredMessage($bookingId);

        if ($stored !== null && (string) $stored['chat_id'] === $chatId) {
            $edit = $this->telegram->editMessage($chatId, (int) $stored['message_id'], $text);
            if ($edit['ok']) {
                $this->touchStoredMessage($bookingId);

                return [
                    'status' => 'sent',
                    'error' => null,
                    'message_id' => (string) $stored['message_id'],
                    'action' => 'updated',
                ];
            }

            if (!$this->shouldSendFreshMessage($edit['message'] ?? '')) {
                return [
                    'status' => 'failed',
                    'error' => $edit['message'] ?? 'Telegram edit failed.',
                    'message_id' => null,
                    'action' => 'update_failed',
                ];
            }
        }

        $send = $this->telegram->sendText($text);
        if (!$send['ok']) {
            return [
                'status' => 'failed',
                'error' => $send['message'] ?? 'Telegram send failed.',
                'message_id' => null,
                'action' => 'send_failed',
            ];
        }

        $messageId = (int) ($send['message_id'] ?? 0);
        if ($messageId <= 0) {
            return ['status' => 'failed', 'error' => 'Telegram did not return a message id.', 'message_id' => null, 'action' => null];
        }

        $this->storeMessage($bookingId, $chatId, $messageId);

        return [
            'status' => 'sent',
            'error' => null,
            'message_id' => (string) $messageId,
            'action' => 'created',
        ];
    }

    /** @param array<string, mixed> $booking */
    public function render(array $booking, ?string $triggerEvent = null): string
    {
        $reference = booking_reference($booking);
        $bookingId = (int) ($booking['id'] ?? 0);
        $pay = booking_admin_payment_summary($booking);
        $attention = (new AdminBookingPresenter())->attentionItems($booking);
        $lifecycle = booking_lifecycle_status($booking);
        $fulfillment = booking_fulfillment_status($booking);
        $badge = $this->statusBadge($lifecycle, booking_payment_status($booking));

        $lines = [
            $badge['icon'] . ' <b>' . $this->e($badge['label']) . '</b>',
            '<b>Booking #' . $this->e((string) $bookingId) . ' · ' . $this->e($reference) . '</b>',
            'Customer: ' . $this->e(trim((string) ($booking['customer_name'] ?? '—'))),
            'Email: ' . $this->e(trim((string) ($booking['customer_email'] ?? '—'))),
            'Dates: ' . $this->e((string) ($booking['start_date'] ?? '—')) . ' → ' . $this->e((string) ($booking['end_date'] ?? '—'))
                . ' (' . $this->e((string) ($pay['rental_days'] ?? 0)) . ' days)',
            'Fulfillment: ' . $this->e($this->fulfillmentLabel((string) ($booking['fulfillment_type'] ?? '')))
                . ' · ' . $this->e((string) ($booking['location_name'] ?? '—')),
            '',
            '<b>Payment</b>',
            'Method: ' . $this->e((string) ($pay['method'] ?? '—')) . ' · ' . $this->e((string) ($pay['flow'] ?? '—')),
            'Rental: ' . $this->e(PricingService::formatMoney((int) ($pay['rental_cents'] ?? 0))) . ' · ' . $this->e((string) ($pay['rental_state'] ?? '—')),
            'Deposit: ' . $this->e(PricingService::formatMoney((int) ($pay['deposit_cents'] ?? 0))) . ' · ' . $this->e((string) ($pay['deposit_state'] ?? '—')),
            '',
            '<b>Order</b>',
            'Lifecycle: ' . $this->e($this->lifecycleLabel($lifecycle)),
            'Fulfillment: ' . $this->e($this->fulfillmentLabel($fulfillment)),
        ];

        $equipment = trim((string) ($booking['equipment_name'] ?? ''));
        $lines[] = 'Unit: ' . $this->e($equipment !== '' ? $equipment : 'Not assigned');

        if (($booking['fulfillment_type'] ?? '') === 'pickup_appointment') {
            $lines[] = 'Appointment: ' . $this->e($this->appointmentLabel((string) ($booking['appointment_status'] ?? '')));
        }

        if ($attention !== []) {
            $lines[] = '';
            $lines[] = '<b>Attention</b>';
            foreach (array_slice($attention, 0, 4) as $item) {
                $lines[] = '• ' . $this->e((string) ($item['label'] ?? ''));
            }
        }

        if ($triggerEvent !== null && trim($triggerEvent) !== '') {
            $lines[] = '';
            $lines[] = '<i>Updated: ' . $this->e(str_replace('_', ' ', trim($triggerEvent))) . '</i>';
        }

        $lines[] = '<i>Synced ' . $this->e(gmdate('Y-m-d H:i') . ' UTC') . '</i>';
        $lines[] = '<a href="' . $this->e($this->adminBookingUrl($bookingId)) . '">Open in admin</a>';

        return implode("\n", $lines);
    }

    private function adminBookingUrl(int $bookingId): string
    {
        return rtrim((string) config('url'), '/') . '/admin/bookings/view?booking_id=' . $bookingId;
    }

    /** @return array{icon: string, label: string} */
    private function statusBadge(string $lifecycle, string $payment): array
    {
        if ($payment === 'payment_deposit_scheduled_failed') {
            return ['icon' => '🔴', 'label' => 'Deposit failed'];
        }

        return match ($lifecycle) {
            'booking_pending_payment' => ['icon' => '⏳', 'label' => 'Awaiting payment'],
            'booking_confirmed', 'booking_active' => ['icon' => '🟢', 'label' => 'In progress'],
            'booking_late' => ['icon' => '🟠', 'label' => 'Late return'],
            'booking_cancellation_pending' => ['icon' => '⚠️', 'label' => 'Cancellation pending'],
            'booking_cancelled' => ['icon' => '⭕', 'label' => 'Cancelled'],
            'booking_closed' => ['icon' => '✅', 'label' => 'Complete'],
            default => ['icon' => '⚪', 'label' => 'Unknown'],
        };
    }

    private function fulfillmentLabel(string $value): string
    {
        return match ($value) {
            'pickup', 'store_pickup' => 'Store pickup',
            'pickup_appointment', 'home_appointment' => 'Home appointment',
            'mail_ship' => 'Mail ship',
            'city_delivery' => 'Local delivery',
            'fulfillment_pending' => 'Awaiting prep',
            'fulfillment_staged' => 'Staged',
            'fulfillment_with_customer' => 'With customer',
            'shipping_received' => 'Mail received by customer',
            'fulfillment_return_received' => 'Return received',
            'fulfillment_return_confirmed' => 'QC passed',
            'fulfillment_return_failed' => 'QC failed',
            default => str_replace('_', ' ', $value),
        };
    }

    private function lifecycleLabel(string $value): string
    {
        return match ($value) {
            'booking_pending_payment' => 'Awaiting payment',
            'booking_confirmed' => 'Confirmed',
            'booking_active' => 'Active',
            'booking_late' => 'Late return',
            'booking_cancellation_pending' => 'Cancellation pending',
            'booking_cancelled' => 'Cancelled',
            'booking_closed' => 'Completed',
            default => str_replace('_', ' ', $value),
        };
    }

    private function appointmentLabel(string $value): string
    {
        return match ($value) {
            'appointment_awaiting_admin' => 'Awaiting admin',
            'appointment_proposed' => 'Window proposed',
            'appointment_confirmed' => 'Confirmed',
            'appointment_na' => 'N/A',
            default => str_replace('_', ' ', $value),
        };
    }

    /** @return ?array<string, mixed> */
    private function findStoredMessage(int $bookingId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT booking_id, chat_id, message_id, updated_at FROM booking_telegram_messages WHERE booking_id = :booking_id LIMIT 1'
        );
        $stmt->execute(['booking_id' => $bookingId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    private function storeMessage(int $bookingId, string $chatId, int $messageId): void
    {
        $now = now_utc();
        $this->db->prepare(
            'INSERT INTO booking_telegram_messages (booking_id, chat_id, message_id, updated_at)
             VALUES (:booking_id, :chat_id, :message_id, :updated_at)
             ON CONFLICT(booking_id) DO UPDATE SET
                chat_id = excluded.chat_id,
                message_id = excluded.message_id,
                updated_at = excluded.updated_at'
        )->execute([
            'booking_id' => $bookingId,
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'updated_at' => $now,
        ]);
    }

    private function touchStoredMessage(int $bookingId): void
    {
        $this->db->prepare('UPDATE booking_telegram_messages SET updated_at = :updated_at WHERE booking_id = :booking_id')
            ->execute(['updated_at' => now_utc(), 'booking_id' => $bookingId]);
    }

    private function shouldSendFreshMessage(string $error): bool
    {
        $error = strtolower($error);

        return str_contains($error, 'message to edit not found')
            || str_contains($error, 'message can\'t be edited')
            || str_contains($error, 'chat not found');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

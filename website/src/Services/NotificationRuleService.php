<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class NotificationRuleService
{
    private readonly PDO $db;

    /** @var array<string, array{label: string, category: string, sort: int, customer: bool, admin_email: bool, telegram: bool}> */
    private const DEFAULTS = [
        'booking_created' => ['label' => 'Booking created (unpaid)', 'category' => 'booking', 'sort' => 10, 'customer' => false, 'admin_email' => true, 'telegram' => false],
        'booking_admin_created' => ['label' => 'Admin created booking', 'category' => 'booking', 'sort' => 20, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'booking_cancellation_requested' => ['label' => 'Customer requested cancellation', 'category' => 'booking', 'sort' => 30, 'customer' => true, 'admin_email' => true, 'telegram' => true],
        'booking_cancellation_rejected' => ['label' => 'Cancellation request declined', 'category' => 'booking', 'sort' => 40, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'booking_cancelled_by_customer' => ['label' => 'Customer cancelled booking', 'category' => 'booking', 'sort' => 50, 'customer' => true, 'admin_email' => true, 'telegram' => true],
        'booking_cancelled_by_admin' => ['label' => 'Admin cancelled booking', 'category' => 'booking', 'sort' => 60, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'booking_closed' => ['label' => 'Booking completed (QC passed)', 'category' => 'booking', 'sort' => 70, 'customer' => true, 'admin_email' => false, 'telegram' => false],

        'payment_checkout_failed' => ['label' => 'Square checkout failed', 'category' => 'payment', 'sort' => 10, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'payment_etransfer_sent' => ['label' => 'e-Transfer marked sent', 'category' => 'payment', 'sort' => 20, 'customer' => false, 'admin_email' => true, 'telegram' => true],
        'payment_etransfer_partial_confirmed' => ['label' => 'e-Transfer partial confirm', 'category' => 'payment', 'sort' => 30, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'payment_etransfer_confirmed' => ['label' => 'e-Transfer confirmed', 'category' => 'payment', 'sort' => 40, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'payment_square_rental_captured' => ['label' => 'Square short rental captured', 'category' => 'payment', 'sort' => 50, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'payment_square_full_captured' => ['label' => 'Square long rental captured', 'category' => 'payment', 'sort' => 60, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'payment_deposit_scheduled_processed' => ['label' => 'Deposit authorized — ready for pickup', 'category' => 'payment', 'sort' => 70, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'payment_deposit_scheduled_failed' => ['label' => 'Deposit authorization failed', 'category' => 'payment', 'sort' => 80, 'customer' => true, 'admin_email' => true, 'telegram' => true],
        'payment_refunded' => ['label' => 'Payment refunded', 'category' => 'payment', 'sort' => 90, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'payment_late_fee_charged' => ['label' => 'Late fee charged', 'category' => 'payment', 'sort' => 100, 'customer' => true, 'admin_email' => true, 'telegram' => false],

        'fulfillment_with_customer' => ['label' => 'Unit picked up by customer', 'category' => 'fulfillment', 'sort' => 10, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'fulfillment_shipped' => ['label' => 'Shipment dispatched', 'category' => 'fulfillment', 'sort' => 20, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'fulfillment_return_received' => ['label' => 'Return received', 'category' => 'fulfillment', 'sort' => 30, 'customer' => false, 'admin_email' => true, 'telegram' => false],
        'fulfillment_return_confirmed' => ['label' => 'QC passed', 'category' => 'fulfillment', 'sort' => 40, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'fulfillment_return_failed' => ['label' => 'QC failed / damage', 'category' => 'fulfillment', 'sort' => 50, 'customer' => true, 'admin_email' => true, 'telegram' => true],

        'appointment_awaiting_admin' => ['label' => 'Pickup appointment awaiting admin', 'category' => 'appointment', 'sort' => 10, 'customer' => false, 'admin_email' => true, 'telegram' => true],
        'appointment_proposed' => ['label' => 'Pickup window proposed', 'category' => 'appointment', 'sort' => 20, 'customer' => true, 'admin_email' => false, 'telegram' => false],
        'appointment_confirmed' => ['label' => 'Pickup window confirmed', 'category' => 'appointment', 'sort' => 30, 'customer' => true, 'admin_email' => false, 'telegram' => false],

        'long_term_request_created' => ['label' => 'Long-term quote request submitted', 'category' => 'requests', 'sort' => 10, 'customer' => true, 'admin_email' => true, 'telegram' => true],
    ];

    /** @var list<string> */
    private const RETIRED_KEYS = [
        'fulfillment_ready',
    ];

    /** @var array<string, array{subject: string, body: string}> */
    private const DEFAULT_TEMPLATES = [
        'booking_created' => [
            'subject' => 'New booking {reference}',
            'body' => 'Booking {reference} was created and is awaiting payment.',
        ],
        'booking_admin_created' => [
            'subject' => 'Your RoamMax booking in {location_name}',
            'body' => 'Complete payment to confirm your reservation for {start_date} to {end_date}.',
        ],
        'booking_cancelled_by_customer' => [
            'subject' => 'Booking {reference} cancelled by customer',
            'body' => 'Customer cancelled booking {reference}. Reason: {reason}',
        ],
        'booking_cancelled_by_admin' => [
            'subject' => 'Booking {reference} cancelled',
            'body' => 'Your booking {reference} was cancelled. {reason}',
        ],
        'booking_cancellation_requested' => [
            'subject' => 'Cancellation requested — {reference}',
            'body' => 'Customer requested to cancel {reference}. Estimated refund {refund} (fee {fee}).',
        ],
        'booking_cancellation_rejected' => [
            'subject' => 'Cancellation declined — {reference}',
            'body' => 'Your cancellation request for {reference} was declined. {reason}',
        ],
        'booking_closed' => [
            'subject' => 'Booking {reference} complete',
            'body' => 'Your RoamMax rental {reference} is complete. Thank you for renting with us.',
        ],
        'payment_checkout_failed' => [
            'subject' => 'Payment failed — {reference}',
            'body' => 'We could not process your card payment for {reference}. {reason} Try again from your booking page.',
        ],
        'payment_etransfer_sent' => [
            'subject' => 'e-Transfer sent — {reference}',
            'body' => 'Customer marked e-Transfer sent for booking {reference}.',
        ],
        'payment_etransfer_confirmed' => [
            'subject' => 'Your booking in {location_name} is confirmed',
            'body' => 'Your RoamMax rental {reference} is confirmed for {start_date} to {end_date}.',
        ],
        'payment_etransfer_partial_confirmed' => [
            'subject' => 'Partial payment — {reference}',
            'body' => 'We received {amount} for {reference}. {balance_message}',
        ],
        'payment_square_rental_captured' => [
            'subject' => 'Your booking in {location_name} is confirmed',
            'body' => 'Your RoamMax rental {reference} is confirmed for {start_date} to {end_date}.',
        ],
        'payment_square_full_captured' => [
            'subject' => 'Your booking in {location_name} is confirmed',
            'body' => 'Your RoamMax rental {reference} is confirmed for {start_date} to {end_date}.',
        ],
        'payment_deposit_scheduled_processed' => [
            'subject' => 'Ready for pickup — {location_name}',
            'body' => 'Deposit for {reference} is authorized. Your booking is ready.',
        ],
        'payment_deposit_scheduled_failed' => [
            'subject' => 'Deposit failed — {reference}',
            'body' => 'We could not authorize the deposit for {reference}. Update your card: {action_url}',
        ],
        'payment_refunded' => [
            'subject' => 'Refund — {reference}',
            'body' => 'A refund was processed for booking {reference}.',
        ],
        'payment_late_fee_charged' => [
            'subject' => 'Late fee — {reference}',
            'body' => 'A late fee of {amount} was applied to booking {reference}.',
        ],
        'fulfillment_with_customer' => [
            'subject' => 'Pickup confirmed — {reference}',
            'body' => 'Your RoamMax unit for {reference} is now with you. Enjoy your rental through {end_date}.',
        ],
        'fulfillment_shipped' => [
            'subject' => 'Shipment dispatched — {reference}',
            'body' => 'Your RoamMax unit for {reference} has shipped. Track delivery to your address.',
        ],
        'fulfillment_return_received' => [
            'subject' => 'Return received — {reference}',
            'body' => 'Equipment for {reference} was returned and is awaiting QC.',
        ],
        'fulfillment_return_confirmed' => [
            'subject' => 'Return approved — {reference}',
            'body' => 'Equipment for {reference} passed inspection. Your deposit will be released.',
        ],
        'fulfillment_return_failed' => [
            'subject' => 'Return inspection — {reference}',
            'body' => 'Equipment returned for {reference} did not pass inspection. We will contact you regarding any charges.',
        ],
        'appointment_awaiting_admin' => [
            'subject' => 'Pickup appointment — {reference}',
            'body' => 'Booking {reference} needs a pickup appointment scheduled.',
        ],
        'appointment_proposed' => [
            'subject' => 'Pickup window proposed — {reference}',
            'body' => 'We proposed a pickup window for {reference}. Review and confirm in your account.',
        ],
        'appointment_confirmed' => [
            'subject' => 'Pickup confirmed — {reference}',
            'body' => 'Your pickup window for {reference} is confirmed.',
        ],
        'long_term_request_created' => [
            'subject' => 'Long-term request #{request_id}',
            'body' => '{customer_name} requested a {day_count}-day rental ({start_date} to {end_date}) at {location_name} via {fulfillment_type}.{notes_suffix}',
        ],
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    public function seedDefaults(): void
    {
        $now = now_utc();
        $ruleStmt = $this->db->prepare(
            'INSERT OR IGNORE INTO notification_rules (
                event_key, label, notify_customer_email, notify_admin_email, notify_admin_telegram, updated_at
             ) VALUES (
                :event_key, :label, :notify_customer_email, :notify_admin_email, :notify_admin_telegram, :updated_at
             )'
        );
        foreach (self::DEFAULTS as $key => $def) {
            $ruleStmt->execute([
                'event_key' => $key,
                'label' => $def['label'],
                'notify_customer_email' => $def['customer'] ? 1 : 0,
                'notify_admin_email' => $def['admin_email'] ? 1 : 0,
                'notify_admin_telegram' => $def['telegram'] ? 1 : 0,
                'updated_at' => $now,
            ]);
        }

        $tplStmt = $this->db->prepare(
            'INSERT OR IGNORE INTO notification_templates (event_key, channel, subject, body, updated_at)
             VALUES (:event_key, :channel, :subject, :body, :updated_at)'
        );
        foreach (self::DEFAULT_TEMPLATES as $key => $tpl) {
            $tplStmt->execute([
                'event_key' => $key,
                'channel' => 'email',
                'subject' => $tpl['subject'],
                'body' => $tpl['body'],
                'updated_at' => $now,
            ]);
        }
    }

    public function syncCatalog(): void
    {
        $this->seedDefaults();
        $this->syncEmailTemplates();
        $this->syncLabels();
        $this->syncTelegramFlags();
        $this->pruneRetiredKeys();
    }

    private function syncTelegramFlags(): void
    {
        $now = now_utc();
        $stmt = $this->db->prepare(
            'UPDATE notification_rules SET notify_admin_telegram = :notify_admin_telegram, updated_at = :updated_at WHERE event_key = :event_key'
        );

        foreach (self::DEFAULTS as $key => $def) {
            $stmt->execute([
                'event_key' => $key,
                'notify_admin_telegram' => $def['telegram'] ? 1 : 0,
                'updated_at' => $now,
            ]);
        }
    }

    private function syncEmailTemplates(): void
    {
        $now = now_utc();
        $stmt = $this->db->prepare(
            'UPDATE notification_templates
             SET subject = :subject, body = :body, updated_at = :updated_at
             WHERE event_key = :event_key AND channel = :channel'
        );
        foreach (self::DEFAULT_TEMPLATES as $key => $tpl) {
            $stmt->execute([
                'event_key' => $key,
                'channel' => 'email',
                'subject' => $tpl['subject'],
                'body' => $tpl['body'],
                'updated_at' => $now,
            ]);
        }
    }

    private function syncLabels(): void
    {
        $now = now_utc();
        $stmt = $this->db->prepare('UPDATE notification_rules SET label = :label, updated_at = :updated_at WHERE event_key = :event_key');
        foreach (self::DEFAULTS as $key => $def) {
            $stmt->execute([
                'event_key' => $key,
                'label' => $def['label'],
                'updated_at' => $now,
            ]);
        }
    }

    private function pruneRetiredKeys(): void
    {
        if (self::RETIRED_KEYS === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count(self::RETIRED_KEYS), '?'));
        $this->db->prepare("DELETE FROM notification_rules WHERE event_key IN ({$placeholders})")
            ->execute(self::RETIRED_KEYS);
        $this->db->prepare("DELETE FROM notification_templates WHERE event_key IN ({$placeholders})")
            ->execute(self::RETIRED_KEYS);
    }

    /** @return array<string, string> */
    public static function categoryLabels(): array
    {
        return [
            'booking' => 'Booking lifecycle',
            'payment' => 'Payments',
            'fulfillment' => 'Fulfillment & returns',
            'appointment' => 'Pickup appointments',
            'requests' => 'Quote requests',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listRules(): array
    {
        $rows = $this->db->query('SELECT * FROM notification_rules')->fetchAll();

        usort($rows, function (array $a, array $b): int {
            $keyA = (string) $a['event_key'];
            $keyB = (string) $b['event_key'];
            $defA = self::DEFAULTS[$keyA] ?? ['category' => 'zzz', 'sort' => 999];
            $defB = self::DEFAULTS[$keyB] ?? ['category' => 'zzz', 'sort' => 999];
            $catCmp = strcmp($defA['category'], $defB['category']);

            return $catCmp !== 0 ? $catCmp : ($defA['sort'] <=> $defB['sort']);
        });

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset(self::DEFAULTS[(string) $row['event_key']]),
        ));
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function listRuleGroups(): array
    {
        $groups = [];
        foreach ($this->listRules() as $rule) {
            $key = (string) $rule['event_key'];
            $category = self::DEFAULTS[$key]['category'];
            $groups[$category][] = $rule;
        }

        $ordered = [];
        foreach (self::categoryLabels() as $category => $_label) {
            if (!empty($groups[$category])) {
                $ordered[$category] = $groups[$category];
            }
        }

        return $ordered;
    }

    /** @param array<string, mixed> $post */
    public function saveRules(array $post): void
    {
        $now = now_utc();
        $stmt = $this->db->prepare(
            'UPDATE notification_rules SET
                notify_customer_email = :notify_customer_email,
                notify_admin_email = :notify_admin_email,
                updated_at = :updated_at
             WHERE event_key = :event_key'
        );

        foreach (self::DEFAULTS as $key => $_) {
            $stmt->execute([
                'event_key' => $key,
                'notify_customer_email' => !empty($post['customer'][$key]) ? 1 : 0,
                'notify_admin_email' => !empty($post['admin_email'][$key]) ? 1 : 0,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return ?array<string, mixed> */
    public function ruleFor(string $eventKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM notification_rules WHERE event_key = :event_key LIMIT 1');
        $stmt->execute(['event_key' => $eventKey]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @param array<string, mixed> $booking @param array<string, mixed> $context */
    public function render(string $eventKey, array $booking, array $context = []): array
    {
        $stmt = $this->db->prepare(
            'SELECT subject, body FROM notification_templates WHERE event_key = :event_key AND channel = :channel LIMIT 1'
        );
        $stmt->execute(['event_key' => $eventKey, 'channel' => 'email']);
        $row = $stmt->fetch();
        $subject = (string) ($row['subject'] ?? self::DEFAULT_TEMPLATES[$eventKey]['subject'] ?? 'RoamMax notification');
        $body = (string) ($row['body'] ?? self::DEFAULT_TEMPLATES[$eventKey]['body'] ?? '');

        $vars = array_merge([
            'reference' => booking_reference($booking),
            'customer_name' => (string) ($booking['customer_name'] ?? ''),
            'start_date' => (string) ($booking['start_date'] ?? ''),
            'end_date' => (string) ($booking['end_date'] ?? ''),
            'amount' => '',
            'reason' => '',
            'balance_message' => '',
            'action_url' => '',
            'request_id' => '',
            'day_count' => '',
            'location_name' => (string) ($booking['location_name'] ?? ''),
            'fulfillment_type' => '',
            'notes_suffix' => '',
            'refund' => '',
            'fee' => '',
        ], $context);

        foreach ($vars as $key => $value) {
            $subject = str_replace('{' . $key . '}', (string) $value, $subject);
            $body = str_replace('{' . $key . '}', (string) $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }
}

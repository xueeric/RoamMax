<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class PricingConfigService
{
    private readonly PDO $db;

    /** @var array<string, string>|null */
    private static ?array $settingsCache = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->allSettings();
        if (!array_key_exists($key, $settings)) {
            return config($key, $default);
        }

        $value = $settings[$key];
        if (is_string($default)) {
            return $value;
        }
        if (is_int($default)) {
            return (int) $value;
        }
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return is_numeric($value) ? (int) $value : $value;
    }

    public function setSetting(string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO pricing_settings (key, value, updated_at) VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $value,
            'updated_at' => now_utc(),
        ]);
        self::$settingsCache = null;
    }

    /** @param array<string, string> $settings */
    public function saveSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->setSetting($key, (string) $value);
        }
    }

    /** @return array<string, string> */
    public function allSettings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $rows = $this->db->query('SELECT key, value FROM pricing_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = (string) $row['value'];
        }

        self::$settingsCache = $settings;

        return $settings;
    }

    /** @return list<array<string, mixed>> */
    public function listTiers(): array
    {
        return $this->db->query(
            'SELECT * FROM pricing_tiers ORDER BY sort_order ASC, min_days ASC'
        )->fetchAll();
    }

    /** @param array<string, mixed> $data */
    public function saveTier(array $data, ?int $tierId = null): int
    {
        $fields = [
            'label' => (string) ($data['label'] ?? ''),
            'min_days' => (int) ($data['min_days'] ?? 1),
            'max_days' => ($data['max_days'] ?? '') !== '' ? (int) $data['max_days'] : null,
            'rate_cents_per_day' => (int) round(((float) ($data['rate_per_day'] ?? 0)) * 100),
            'notes' => ($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null,
            'pricing_mode' => (string) ($data['pricing_mode'] ?? 'daily'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        if ($fields['pricing_mode'] === 'custom_contact') {
            $fields['rate_cents_per_day'] = 0;
        }

        if ($tierId === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO pricing_tiers (label, min_days, max_days, rate_cents_per_day, notes, pricing_mode, sort_order, is_active, created_at)
                 VALUES (:label, :min_days, :max_days, :rate_cents_per_day, :notes, :pricing_mode, :sort_order, :is_active, :created_at)'
            );
            $fields['created_at'] = now_utc();
            $stmt->execute($fields);

            return (int) $this->db->lastInsertId();
        }

        $fields['id'] = $tierId;
        $this->db->prepare(
            'UPDATE pricing_tiers SET label = :label, min_days = :min_days, max_days = :max_days,
             rate_cents_per_day = :rate_cents_per_day, notes = :notes, pricing_mode = :pricing_mode,
             sort_order = :sort_order, is_active = :is_active WHERE id = :id'
        )->execute($fields);

        return $tierId;
    }

    /** @return list<array<string, mixed>> */
    public function listSpecialRules(): array
    {
        return $this->db->query(
            'SELECT * FROM pricing_special_rules ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    }

    /** @param array<string, mixed> $data */
    public function saveSpecialRule(array $data, ?int $ruleId = null): int
    {
        $fields = [
            'rule_type' => (string) ($data['rule_type'] ?? 'weekday_flat'),
            'label' => (string) ($data['label'] ?? ''),
            'flat_rate_cents' => ($data['flat_rate'] ?? '') !== '' ? (int) round(((float) $data['flat_rate']) * 100) : null,
            'min_booking_days' => ($data['min_booking_days'] ?? '') !== '' ? (int) $data['min_booking_days'] : null,
            'start_weekday' => ($data['start_weekday'] ?? '') !== '' ? (int) $data['start_weekday'] : null,
            'end_weekday' => ($data['end_weekday'] ?? '') !== '' ? (int) $data['end_weekday'] : null,
            'exact_day_count' => ($data['exact_day_count'] ?? '') !== '' ? (int) $data['exact_day_count'] : null,
            'period_start' => ($data['period_start'] ?? '') !== '' ? (string) $data['period_start'] : null,
            'period_end' => ($data['period_end'] ?? '') !== '' ? (string) $data['period_end'] : null,
            'notes' => ($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        if ($ruleId === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO pricing_special_rules (
                    rule_type, label, flat_rate_cents, min_booking_days, start_weekday, end_weekday,
                    exact_day_count, period_start, period_end, notes, is_active, sort_order, created_at
                 ) VALUES (
                    :rule_type, :label, :flat_rate_cents, :min_booking_days, :start_weekday, :end_weekday,
                    :exact_day_count, :period_start, :period_end, :notes, :is_active, :sort_order, :created_at
                 )'
            );
            $fields['created_at'] = now_utc();
            $stmt->execute($fields);

            return (int) $this->db->lastInsertId();
        }

        $fields['id'] = $ruleId;
        $this->db->prepare(
            'UPDATE pricing_special_rules SET
                rule_type = :rule_type, label = :label, flat_rate_cents = :flat_rate_cents,
                min_booking_days = :min_booking_days, start_weekday = :start_weekday, end_weekday = :end_weekday,
                exact_day_count = :exact_day_count, period_start = :period_start, period_end = :period_end,
                notes = :notes, is_active = :is_active, sort_order = :sort_order
             WHERE id = :id'
        )->execute($fields);

        return $ruleId;
    }

    public function deleteSpecialRule(int $ruleId): void
    {
        $this->db->prepare('DELETE FROM pricing_special_rules WHERE id = :id')->execute(['id' => $ruleId]);
    }

    public static function clearCache(): void
    {
        self::$settingsCache = null;
    }

    /** @return list<string> */
    public static function weekdayLabels(): array
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    }
}

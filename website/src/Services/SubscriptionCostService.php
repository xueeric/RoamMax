<?php

declare(strict_types=1);

namespace Starlink\Services;

use DateTimeImmutable;
use PDO;
use Starlink\Database\Connection;

final class SubscriptionCostService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /** Lifetime subscription cost from plan history (prorated monthly). */
    public function subscriptionCostCents(int $equipmentId): int
    {
        return $this->accruedPlanCostCents($equipmentId);
    }

    /** Subscription cost that reduces this unit's profit (respects who pays the bill). */
    public function subscriptionChargeCents(array $equipment): int
    {
        $equipmentId = (int) ($equipment['id'] ?? 0);
        if ($equipmentId <= 0) {
            return 0;
        }

        if (($equipment['owner_type'] ?? '') === 'partner') {
            return $this->accruedPlanCostCentsForPayer($equipmentId, 'partner');
        }

        return $this->accruedPlanCostCents($equipmentId);
    }

    public function accruedPlanCostCentsForPayer(int $equipmentId, string $payer): int
    {
        $through = today_date();
        $periods = array_values(array_filter(
            $this->planPeriods($equipmentId),
            static fn (array $period): bool => ($period['payer'] ?? '') === $payer,
        ));
        if ($periods === []) {
            return 0;
        }

        $start = parse_date((string) $periods[0]['effective_from']);
        if ($start === null) {
            return 0;
        }

        $total = 0;
        $cursor = $start->modify('first day of this month');
        while ($cursor <= $through) {
            $total += $this->monthlyPlanCostCents($periods, $cursor, $cursor->modify('last day of this month'));
            $cursor = $cursor->modify('first day of next month');
        }

        return $total;
    }

    public function accruedPlanCostCents(int $equipmentId, ?DateTimeImmutable $through = null): int
    {
        $through ??= today_date();
        $periods = $this->planPeriods($equipmentId);
        if ($periods === []) {
            return 0;
        }

        $start = parse_date((string) $periods[0]['effective_from']);
        if ($start === null) {
            return 0;
        }

        $total = 0;
        $cursor = $start->modify('first day of this month');
        while ($cursor <= $through) {
            $monthStart = $cursor;
            $monthEnd = $cursor->modify('last day of this month');
            $total += $this->monthlyPlanCostCents($periods, $monthStart, $monthEnd);
            $cursor = $cursor->modify('first day of next month');
        }

        return $total;
    }

    /**
     * Prorated monthly bill when plans change mid-cycle.
     * Example: 100 GB for 15 days + 300 GB for 15 days in a 30-day month.
     */
    public function monthlyPlanCostCents(array $periods, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): int
    {
        $daysInMonth = (int) $monthStart->format('t');
        if ($daysInMonth <= 0) {
            return 0;
        }

        $total = 0;
        foreach ($periods as $period) {
            $from = parse_date((string) $period['effective_from']);
            $to = ($period['effective_to'] ?? '') !== '' ? parse_date((string) $period['effective_to']) : null;
            if ($from === null) {
                continue;
            }

            $segmentStart = $from > $monthStart ? $from : $monthStart;
            $segmentEnd = $to !== null && $to < $monthEnd ? $to : $monthEnd;
            if ($segmentStart > $segmentEnd) {
                continue;
            }

            $days = inclusive_day_count($segmentStart, $segmentEnd);
            $monthly = (int) $period['monthly_cents'];
            $total += (int) round($monthly * ($days / $daysInMonth));
        }

        return $total;
    }

    /** @return list<array<string, mixed>> */
    public function planPeriods(int $equipmentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ep.*, sp.label AS plan_label, sp.data_gb
             FROM equipment_plan_periods ep
             INNER JOIN starlink_plans sp ON sp.slug = ep.plan_slug
             WHERE ep.equipment_id = :equipment_id
             ORDER BY ep.effective_from ASC, ep.id ASC'
        );
        $stmt->execute(['equipment_id' => $equipmentId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function currentPlanPeriod(int $equipmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ep.*, sp.label AS plan_label, sp.data_gb
             FROM equipment_plan_periods ep
             INNER JOIN starlink_plans sp ON sp.slug = ep.plan_slug
             WHERE ep.equipment_id = :equipment_id AND ep.effective_to IS NULL
             ORDER BY ep.effective_from DESC, ep.id DESC
             LIMIT 1'
        );
        $stmt->execute(['equipment_id' => $equipmentId]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }
}

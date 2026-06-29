<?php

declare(strict_types=1);

namespace Starlink\Services;

use DateTimeImmutable;
use PDO;
use Starlink\Database\Connection;

final class PoolRevenueService
{
    private readonly PDO $db;

    /** @var array<string, list<int>> */
    private array $poolCache = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    public function allocatedRevenueForEquipment(int $equipmentId): int
    {
        return $this->allocatedAmountForEquipment($equipmentId, 'revenue');
    }

    public function allocatedRefundsForEquipment(int $equipmentId): int
    {
        return $this->allocatedAmountForEquipment($equipmentId, 'refund');
    }

    /** @return list<array<string, mixed>> */
    public function revenueByOwner(): array
    {
        $payments = $this->customerPoolPayments();
        /** @var array<string, array{owner_type: string, owner_name: string, revenue_cents: int}> $totals */
        $totals = [];

        foreach ($payments as $payment) {
            $poolIds = $this->poolUnitIdsAt((string) $payment['allocated_date']);
            $poolSize = count($poolIds);
            if ($poolSize === 0) {
                continue;
            }

            $amount = (int) $payment['amount_cents'];
            $type = (string) $payment['allocation_type'];
            foreach ($poolIds as $index => $unitId) {
                $share = $this->allocateShare($amount, $poolSize, $index);
                if ($share === 0) {
                    continue;
                }

                $owner = $this->ownerKeyForUnit($unitId);
                $key = $owner['key'];
                if (!isset($totals[$key])) {
                    $totals[$key] = [
                        'owner_type' => $owner['owner_type'],
                        'owner_name' => $owner['owner_name'],
                        'revenue_cents' => 0,
                    ];
                }
                if ($type === 'revenue') {
                    $totals[$key]['revenue_cents'] += $share;
                } else {
                    $totals[$key]['revenue_cents'] -= $share;
                }
            }
        }

        $rows = array_values($totals);
        usort($rows, static fn (array $a, array $b): int => ($b['revenue_cents'] <=> $a['revenue_cents']));

        return $rows;
    }

    private function allocatedAmountForEquipment(int $equipmentId, string $kind): int
    {
        $total = 0;
        foreach ($this->customerPoolPayments() as $payment) {
            if (($payment['allocation_type'] ?? '') !== $kind) {
                continue;
            }

            $poolIds = $this->poolUnitIdsAt((string) $payment['allocated_date']);
            $index = array_search($equipmentId, $poolIds, true);
            if ($index === false) {
                continue;
            }

            $total += $this->allocateShare((int) $payment['amount_cents'], count($poolIds), $index);
        }

        return $total;
    }

    /** @return list<array<string, mixed>> */
    private function customerPoolPayments(): array
    {
        $rows = $this->db->query(
            "SELECT p.id, p.amount_cents, p.type, p.created_at,
                    COALESCE(b.end_date, date(p.created_at)) AS allocated_date
             FROM payments p
             LEFT JOIN bookings b ON b.id = p.booking_id
             WHERE p.status = 'completed'
             AND (
                p.type IN ('rental', 'late_fee', 'checkout', 'addon', 'shipping')
                OR p.type = 'deposit_refund'
             )
             AND (
                p.booking_id IS NULL
                OR COALESCE(b.booking_kind, 'customer') = 'customer'
             )
             ORDER BY p.id ASC"
        )->fetchAll();

        $payments = [];
        foreach ($rows as $row) {
            $payments[] = [
                'amount_cents' => (int) $row['amount_cents'],
                'allocated_date' => (string) $row['allocated_date'],
                'allocation_type' => $row['type'] === 'deposit_refund' ? 'refund' : 'revenue',
            ];
        }

        return $payments;
    }

    /** @return list<int> */
    private function poolUnitIdsAt(string $date): array
    {
        if (isset($this->poolCache[$date])) {
            return $this->poolCache[$date];
        }

        $stmt = $this->db->prepare(
            "SELECT id FROM equipment
             WHERE equipment_type = 'starlink'
             AND status != 'retired'
             AND date(created_at) <= date(:as_of)
             ORDER BY id ASC"
        );
        $stmt->execute(['as_of' => $date]);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());
        $this->poolCache[$date] = $ids;

        return $ids;
    }

    private function allocateShare(int $amountCents, int $poolSize, int $unitIndex): int
    {
        if ($poolSize <= 0) {
            return 0;
        }

        $base = intdiv($amountCents, $poolSize);
        $remainder = $amountCents % $poolSize;

        return $base + ($unitIndex < $remainder ? 1 : 0);
    }

    /** @return array{key: string, owner_type: string, owner_name: string} */
    private function ownerKeyForUnit(int $equipmentId): array
    {
        static $cache = [];
        if (isset($cache[$equipmentId])) {
            return $cache[$equipmentId];
        }

        $stmt = $this->db->prepare(
            'SELECT e.owner_type, COALESCE(p.name, \'Admin\') AS owner_name
             FROM equipment e
             LEFT JOIN partners p ON p.id = e.partner_id
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $equipmentId]);
        $row = $stmt->fetch();
        if (!$row) {
            return $cache[$equipmentId] = ['key' => 'unknown', 'owner_type' => 'admin', 'owner_name' => 'Admin'];
        }

        $ownerType = (string) $row['owner_type'];
        $ownerName = (string) $row['owner_name'];
        $key = $ownerType . ':' . $ownerName;

        return $cache[$equipmentId] = ['key' => $key, 'owner_type' => $ownerType, 'owner_name' => $ownerName];
    }
}

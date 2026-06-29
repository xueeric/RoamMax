<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class PayoffService
{
    /** @var list<string> */
    private const RENTAL_REVENUE_TYPES = [
        'checkout',
        'rental',
        'late_fee',
        'cancellation_fee',
        'shipping',
        'addon',
        'damage_charge',
    ];

    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function equipmentReports(): array
    {
        $equipment = $this->db->query(
            "SELECT e.*, p.name AS partner_name
             FROM equipment e
             LEFT JOIN partners p ON p.id = e.partner_id
             WHERE e.equipment_type = 'starlink'
             ORDER BY e.rental_priority ASC, e.id ASC"
        )->fetchAll();

        return array_map(fn (array $unit): array => $this->reportForEquipment((int) $unit['id'], $unit), $equipment);
    }

    /** @return array<string, mixed> */
    public function financialSummary(): array
    {
        $revenueTypes = $this->rentalRevenueTypesSql();
        $row = $this->db->query(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN pay.status = 'completed' AND pay.type IN ({$revenueTypes})
                    THEN pay.amount_cents ELSE 0 END), 0) AS rental_collected_cents,
                COALESCE(SUM(CASE
                    WHEN pay.status = 'refunded' AND pay.type != 'deposit_refund'
                    THEN pay.amount_cents ELSE 0 END), 0) AS rental_refunded_cents,
                COALESCE(SUM(CASE
                    WHEN pay.status = 'completed' AND pay.type = 'deposit'
                    THEN pay.amount_cents ELSE 0 END), 0) AS deposits_collected_cents
             FROM payments pay"
        )->fetch() ?: [];

        $collected = (int) ($row['rental_collected_cents'] ?? 0);
        $refunded = (int) ($row['rental_refunded_cents'] ?? 0);

        $reports = $this->equipmentReports();
        $totalProfit = 0;
        $totalRemaining = 0;
        foreach ($reports as $report) {
            $totalProfit += (int) ($report['profit_cents'] ?? 0);
            $totalRemaining += (int) ($report['remaining_cents'] ?? 0);
        }

        $depositsHeld = (int) $this->db->query(
            "SELECT COALESCE(SUM(deposit_cents), 0)
             FROM bookings
             WHERE payment_status = 'payment_deposit_scheduled_processed'
             AND booking_status IN ('booking_confirmed', 'booking_active', 'booking_late')"
        )->fetchColumn();

        return [
            'rental_collected_cents' => $collected,
            'rental_refunded_cents' => $refunded,
            'net_rental_cents' => $collected - $refunded,
            'deposits_held_cents' => $depositsHeld,
            'deposits_collected_cents' => (int) ($row['deposits_collected_cents'] ?? 0),
            'total_profit_cents' => $totalProfit,
            'total_remaining_cents' => $totalRemaining,
        ];
    }

    /** @return array<string, mixed> */
    public function reportForEquipment(int $equipmentId, ?array $equipment = null): array
    {
        if ($equipment === null) {
            $stmt = $this->db->prepare(
                'SELECT e.*, p.name AS partner_name FROM equipment e LEFT JOIN partners p ON p.id = e.partner_id WHERE e.id = :id'
            );
            $stmt->execute(['id' => $equipmentId]);
            $equipment = $stmt->fetch();
            if (!$equipment) {
                return [];
            }
        }

        $capex = (int) $equipment['purchase_cost_cents'];
        $costStmt = $this->db->prepare(
            'SELECT type, SUM(amount_cents) AS total FROM equipment_costs WHERE equipment_id = :id GROUP BY type'
        );
        $costStmt->execute(['id' => $equipmentId]);
        $costsByType = [];
        while ($row = $costStmt->fetch()) {
            $costsByType[$row['type']] = (int) $row['total'];
            if (in_array($row['type'], ['purchase', 'accessory'], true)) {
                $capex += (int) $row['total'];
            }
        }

        $ongoing = (int) ($costsByType['repair'] ?? 0)
            + (int) ($costsByType['other'] ?? 0);

        $subscriptionAccrued = (new SubscriptionCostService($this->db))->subscriptionCostCents($equipmentId);
        $subscriptionCharge = (new SubscriptionCostService($this->db))->subscriptionChargeCents($equipment);
        $ongoing += $subscriptionCharge;

        $paymentTotals = $this->paymentTotalsForEquipment($equipmentId);
        $revenue = $paymentTotals['revenue_cents'];
        $refunds = $paymentTotals['rental_refund_cents'];
        $netRevenue = $revenue - $refunds;

        $profit = $netRevenue - $ongoing;
        $payoffPct = $capex > 0 ? round(($profit / $capex) * 100, 1) : 0.0;
        $remaining = max(0, $capex - $profit);

        $currentPlan = (new SubscriptionCostService($this->db))->currentPlanPeriod($equipmentId);

        return [
            'equipment' => $equipment,
            'capex_cents' => $capex,
            'ongoing_cents' => $ongoing,
            'subscription_cents' => $subscriptionAccrued,
            'subscription_charge_cents' => $subscriptionCharge,
            'revenue_cents' => $revenue,
            'refund_cents' => $refunds,
            'net_revenue_cents' => $netRevenue,
            'profit_cents' => $profit,
            'payoff_pct' => $payoffPct,
            'remaining_cents' => $remaining,
            'revenue_model' => 'assigned_unit',
            'current_plan' => $currentPlan,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function revenueByOwner(): array
    {
        $revenueTypes = $this->rentalRevenueTypesSql();

        return $this->db->query(
            "SELECT e.owner_type,
                    COALESCE(p.name, 'Admin') AS owner_name,
                    COALESCE(SUM(CASE
                        WHEN pay.status = 'completed' AND pay.type IN ({$revenueTypes})
                        THEN pay.amount_cents ELSE 0 END), 0) AS revenue_cents,
                    COALESCE(SUM(CASE
                        WHEN pay.status = 'refunded' AND pay.type != 'deposit_refund'
                        THEN pay.amount_cents ELSE 0 END), 0) AS refund_cents
             FROM payments pay
             INNER JOIN equipment e ON e.id = pay.equipment_id
             LEFT JOIN partners p ON p.id = e.partner_id
             GROUP BY e.owner_type, owner_name
             ORDER BY revenue_cents DESC"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function paymentLedger(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, b.id AS booking_ref, b.reference_code AS booking_reference, e.nickname AS equipment_name
             FROM payments p
             LEFT JOIN bookings b ON b.id = p.booking_id
             LEFT JOIN equipment e ON e.id = p.equipment_id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function partnerReports(int $partnerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM equipment WHERE partner_id = :partner_id AND equipment_type = 'starlink' ORDER BY id ASC"
        );
        $stmt->execute(['partner_id' => $partnerId]);

        $reports = [];
        while ($row = $stmt->fetch()) {
            $reports[] = $this->reportForEquipment((int) $row['id'], $row);
        }

        return $reports;
    }

    /** @return array{revenue_cents: int, rental_refund_cents: int} */
    private function paymentTotalsForEquipment(int $equipmentId): array
    {
        $revenueTypes = $this->rentalRevenueTypesSql();
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN status = 'completed' AND type IN ({$revenueTypes})
                    THEN amount_cents ELSE 0 END), 0) AS revenue_cents,
                COALESCE(SUM(CASE
                    WHEN status = 'refunded' AND type != 'deposit_refund'
                    THEN amount_cents ELSE 0 END), 0) AS rental_refund_cents
             FROM payments
             WHERE equipment_id = :equipment_id"
        );
        $stmt->execute(['equipment_id' => $equipmentId]);
        $row = $stmt->fetch() ?: [];

        return [
            'revenue_cents' => (int) ($row['revenue_cents'] ?? 0),
            'rental_refund_cents' => (int) ($row['rental_refund_cents'] ?? 0),
        ];
    }

    private function rentalRevenueTypesSql(): string
    {
        $quoted = [];
        foreach (self::RENTAL_REVENUE_TYPES as $type) {
            $quoted[] = $this->db->quote($type);
        }

        return implode(',', $quoted);
    }
}

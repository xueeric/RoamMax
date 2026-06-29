<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class StarlinkPlanService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /** @return list<array<string, mixed>> */
    public function activePlans(): array
    {
        return $this->db->query(
            'SELECT slug, label, monthly_cents, data_gb
             FROM starlink_plans
             WHERE is_active = 1
             ORDER BY sort_order ASC, monthly_cents ASC'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findPlan(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM starlink_plans WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function changePlan(
        int $equipmentId,
        string $planSlug,
        string $payer,
        string $effectiveFrom,
        ?string $notes = null,
    ): void {
        $plan = $this->findPlan($planSlug);
        if ($plan === null) {
            throw new \InvalidArgumentException('Unknown Starlink plan.');
        }

        if (!in_array($payer, ['partner', 'admin'], true)) {
            throw new \InvalidArgumentException('Subscription payer must be partner or admin.');
        }

        $this->db->beginTransaction();
        try {
            $close = $this->db->prepare(
                'UPDATE equipment_plan_periods
                 SET effective_to = date(:effective_to, \'-1 day\')
                 WHERE equipment_id = :equipment_id AND effective_to IS NULL'
            );
            $close->execute([
                'equipment_id' => $equipmentId,
                'effective_to' => $effectiveFrom,
            ]);

            $insert = $this->db->prepare(
                'INSERT INTO equipment_plan_periods (
                    equipment_id, plan_slug, monthly_cents, payer, effective_from, notes, created_at
                 ) VALUES (
                    :equipment_id, :plan_slug, :monthly_cents, :payer, :effective_from, :notes, :created_at
                 )'
            );
            $insert->execute([
                'equipment_id' => $equipmentId,
                'plan_slug' => $planSlug,
                'monthly_cents' => (int) $plan['monthly_cents'],
                'payer' => $payer,
                'effective_from' => $effectiveFrom,
                'notes' => $notes,
                'created_at' => now_utc(),
            ]);

            $this->db->prepare(
                'UPDATE equipment SET data_plan = :plan_slug WHERE id = :id'
            )->execute(['plan_slug' => $planSlug, 'id' => $equipmentId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function ensureInitialPlan(int $equipmentId, string $ownerType): void
    {
        $existing = (new SubscriptionCostService($this->db))->planPeriods($equipmentId);
        if ($existing !== []) {
            return;
        }

        $this->changePlan(
            $equipmentId,
            'roam_100gb',
            $ownerType === 'partner' ? 'partner' : 'admin',
            today_date()->format('Y-m-d'),
            'Initial plan',
        );
    }
}

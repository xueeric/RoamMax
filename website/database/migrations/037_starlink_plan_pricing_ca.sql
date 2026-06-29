-- Canadian Starlink Roam plan pricing (CAD / month)
UPDATE starlink_plans SET label = 'Roam 100 GB', monthly_cents = 7500 WHERE slug = 'roam_100gb';
UPDATE starlink_plans SET label = 'Roam 300 GB', monthly_cents = 11000 WHERE slug = 'roam_300gb';
UPDATE starlink_plans SET label = 'Roam Unlimited', monthly_cents = 20000 WHERE slug = 'roam_unlimited';

-- Keep open plan-period cost snapshots aligned with catalog
UPDATE equipment_plan_periods
SET monthly_cents = (
    SELECT monthly_cents FROM starlink_plans WHERE slug = equipment_plan_periods.plan_slug
)
WHERE effective_to IS NULL;

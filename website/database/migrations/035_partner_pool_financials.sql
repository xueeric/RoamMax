-- Starlink plan catalog (monthly subscription tiers)
CREATE TABLE IF NOT EXISTS starlink_plans (
    slug TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    monthly_cents INTEGER NOT NULL,
    data_gb INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1
);

INSERT INTO starlink_plans (slug, label, monthly_cents, data_gb, sort_order) VALUES
    ('roam_100gb', 'Roam 100 GB', 5000, 100, 10),
    ('roam_300gb', 'Roam 300 GB', 9000, 300, 20),
    ('roam_unlimited', 'Roam Unlimited', 15000, 0, 30);

-- Encrypted Starlink portal password + who pays the monthly bill
ALTER TABLE equipment ADD COLUMN starlink_account_password_enc TEXT;
ALTER TABLE equipment ADD COLUMN subscription_payer TEXT NOT NULL DEFAULT 'admin';

-- Plan history with proration support (effective_to NULL = current plan)
CREATE TABLE IF NOT EXISTS equipment_plan_periods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    equipment_id INTEGER NOT NULL,
    plan_slug TEXT NOT NULL,
    monthly_cents INTEGER NOT NULL,
    payer TEXT NOT NULL CHECK (payer IN ('partner', 'admin')),
    effective_from TEXT NOT NULL,
    effective_to TEXT,
    notes TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_slug) REFERENCES starlink_plans(slug)
);

CREATE INDEX IF NOT EXISTS idx_equipment_plan_periods_equipment
    ON equipment_plan_periods (equipment_id, effective_from);

-- Backfill subscription payer from owner type
UPDATE equipment
SET subscription_payer = CASE owner_type WHEN 'partner' THEN 'partner' ELSE 'admin' END
WHERE equipment_type = 'starlink';

-- Seed initial plan periods from existing data_plan labels
INSERT INTO equipment_plan_periods (equipment_id, plan_slug, monthly_cents, payer, effective_from, created_at)
SELECT e.id,
       CASE COALESCE(NULLIF(TRIM(e.data_plan), ''), 'roam_100gb')
           WHEN 'roam_50gb' THEN 'roam_100gb'
           ELSE COALESCE(NULLIF(TRIM(e.data_plan), ''), 'roam_100gb')
       END,
       COALESCE(p.monthly_cents, 5000),
       CASE e.owner_type WHEN 'partner' THEN 'partner' ELSE 'admin' END,
       COALESCE(e.purchase_date, date('now')),
       datetime('now')
FROM equipment e
LEFT JOIN starlink_plans p ON p.slug = CASE COALESCE(NULLIF(TRIM(e.data_plan), ''), 'roam_100gb')
    WHEN 'roam_50gb' THEN 'roam_100gb'
    ELSE COALESCE(NULLIF(TRIM(e.data_plan), ''), 'roam_100gb')
END
WHERE e.equipment_type = 'starlink'
AND NOT EXISTS (
    SELECT 1 FROM equipment_plan_periods ep WHERE ep.equipment_id = e.id
);

UPDATE equipment
SET data_plan = 'roam_100gb'
WHERE equipment_type = 'starlink'
AND (data_plan IS NULL OR TRIM(data_plan) = '');

UPDATE equipment
SET data_plan = 'roam_100gb'
WHERE equipment_type = 'starlink'
AND data_plan = 'roam_50gb';

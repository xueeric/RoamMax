ALTER TABLE pricing_tiers ADD COLUMN label TEXT;
ALTER TABLE pricing_tiers ADD COLUMN notes TEXT;
ALTER TABLE pricing_tiers ADD COLUMN pricing_mode TEXT NOT NULL DEFAULT 'daily';

UPDATE pricing_tiers
SET label = '3 to 7 Days',
    notes = 'Minimum 3-day booking required',
    pricing_mode = 'daily'
WHERE min_days = 3 AND max_days = 7;

UPDATE pricing_tiers
SET label = '7 to 30 Days',
    notes = 'Discount automatically applied',
    pricing_mode = 'daily'
WHERE min_days = 8 AND max_days = 30;

INSERT INTO pricing_tiers (min_days, max_days, rate_cents_per_day, sort_order, is_active, label, notes, pricing_mode, created_at)
SELECT 31, NULL, 0, 3, 1, 'Greater than 30 Days', 'Contact Owner directly for long-term commercial rates', 'custom_contact', datetime('now')
WHERE NOT EXISTS (SELECT 1 FROM pricing_tiers WHERE pricing_mode = 'custom_contact');

CREATE TABLE IF NOT EXISTS pricing_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

INSERT OR IGNORE INTO pricing_settings (key, value, updated_at) VALUES
    ('minimum_rental_days', '3', datetime('now')),
    ('max_self_serve_days', '30', datetime('now')),
    ('deposit_cents', '35000', datetime('now')),
    ('shipping_fee_cents', '15000', datetime('now')),
    ('long_term_contact_note', 'Contact Owner directly for long-term commercial rates', datetime('now'));

CREATE TABLE IF NOT EXISTS pricing_special_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rule_type TEXT NOT NULL CHECK (rule_type IN ('weekday_flat', 'period_minimum')),
    label TEXT NOT NULL,
    flat_rate_cents INTEGER,
    min_booking_days INTEGER,
    start_weekday INTEGER,
    end_weekday INTEGER,
    exact_day_count INTEGER,
    period_start TEXT,
    period_end TEXT,
    notes TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

INSERT INTO pricing_special_rules (
    rule_type, label, flat_rate_cents, start_weekday, end_weekday, exact_day_count, notes, is_active, sort_order, created_at
) VALUES (
    'weekday_flat',
    'Long weekend Fri–Mon',
    15000,
    5,
    1,
    4,
    'Friday pickup through Monday return — flat rate when demand is high.',
    1,
    1,
    datetime('now')
);

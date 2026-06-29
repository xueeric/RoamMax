CREATE TABLE pricing_tiers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    min_days INTEGER NOT NULL,
    max_days INTEGER,
    rate_cents_per_day INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

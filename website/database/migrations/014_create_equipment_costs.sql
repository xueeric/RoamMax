CREATE TABLE IF NOT EXISTS equipment_costs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    equipment_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    amount_cents INTEGER NOT NULL,
    description TEXT,
    incurred_date TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id)
);

CREATE INDEX IF NOT EXISTS idx_equipment_costs_equipment_id ON equipment_costs (equipment_id);

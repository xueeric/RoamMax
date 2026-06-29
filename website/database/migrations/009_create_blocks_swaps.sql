CREATE TABLE partner_swaps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    partner_id INTEGER NOT NULL,
    partner_equipment_id INTEGER NOT NULL,
    loaner_equipment_id INTEGER,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'approved', 'active', 'completed', 'denied')),
    notes TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (partner_id) REFERENCES partners(id),
    FOREIGN KEY (partner_equipment_id) REFERENCES equipment(id),
    FOREIGN KEY (loaner_equipment_id) REFERENCES equipment(id)
);

CREATE TABLE owner_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    equipment_id INTEGER NOT NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    reason TEXT NOT NULL CHECK (reason IN ('personal', 'maintenance', 'staging', 'other')),
    requested_by TEXT NOT NULL CHECK (requested_by IN ('admin', 'partner')),
    partner_swap_id INTEGER,
    notes TEXT,
    created_by_user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id),
    FOREIGN KEY (partner_swap_id) REFERENCES partner_swaps(id),
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
);

CREATE INDEX idx_blocks_equipment_dates ON owner_blocks(equipment_id, start_date, end_date);
CREATE INDEX idx_staging_equipment ON staging_events(equipment_id, staging_date);
CREATE INDEX idx_transfers_equipment ON transfers(equipment_id, transfer_date);

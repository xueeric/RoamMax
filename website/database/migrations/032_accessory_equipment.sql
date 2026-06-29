ALTER TABLE equipment ADD COLUMN equipment_type TEXT NOT NULL DEFAULT 'starlink';
ALTER TABLE equipment ADD COLUMN sku TEXT;
ALTER TABLE equipment ADD COLUMN rental_price_cents_per_day INTEGER;
ALTER TABLE equipment ADD COLUMN rental_price_cents_flat INTEGER;

CREATE INDEX IF NOT EXISTS idx_equipment_type ON equipment(equipment_type, status);

CREATE TABLE booking_add_ons_v2 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    equipment_id INTEGER,
    inventory_item_id INTEGER,
    quantity INTEGER NOT NULL DEFAULT 1,
    unit_price_cents INTEGER NOT NULL,
    line_total_cents INTEGER NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id),
    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
);

INSERT INTO booking_add_ons_v2 (id, booking_id, equipment_id, inventory_item_id, quantity, unit_price_cents, line_total_cents)
SELECT id, booking_id, NULL, inventory_item_id, quantity, unit_price_cents, line_total_cents FROM booking_add_ons;

DROP TABLE booking_add_ons;
ALTER TABLE booking_add_ons_v2 RENAME TO booking_add_ons;

CREATE INDEX IF NOT EXISTS idx_booking_add_ons_equipment ON booking_add_ons(equipment_id);

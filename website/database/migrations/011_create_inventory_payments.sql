CREATE TABLE inventory_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    location_id INTEGER NOT NULL,
    quantity_total INTEGER NOT NULL DEFAULT 0,
    quantity_available INTEGER NOT NULL DEFAULT 0,
    rental_price_cents_per_day INTEGER,
    rental_price_cents_flat INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id)
);

CREATE TABLE booking_add_ons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    inventory_item_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    unit_price_cents INTEGER NOT NULL,
    line_total_cents INTEGER NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
);

CREATE TABLE payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER,
    equipment_id INTEGER,
    type TEXT NOT NULL CHECK (type IN (
        'rental', 'deposit', 'deposit_refund', 'shipping', 'late_fee',
        'cancellation_fee', 'damage_charge', 'addon', 'checkout'
    )),
    amount_cents INTEGER NOT NULL,
    square_payment_id TEXT,
    square_order_id TEXT,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'failed', 'refunded')),
    notes TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (equipment_id) REFERENCES equipment(id)
);

CREATE INDEX idx_inventory_location ON inventory_items(location_id, is_active);

INSERT OR IGNORE INTO pricing_settings (key, value, updated_at) VALUES
    ('city_delivery_radius_km', '50', datetime('now')),
    ('city_delivery_fee_cents', '2500', datetime('now'));

CREATE TABLE bookings_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    equipment_id INTEGER,
    location_id INTEGER NOT NULL,
    fulfillment_type TEXT NOT NULL CHECK (fulfillment_type IN ('store_pickup', 'home_appointment', 'mail_ship', 'city_delivery')),
    shipping_address_json TEXT,
    staging_date TEXT,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    daily_rate_cents INTEGER NOT NULL DEFAULT 0,
    rental_total_cents INTEGER NOT NULL DEFAULT 0,
    shipping_fee_cents INTEGER NOT NULL DEFAULT 0,
    deposit_cents INTEGER NOT NULL DEFAULT 35000,
    add_ons_total_cents INTEGER NOT NULL DEFAULT 0,
    is_admin_created INTEGER NOT NULL DEFAULT 0,
    customer_notes TEXT,
    appointment_status TEXT NOT NULL DEFAULT 'n/a',
    confirmed_pickup_date TEXT,
    confirmed_pickup_time_start TEXT,
    confirmed_pickup_time_end TEXT,
    proposed_pickup_date TEXT,
    proposed_pickup_time_start TEXT,
    proposed_pickup_time_end TEXT,
    proposed_at TEXT,
    appointment_confirmed_at TEXT,
    status TEXT NOT NULL DEFAULT 'pending_payment',
    agreement_accepted_at TEXT,
    square_payment_id TEXT,
    cancelled_at TEXT,
    cancellation_fee_cents INTEGER,
    created_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(user_id),
    FOREIGN KEY (equipment_id) REFERENCES equipment(id),
    FOREIGN KEY (location_id) REFERENCES locations(id)
);

INSERT INTO bookings_new SELECT * FROM bookings;

DROP TABLE bookings;

ALTER TABLE bookings_new RENAME TO bookings;

CREATE INDEX idx_bookings_customer ON bookings(customer_id, status);
CREATE INDEX idx_bookings_equipment_dates ON bookings(equipment_id, start_date, end_date);
CREATE INDEX idx_bookings_location ON bookings(location_id, status);

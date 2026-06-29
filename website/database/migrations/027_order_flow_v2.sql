-- Order flow v2: three-axis statuses, fulfillment renames, notifications

CREATE TABLE bookings_v2 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    equipment_id INTEGER,
    location_id INTEGER NOT NULL,
    fulfillment_type TEXT NOT NULL CHECK (fulfillment_type IN ('pickup', 'pickup_appointment', 'mail_ship', 'city_delivery')),
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
    appointment_status TEXT NOT NULL DEFAULT 'appointment_na',
    confirmed_pickup_date TEXT,
    confirmed_pickup_time_start TEXT,
    confirmed_pickup_time_end TEXT,
    proposed_pickup_date TEXT,
    proposed_pickup_time_start TEXT,
    proposed_pickup_time_end TEXT,
    proposed_at TEXT,
    appointment_confirmed_at TEXT,
    status TEXT NOT NULL DEFAULT 'pending_payment',
    payment_status TEXT NOT NULL DEFAULT 'payment_pending_square',
    booking_status TEXT NOT NULL DEFAULT 'booking_pending_payment',
    fulfillment_status TEXT NOT NULL DEFAULT 'fulfillment_pending',
    cancellation_reason TEXT,
    etransfer_confirmed_amount_cents INTEGER,
    agreement_accepted_at TEXT,
    square_payment_id TEXT,
    cancelled_at TEXT,
    cancellation_fee_cents INTEGER,
    created_at TEXT NOT NULL,
    tax_cents INTEGER NOT NULL DEFAULT 0,
    company_name TEXT,
    home_address_json TEXT,
    billing_address_json TEXT,
    payment_method TEXT,
    etransfer_notified_at TEXT,
    reference_code TEXT,
    square_customer_id TEXT,
    square_card_id TEXT,
    square_deposit_payment_id TEXT,
    square_payment_flow TEXT,
    deposit_auth_at TEXT,
    FOREIGN KEY (customer_id) REFERENCES customers(user_id),
    FOREIGN KEY (equipment_id) REFERENCES equipment(id),
    FOREIGN KEY (location_id) REFERENCES locations(id)
);

INSERT INTO bookings_v2 (
    id, customer_id, equipment_id, location_id, fulfillment_type, shipping_address_json,
    staging_date, start_date, end_date, daily_rate_cents, rental_total_cents,
    shipping_fee_cents, deposit_cents, add_ons_total_cents, is_admin_created,
    customer_notes, appointment_status, confirmed_pickup_date, confirmed_pickup_time_start,
    confirmed_pickup_time_end, proposed_pickup_date, proposed_pickup_time_start,
    proposed_pickup_time_end, proposed_at, appointment_confirmed_at, status,
    payment_status, booking_status, fulfillment_status, cancellation_reason,
    etransfer_confirmed_amount_cents, agreement_accepted_at, square_payment_id,
    cancelled_at, cancellation_fee_cents, created_at, tax_cents, company_name,
    home_address_json, billing_address_json, payment_method, etransfer_notified_at,
    reference_code, square_customer_id, square_card_id, square_deposit_payment_id,
    square_payment_flow, deposit_auth_at
)
SELECT
    id, customer_id, equipment_id, location_id,
    CASE fulfillment_type
        WHEN 'store_pickup' THEN 'pickup'
        WHEN 'home_appointment' THEN 'pickup_appointment'
        ELSE fulfillment_type
    END,
    shipping_address_json, staging_date, start_date, end_date, daily_rate_cents, rental_total_cents,
    shipping_fee_cents, deposit_cents, add_ons_total_cents, is_admin_created,
    customer_notes,
    CASE appointment_status
        WHEN 'n/a' THEN 'appointment_na'
        WHEN 'awaiting_admin' THEN 'appointment_awaiting_admin'
        WHEN 'proposed' THEN 'appointment_proposed'
        WHEN 'confirmed' THEN 'appointment_confirmed'
        ELSE appointment_status
    END,
    confirmed_pickup_date, confirmed_pickup_time_start, confirmed_pickup_time_end,
    proposed_pickup_date, proposed_pickup_time_start, proposed_pickup_time_end,
    proposed_at, appointment_confirmed_at, status,
    CASE
        WHEN status = 'cancelled' AND payment_method = 'etransfer' THEN 'payment_etransfer_refunded'
        WHEN status = 'cancelled' THEN 'payment_square_refunded'
        WHEN status = 'pending_payment' AND payment_method = 'etransfer' AND etransfer_notified_at IS NOT NULL AND etransfer_notified_at != '' THEN 'payment_etransfer_sent'
        WHEN status = 'pending_payment' AND payment_method = 'etransfer' THEN 'payment_pending_etransfer'
        WHEN status = 'pending_payment' THEN 'payment_pending_square'
        WHEN status = 'booked_deposit_pending' THEN 'payment_deposit_scheduled'
        WHEN status = 'deposit_failed' THEN 'payment_deposit_scheduled_failed'
        WHEN status = 'ready_for_pickup' AND square_payment_flow = 'long_term_capture' THEN 'payment_square_full_captured'
        WHEN status = 'ready_for_pickup' THEN 'payment_deposit_scheduled_processed'
        WHEN status IN ('confirmed', 'staged', 'picked_up', 'shipped', 'late', 'returned') AND payment_method = 'etransfer' THEN 'payment_etransfer_confirmed'
        WHEN status IN ('confirmed', 'staged', 'picked_up', 'shipped', 'late', 'returned') AND square_payment_flow = 'long_term_capture' THEN 'payment_square_full_captured'
        WHEN status IN ('confirmed', 'staged', 'picked_up', 'shipped', 'late', 'returned') THEN 'payment_deposit_scheduled_processed'
        ELSE 'payment_pending_square'
    END,
    CASE status
        WHEN 'cancelled' THEN 'booking_cancelled'
        WHEN 'returned' THEN 'booking_closed'
        WHEN 'late' THEN 'booking_late'
        WHEN 'picked_up' THEN 'booking_active'
        WHEN 'shipped' THEN 'booking_active'
        WHEN 'pending_payment' THEN 'booking_pending_payment'
        ELSE 'booking_confirmed'
    END,
    CASE
        WHEN status = 'staged' THEN 'fulfillment_staged'
        WHEN status = 'shipped' THEN 'shipping_received'
        WHEN status IN ('picked_up', 'late') THEN 'fulfillment_with_customer'
        WHEN status = 'returned' THEN 'fulfillment_return_confirmed'
        ELSE 'fulfillment_pending'
    END,
    NULL,
    NULL,
    agreement_accepted_at, square_payment_id, cancelled_at, cancellation_fee_cents, created_at,
    tax_cents, company_name, home_address_json, billing_address_json, payment_method,
    etransfer_notified_at, reference_code, square_customer_id, square_card_id,
    square_deposit_payment_id, square_payment_flow, deposit_auth_at
FROM bookings;

DROP TABLE bookings;

ALTER TABLE bookings_v2 RENAME TO bookings;

CREATE INDEX idx_bookings_customer ON bookings(customer_id, status);
CREATE INDEX idx_bookings_equipment_dates ON bookings(equipment_id, start_date, end_date);
CREATE INDEX idx_bookings_location ON bookings(location_id, status);
CREATE UNIQUE INDEX idx_bookings_reference_code ON bookings(reference_code);

CREATE TABLE IF NOT EXISTS notification_rules (
    event_key TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    notify_customer_email INTEGER NOT NULL DEFAULT 0,
    notify_admin_email INTEGER NOT NULL DEFAULT 0,
    notify_admin_telegram INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notification_templates (
    event_key TEXT NOT NULL,
    channel TEXT NOT NULL DEFAULT 'email',
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (event_key, channel)
);

ALTER TABLE notification_log ADD COLUMN channel TEXT NOT NULL DEFAULT 'email';
ALTER TABLE notification_log ADD COLUMN event_key TEXT;
ALTER TABLE notification_log ADD COLUMN delivery_status TEXT NOT NULL DEFAULT 'logged';
ALTER TABLE notification_log ADD COLUMN error_message TEXT;

INSERT OR IGNORE INTO pricing_settings (key, value, updated_at) VALUES
    ('staging_lead_days', '2', datetime('now')),
    ('inter_city_staging_lead_days', '7', datetime('now'));

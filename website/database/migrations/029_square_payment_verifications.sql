ALTER TABLE payments ADD COLUMN square_verified_at TEXT;

CREATE TABLE IF NOT EXISTS square_payment_verifications (
    booking_id INTEGER NOT NULL,
    square_payment_id TEXT NOT NULL,
    verified_at TEXT NOT NULL,
    square_status TEXT NOT NULL,
    PRIMARY KEY (booking_id, square_payment_id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

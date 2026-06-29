ALTER TABLE bookings ADD COLUMN booking_kind TEXT NOT NULL DEFAULT 'customer';
ALTER TABLE bookings ADD COLUMN owner_block_requested_by TEXT;

CREATE INDEX IF NOT EXISTS idx_bookings_kind ON bookings(booking_kind, booking_status);

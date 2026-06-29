ALTER TABLE bookings ADD COLUMN reference_code TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS idx_bookings_reference_code ON bookings(reference_code);

-- Partner personal-use bookings (conflict-day borrow fees)
ALTER TABLE bookings ADD COLUMN partner_owner_equipment_id INTEGER;
ALTER TABLE bookings ADD COLUMN partner_conflict_days INTEGER NOT NULL DEFAULT 0;
ALTER TABLE bookings ADD COLUMN partner_borrow_fee_cents INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_bookings_partner_owner_equipment
    ON bookings(partner_owner_equipment_id);

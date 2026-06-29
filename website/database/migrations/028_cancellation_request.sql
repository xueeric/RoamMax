-- Customer cancellation requests awaiting admin approval before refund.

ALTER TABLE bookings ADD COLUMN cancellation_requested_at TEXT;
ALTER TABLE bookings ADD COLUMN cancellation_refund_cents INTEGER;

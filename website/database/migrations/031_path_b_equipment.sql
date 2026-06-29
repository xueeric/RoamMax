ALTER TABLE transfers ADD COLUMN status TEXT NOT NULL DEFAULT 'scheduled';
ALTER TABLE transfers ADD COLUMN completed_at TEXT;

UPDATE transfers SET status = 'completed', completed_at = created_at WHERE id IN (
    SELECT t.id FROM transfers t
    INNER JOIN equipment e ON e.id = t.equipment_id
    WHERE e.current_storage_location_id = t.to_location_id
);

CREATE INDEX IF NOT EXISTS idx_bookings_dates ON bookings(start_date, end_date);

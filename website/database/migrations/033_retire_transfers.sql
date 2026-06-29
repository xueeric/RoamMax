INSERT INTO staging_events (
    equipment_id, from_location_id, to_location_id, staging_date, ready_date, status, created_at
)
SELECT
    t.equipment_id,
    t.from_location_id,
    t.to_location_id,
    t.transfer_date,
    date(t.transfer_date, '+' || (CASE WHEN t.transit_days < 1 THEN 0 ELSE t.transit_days - 1 END) || ' days'),
    'scheduled',
    t.created_at
FROM transfers t
WHERE t.status = 'scheduled';

UPDATE transfers
SET status = 'completed', completed_at = COALESCE(completed_at, created_at)
WHERE status = 'scheduled';

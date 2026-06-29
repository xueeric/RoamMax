-- Coordinates for distance-based nearest-location selection on the home page.

ALTER TABLE locations ADD COLUMN latitude REAL;
ALTER TABLE locations ADD COLUMN longitude REAL;

UPDATE locations SET latitude = 53.3096, longitude = -113.5538
WHERE slug = 'edmonton-outlet';

UPDATE locations SET latitude = 53.6312, longitude = -113.5419
WHERE slug = 'edmonton-north';

UPDATE locations SET latitude = 52.2427, longitude = -113.8117
WHERE slug = 'red-deer-bower';

UPDATE locations SET latitude = 50.9983, longitude = -114.0719
WHERE slug = 'calgary-chinook';

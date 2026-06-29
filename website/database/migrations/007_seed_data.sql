-- Seed password for all demo users: changeme

INSERT INTO locations (slug, name, location_type, address, city, province, hours_json, is_customer_pickup, is_active, created_at) VALUES
    ('edmonton-outlet', 'Edmonton Premium Outlet', 'store', '1 Outlet Collection Way, Edmonton International Airport, AB T9E 1J5', 'Edmonton', 'AB', NULL, 1, 1, datetime('now')),
    ('edmonton-north', 'Edmonton North', 'home', '17104 121 St NW', 'Edmonton', 'AB', NULL, 1, 1, datetime('now')),
    ('red-deer-bower', 'Red Deer Bower Place', 'store', '4900 Molly Banister Dr, Red Deer, AB T4R 1N9', 'Red Deer', 'AB', NULL, 0, 0, datetime('now')),
    ('calgary-chinook', 'Calgary Chinook', 'store', '6455 Macleod Trail SW, Calgary, AB T2H 0K8', 'Calgary', 'AB', NULL, 0, 0, datetime('now'));

INSERT INTO users (email, password_hash, role, name, phone, created_at) VALUES
    ('admin@starlink.local', '$2y$12$5BrEfvAA8tufIQwDRJYhFuTnfUEGpiG.k0avDop5VOiOkawMV8U7S', 'admin', 'Eric Admin', NULL, datetime('now')),
    ('partner@starlink.local', '$2y$12$5BrEfvAA8tufIQwDRJYhFuTnfUEGpiG.k0avDop5VOiOkawMV8U7S', 'partner', 'Alex Partner', NULL, datetime('now'));

INSERT INTO partners (user_id, name, email, phone, created_at)
SELECT id, name, email, phone, datetime('now')
FROM users
WHERE email = 'partner@starlink.local';

INSERT INTO equipment (
    nickname,
    serial_number,
    owner_type,
    partner_id,
    purchase_date,
    purchase_cost_cents,
    starlink_account_email,
    data_plan,
    billing_cycle_start_day,
    current_storage_location_id,
    at_pickup_site,
    rental_priority,
    status,
    notes,
    created_at
) VALUES
    (
        'Eric Mini #1',
        'SLM-ADMIN-001',
        'admin',
        NULL,
        '2025-06-01',
        59900,
        'eric.starlink@example.com',
        'roam_50gb',
        15,
        (SELECT id FROM locations WHERE slug = 'edmonton-north'),
        0,
        0,
        'active',
        'Primary admin unit — usually staged from home to store.',
        datetime('now')
    ),
    (
        'Alex Mini #1',
        'SLM-PARTNER-001',
        'partner',
        (SELECT id FROM partners LIMIT 1),
        '2025-08-15',
        59900,
        'alex.starlink@example.com',
        'roam_50gb',
        1,
        (SELECT id FROM locations WHERE slug = 'edmonton-outlet'),
        1,
        10,
        'active',
        'Partner-contributed unit at Edmonton store.',
        datetime('now')
    );

INSERT INTO pricing_tiers (min_days, max_days, rate_cents_per_day, sort_order, is_active, created_at) VALUES
    (3, 7, 2500, 1, 1, datetime('now')),
    (8, 30, 2000, 2, 1, datetime('now'));

INSERT INTO inventory_items (
    sku, name, description, location_id, quantity_total, quantity_available,
    rental_price_cents_per_day, rental_price_cents_flat, is_active, created_at
) VALUES
    (
        'BAT-MINI-01',
        'Portable Battery Pack',
        'High-capacity battery for off-grid Starlink Mini sessions.',
        (SELECT id FROM locations WHERE slug = 'edmonton-outlet'),
        4,
        4,
        1500,
        NULL,
        1,
        datetime('now')
    ),
    (
        'CASE-MINI-01',
        'Rugged Travel Case',
        'Protective case included as an optional add-on line item.',
        (SELECT id FROM locations WHERE slug = 'edmonton-outlet'),
        6,
        6,
        NULL,
        2500,
        1,
        datetime('now')
    );

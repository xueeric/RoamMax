-- Production location catalog (addresses, slugs, pickup instructions).
-- Safe on fresh installs and upgrades from older demo slugs.

UPDATE locations
SET
    slug = 'edmonton-outlet',
    name = 'Edmonton Premium Outlet',
    location_type = 'store',
    address = '1 Outlet Collection Way, Edmonton International Airport, AB T9E 1J5',
    city = 'Edmonton',
    province = 'AB',
    pickup_instructions = 'Crepe Delicious — use entrance 3 or 4, next to Swarovski. Get 20% off in store when you pick up or drop off the dish.',
    is_customer_pickup = 1,
    is_active = 1
WHERE slug IN ('edmonton-store', 'edmonton-outlet') OR id = 1;

UPDATE locations
SET
    slug = 'edmonton-north',
    name = 'Edmonton North',
    location_type = 'home',
    address = '17104 121 St NW',
    city = 'Edmonton',
    province = 'AB',
    pickup_instructions = 'Pickup by Appointment only.',
    is_customer_pickup = 1,
    is_active = 1
WHERE slug IN ('edmonton-home', 'edmonton-north') OR id = 2;

UPDATE locations
SET
    slug = 'red-deer-bower',
    name = 'Red Deer Bower Place',
    location_type = 'store',
    address = '4900 Molly Banister Dr, Red Deer, AB T4R 1N9',
    city = 'Red Deer',
    province = 'AB',
    pickup_instructions = 'Crepe Delicious — Bower Place right side of the South entrance locate in the food court. Get 20% off in store when you pick up or drop off the dish.',
    is_customer_pickup = 0,
    is_active = 0
WHERE slug IN ('red-deer', 'red-deer-bower') OR id = 3;

UPDATE locations
SET
    slug = 'calgary-chinook',
    name = 'Calgary Chinook',
    location_type = 'store',
    address = '6455 Macleod Trail SW, Calgary, AB T2H 0K8',
    city = 'Calgary',
    province = 'AB',
    pickup_instructions = 'Crepe Delicious — locate in the food court, next to Starbucks. Get 20% off in store when you pick up or drop off the dish.',
    is_customer_pickup = 0,
    is_active = 0
WHERE slug IN ('calgary', 'calgary-chinook') OR id = 4;

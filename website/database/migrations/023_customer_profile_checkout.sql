ALTER TABLE customers ADD COLUMN company_name TEXT;
ALTER TABLE customers ADD COLUMN home_address_json TEXT;
ALTER TABLE customers ADD COLUMN shipping_address_json TEXT;
ALTER TABLE customers ADD COLUMN billing_address_json TEXT;

UPDATE customers
SET shipping_address_json = default_address_json
WHERE default_address_json IS NOT NULL
  AND (shipping_address_json IS NULL OR shipping_address_json = '');

ALTER TABLE bookings ADD COLUMN company_name TEXT;
ALTER TABLE bookings ADD COLUMN home_address_json TEXT;
ALTER TABLE bookings ADD COLUMN billing_address_json TEXT;

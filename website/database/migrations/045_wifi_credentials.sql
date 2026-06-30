-- WiFi network credentials per Starlink unit (customer-facing after booking).

ALTER TABLE equipment ADD COLUMN wifi_ssid TEXT;
ALTER TABLE equipment ADD COLUMN wifi_password_enc TEXT;

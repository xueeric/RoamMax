CREATE INDEX idx_equipment_priority ON equipment(rental_priority, status, at_pickup_site);
CREATE INDEX idx_equipment_partner ON equipment(partner_id);
CREATE INDEX idx_users_email ON users(email);

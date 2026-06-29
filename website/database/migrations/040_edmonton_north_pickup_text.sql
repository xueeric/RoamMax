UPDATE locations
SET pickup_instructions = 'Pickup by Appointment only.'
WHERE slug = 'edmonton-north'
   OR pickup_instructions = 'Appointment only.';

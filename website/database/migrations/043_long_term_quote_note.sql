UPDATE pricing_tiers
SET notes = 'Submit a quote for long-term commercial rates'
WHERE pricing_mode = 'custom_contact'
  AND (notes IS NULL OR notes = 'Contact Owner directly for long-term commercial rates');

UPDATE pricing_settings
SET value = 'Submit a quote for long-term commercial rates', updated_at = datetime('now')
WHERE key = 'long_term_contact_note'
  AND value = 'Contact Owner directly for long-term commercial rates';

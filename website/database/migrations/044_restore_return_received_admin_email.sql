-- Return received should notify admin per NotificationRuleService defaults.
UPDATE notification_rules
SET notify_admin_email = 1,
    updated_at = datetime('now')
WHERE event_key = 'fulfillment_return_received'
  AND notify_admin_email = 0;

<?php

declare(strict_types=1);

$versionManifest = is_file(dirname(__DIR__) . '/version.php')
    ? require dirname(__DIR__) . '/version.php'
    : [];

return [
    'name' => 'RoamMax',
    'brand_domain' => 'roammax.ca',
    'version' => (string) ($versionManifest['app_version'] ?? '0.0.0'),
    'version_released' => (string) ($versionManifest['released'] ?? ''),
    'brand_tagline' => 'Starlink Mini rental in Alberta — Edmonton, Calgary & Red Deer',
    'customer_mail_ship_enabled' => filter_var($_ENV['CUSTOMER_MAIL_SHIP_ENABLED'] ?? false, FILTER_VALIDATE_BOOL),
    'seo' => [
        'default_description' => 'Rent a portable Starlink Mini from RoamMax — online booking with pickup in Edmonton, Calgary, or Red Deer, Alberta.',
        'og_image_path' => 'assets/brand/social-og-1200x630.png',
        'business' => [
            'area_served' => 'Alberta',
            'region' => 'AB',
            'pickup_cities' => ['Edmonton', 'Calgary', 'Red Deer'],
        ],
    ],
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'America/Edmonton',
    'pending_booking_expiry_hours' => (int) ($_ENV['PENDING_BOOKING_EXPIRY_HOURS'] ?? 24),
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
    'url' => rtrim($_ENV['APP_URL'] ?? 'https://roammax.ca', '/'),
    'session_secret' => $_ENV['SESSION_SECRET'] ?? 'dev-insecure-secret',
    'db_path' => $_ENV['DB_PATH'] ?? 'data/starlink.db',
    'minimum_rental_days' => 3,
    'deposit_cents' => 35000,
    'shipping_fee_cents' => 15000,
    'staging_lead_days' => 2,
    'inter_city_staging_lead_days' => 7,
    'shipping_lead_days' => 1,
    'max_self_serve_days' => 30,
    'late_fee_cents_per_day' => 5000,
    'cancellation_free_hours' => 72,
    'mail_from' => $_ENV['MAIL_FROM'] ?? 'noreply@roammax.ca',
    'mail' => [
        'from_email' => $_ENV['MAIL_FROM'] ?? 'noreply@roammax.ca',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'RoamMax',
        'brevo_api_key' => $_ENV['BREVO_API_KEY'] ?? '',
        'support_contact_name' => $_ENV['SUPPORT_CONTACT_NAME'] ?? 'Eric',
        'support_phone' => $_ENV['SUPPORT_PHONE'] ?? '780-709-9939',
        'referral_url' => $_ENV['STARLINK_REFERRAL_URL'] ?? 'https://starlink.com/residential?referral=RC-DF-12500281-12436-81',
    ],
    'telegram' => [
        'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
        'admin_chat_id' => $_ENV['TELEGRAM_ADMIN_CHAT_ID'] ?? '',
    ],
    'payments' => [
        'etransfer_email' => $_ENV['ETRANSFER_EMAIL'] ?? 'payments@roammax.ca',
        'admin_email' => $_ENV['ADMIN_EMAIL'] ?? 'payments@roammax.ca',
        'short_term_max_days' => (int) ($_ENV['SHORT_TERM_MAX_DAYS'] ?? 4),
        'square_short_max_rental_days' => (int) ($_ENV['SQUARE_SHORT_MAX_RENTAL_DAYS'] ?? 4),
        'square_long_term_min_days' => (int) ($_ENV['SQUARE_LONG_TERM_MIN_DAYS'] ?? 5),
        'deposit_lead_days' => (int) ($_ENV['DEPOSIT_LEAD_DAYS'] ?? 1),
        'deposit_auth_hold_days' => (int) ($_ENV['DEPOSIT_AUTH_HOLD_DAYS'] ?? 7),
        'post_return_inspection_days' => (int) ($_ENV['POST_RETURN_INSPECTION_DAYS'] ?? 1),
    ],
    'default_home_location_slug' => 'edmonton-north',
    'square' => [
        'access_token' => $_ENV['SQUARE_ACCESS_TOKEN'] ?? '',
        'application_id' => $_ENV['SQUARE_APPLICATION_ID'] ?? '',
        'location_id' => $_ENV['SQUARE_LOCATION_ID'] ?? '',
        'webhook_signature_key' => $_ENV['SQUARE_WEBHOOK_SIGNATURE_KEY'] ?? '',
        'environment' => $_ENV['SQUARE_ENVIRONMENT'] ?? 'sandbox',
        'api_base' => ($_ENV['SQUARE_ENVIRONMENT'] ?? 'sandbox') === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com',
    ],
];

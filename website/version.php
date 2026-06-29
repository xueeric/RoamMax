<?php

declare(strict_types=1);

/**
 * Release manifest — bump app_version on each production deploy.
 * Per-page version overrides are optional; unset pages inherit app_version.
 */
return [
    'app_version' => '2.0',
    'released' => '2026-05-28',
    'pages' => [
        'customer/home' => ['version' => '2.2'],
        'customer/checkout' => ['version' => '2.0'],
        'customer/checkout-gate' => ['version' => '2.0'],
        'customer/agreement' => ['version' => '2.0'],
        'customer/booking-success' => ['version' => '2.0'],
        'customer/etransfer-pay' => ['version' => '2.0'],
        'customer/square-pay' => ['version' => '2.0'],
        'customer/update-card' => ['version' => '2.0'],
        'customer/long-term-success' => ['version' => '2.0'],
        'customer/bookings' => ['version' => '2.0'],
        'customer/booking-detail' => ['version' => '2.0'],
        'customer/profile' => ['version' => '2.0'],
        'customer/payment-method' => ['version' => '2.0'],
        'auth/login' => ['version' => '2.0'],
        'auth/register' => ['version' => '2.0'],
        'admin/dashboard' => ['version' => '2.1'],
        'admin/bookings' => ['version' => '2.0'],
        'admin/booking-detail' => ['version' => '2.0'],
        'admin/booking-new' => ['version' => '2.0'],
        'admin/equipment' => ['version' => '2.1'],
        'admin/equipment-form' => ['version' => '2.1'],
        'admin/accessories' => ['version' => '2.1'],
        'admin/locations' => ['version' => '2.0'],
        'admin/location-form' => ['version' => '2.0'],
        'admin/long-term-requests' => ['version' => '2.0'],
        'admin/pricing' => ['version' => '2.0'],
        'admin/financials' => ['version' => '2.0'],
        'admin/notifications' => ['version' => '2.0'],
        'admin/users' => ['version' => '2.0'],
        'admin/user-detail' => ['version' => '2.1'],
        'admin/user-new' => ['version' => '2.0'],
        'admin/staging' => ['version' => '2.1'],
        'admin/blocks' => ['version' => '2.2'],
        'admin/appointments' => ['version' => '2.0'],
        'admin/inventory' => ['version' => '2.0'],
        'admin/transfers' => ['version' => '2.0'],
        'admin/swaps' => ['version' => '2.0'],
        'partner/dashboard' => ['version' => '2.2'],
        'errors/not-found' => ['version' => '2.0'],
        'errors/forbidden' => ['version' => '2.0'],
        'system/version' => ['version' => '2.1'],
    ],
];

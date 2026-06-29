#!/usr/bin/env php
<?php

declare(strict_types=1);

define('WEBSITE_ROOT', dirname(__DIR__));

require WEBSITE_ROOT . '/bootstrap.php';

use Starlink\Services\BookingService;

$hours = (int) config('pending_booking_expiry_hours', 24);
$expired = (new BookingService())->expireStalePendingBookings($hours);

echo sprintf("Expired %d stale pending-payment booking(s) older than %d hour(s).\n", $expired, $hours);

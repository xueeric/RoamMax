#!/usr/bin/env php
<?php

declare(strict_types=1);

define('WEBSITE_ROOT', dirname(__DIR__));

require WEBSITE_ROOT . '/bootstrap.php';

use Starlink\Services\SquareDepositService;
use Starlink\Services\DepositCronService;
use Starlink\Services\BookingService;

$expired = (new BookingService())->expireStalePendingBookings((int) config('pending_booking_expiry_hours', 24));
$result = (new SquareDepositService())->processDueDepositAuthorizations();
(new DepositCronService())->recordRun($result);

echo sprintf(
    "Expired %d stale pending booking(s).\nDeposit authorization run complete: processed=%d succeeded=%d failed=%d\n",
    $expired,
    $result['processed'],
    $result['succeeded'],
    $result['failed'],
);

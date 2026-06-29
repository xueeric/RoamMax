#!/usr/bin/env php
<?php

declare(strict_types=1);

define('WEBSITE_ROOT', dirname(__DIR__, 2) . '/website');

require WEBSITE_ROOT . '/bootstrap.php';

$_ENV['NOTIFICATIONS_LIVE'] = 'false';
putenv('NOTIFICATIONS_LIVE=false');

use Starlink\Database\Connection;
use Starlink\Services\AvailabilityService;
use Starlink\Services\BookingService;
use Starlink\Services\NotificationService;
use Starlink\Services\OperationsService;
use Starlink\Services\PayoffService;
use Starlink\Services\StagingService;

function sampleAddress(): array
{
    return [
        'line1' => '123 Test St',
        'city' => 'Edmonton',
        'province' => 'AB',
        'postal_code' => 'T5J 1A1',
    ];
}

final class SelfTestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    /** @var list<string> */
    private array $failures = [];

    public function run(): int
    {
        echo "StarLink self-test\n";
        echo str_repeat('=', 40) . "\n";

        $this->section('Database & migrations');
        $this->testMigrationsApplied();
        $this->testCoreTables();

        $this->section('Authentication');
        $this->testSeedUsers();

        $this->section('Availability & pricing');
        $this->testAvailabilityEndpointData();
        $this->testSameCityStaging();

        $this->section('Booking lifecycle');
        $this->testBookingLifecycle();

        $this->section('Cancellation');
        $this->testCancellation();

        $this->section('Operations & financials');
        $this->testDashboardStats();
        $this->testPayoffReports();
        $this->testLocationToggle();
        $this->testNotifications();

        $this->section('HTTP smoke tests');
        $this->testHttpRoutes();

        echo str_repeat('=', 40) . "\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        if ($this->failures !== []) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "  - {$failure}\n";
            }
        }

        return $this->failed === 0 ? 0 : 1;
    }

    private function section(string $name): void
    {
        echo "\n[{$name}]\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "  OK  {$message}\n";
            return;
        }

        $this->failed++;
        $this->failures[] = $message;
        echo "  FAIL {$message}\n";
    }

    private function testMigrationsApplied(): void
    {
        $db = Connection::get();
        $names = $db->query('SELECT name FROM migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
        $this->assert(in_array('013_create_notifications.sql', $names, true), 'Migration 013 applied');
        $this->assert(in_array('014_create_equipment_costs.sql', $names, true), 'Migration 014 applied');
    }

    private function testCoreTables(): void
    {
        $db = Connection::get();
        $tables = [
            'users',
            'equipment',
            'bookings',
            'payments',
            'inventory_items',
            'notification_log',
            'transfers',
            'staging_events',
            'equipment_costs',
        ];

        foreach ($tables as $table) {
            $stmt = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '{$table}'");
            $this->assert($stmt->fetch() !== false, "Table {$table} exists");
        }
    }

    private function testSeedUsers(): void
    {
        $db = Connection::get();

        $admin = $db->query("SELECT id, password_hash FROM users WHERE email = 'admin@starlink.local' LIMIT 1")->fetch();
        $partner = $db->query("SELECT id, password_hash FROM users WHERE email = 'partner@starlink.local' LIMIT 1")->fetch();

        $this->assert($admin !== false, 'Admin seed user exists');
        $this->assert($partner !== false, 'Partner seed user exists');
        $this->assert(
            is_string($admin['password_hash'] ?? null) && password_verify('changeme', $admin['password_hash']),
            'Admin password verifies',
        );
        $this->assert(
            is_string($partner['password_hash'] ?? null) && password_verify('changeme', $partner['password_hash']),
            'Partner password verifies',
        );
    }

    private function testAvailabilityEndpointData(): void
    {
        $db = Connection::get();
        $locationId = (int) $db->query("SELECT id FROM locations WHERE slug = 'edmonton-outlet' LIMIT 1")->fetchColumn();
        [$start, $end] = $this->findAvailableWindow($locationId, 'pickup', 4, 60);

        $availability = new AvailabilityService();
        $available = $availability->isRangeAvailable($locationId, 'pickup', $start, $end);
        $this->assert($available, 'Availability finds units for store pickup window');
    }

    private function testSameCityStaging(): void
    {
        $db = Connection::get();
        $staging = new StagingService();
        $outletId = (int) $db->query("SELECT id FROM locations WHERE slug = 'edmonton-outlet' LIMIT 1")->fetchColumn();
        $northId = (int) $db->query("SELECT id FROM locations WHERE slug = 'edmonton-north' LIMIT 1")->fetchColumn();
        $ericStmt = $db->prepare(
            'SELECT e.*, l.city AS storage_city
             FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.serial_number = :serial LIMIT 1'
        );
        $ericStmt->execute(['serial' => 'SLM-ADMIN-001']);
        $eric = $ericStmt->fetch();
        $ericId = (int) $eric['id'];

        $db->prepare(
            'UPDATE equipment SET status = :status, current_storage_location_id = :location_id, at_pickup_site = 0 WHERE id = :id'
        )->execute(['status' => 'active', 'location_id' => $northId, 'id' => $ericId]);
        $ericStmt->execute(['serial' => 'SLM-ADMIN-001']);
        $eric = $ericStmt->fetch();

        $today = today_date();
        $this->assert(
            $staging->needsStaging($eric, $outletId, 'pickup'),
            'North unit needs staging for outlet pickup',
        );
        $this->assert(
            $staging->earliestStartDate($eric, $outletId, 'pickup', $today)->format('Y-m-d')
                === $today->modify('+1 day')->format('Y-m-d'),
            'North unit outlet pickup starts next day',
        );

        $alexId = (int) $db->query("SELECT id FROM equipment WHERE serial_number = 'SLM-PARTNER-001' LIMIT 1")->fetchColumn();
        $otherUnitIds = $db->query(
            "SELECT id FROM equipment WHERE id != {$ericId} AND status = 'active'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $db->prepare('UPDATE equipment SET status = :status WHERE id = :id')->execute(['status' => 'maintenance', 'id' => $alexId]);
        foreach ($otherUnitIds as $otherId) {
            $db->prepare('UPDATE equipment SET status = :status WHERE id = :id')->execute([
                'status' => 'maintenance',
                'id' => (int) $otherId,
            ]);
        }
        try {
            $availability = new AvailabilityService();
            $globalEarliest = $availability->globalEarliestStart($outletId, 'pickup');
            $this->assert(
                $globalEarliest === $today->modify('+1 day')->format('Y-m-d'),
                'Outlet calendar earliest start is tomorrow when only north unit remains',
            );
        } finally {
            $db->prepare("UPDATE equipment SET status = 'active' WHERE status = 'maintenance'")->execute();
            $db->prepare(
                'UPDATE equipment SET current_storage_location_id = :location_id, at_pickup_site = 0 WHERE id = :id'
            )->execute(['location_id' => $northId, 'id' => $ericId]);
            $db->prepare(
                'UPDATE equipment SET current_storage_location_id = (SELECT id FROM locations WHERE slug = :slug), at_pickup_site = 1 WHERE serial_number = :serial'
            )->execute(['slug' => 'edmonton-outlet', 'serial' => 'SLM-PARTNER-001']);
        }
    }

    private function testBookingLifecycle(): void
    {
        $db = Connection::get();
        $bookings = new BookingService();
        $ops = new OperationsService();

        $customerId = $this->ensureTestCustomer();
        $locationId = (int) $db->query("SELECT id FROM locations WHERE slug = 'edmonton-outlet' LIMIT 1")->fetchColumn();
        [$start, $end] = $this->findAvailableWindow($locationId, 'pickup', 4);

        $addr = sampleAddress();

        try {
            $booking = $bookings->createCustomerBooking(
                $customerId,
                $locationId,
                'pickup',
                $start,
                $end,
                [],
                'Self-test lifecycle booking',
                null,
                true,
                $addr,
                $addr,
                null,
                'etransfer',
            );
        } catch (\Starlink\Services\BookingUnavailableException $e) {
            $this->assert(false, 'Lifecycle booking window available: ' . $e->getMessage());
            return;
        }

        $bookingId = (int) $booking['id'];
        $this->assert($booking['status'] === 'pending_payment', 'Created booking is pending payment');

        $paid = $bookings->markPaid($bookingId, 'selftest_pay_' . $bookingId);
        $this->assert($paid['status'] === 'confirmed', 'Paid booking becomes confirmed');

        $units = (new AvailabilityService())->eligibleUnitsForBooking($paid);
        $this->assert($units !== [], 'Eligible unit available for lifecycle booking');
        $adminId = (int) $db->query("SELECT id FROM users WHERE email = 'admin@starlink.local' LIMIT 1")->fetchColumn();
        $bookings->assignEquipment($bookingId, (int) $units[0]['id'], $adminId);

        $ops->advanceBooking($bookingId, 'stage');
        $staged = $bookings->findBooking($bookingId);
        $this->assert($staged !== null && $staged['status'] === 'staged', 'Booking can be staged');

        $ops->advanceBooking($bookingId, 'pickup');
        $pickedUp = $bookings->findBooking($bookingId);
        $this->assert($pickedUp !== null && $pickedUp['status'] === 'picked_up', 'Booking can be marked picked up');

        $equipmentStatus = $db->prepare('SELECT status FROM equipment WHERE id = :id');
        $equipmentStatus->execute(['id' => (int) $pickedUp['equipment_id']]);
        $this->assert($equipmentStatus->fetchColumn() === 'with_customer', 'Equipment status is with_customer after pickup');

        $ops->advanceBooking($bookingId, 'return_received');
        $returned = $bookings->findBooking($bookingId);
        $this->assert(
            $returned !== null && booking_fulfillment_status($returned) === 'fulfillment_return_received',
            'Booking can be marked return received',
        );

        $ops->advanceBooking($bookingId, 'confirm_qc');
        $closed = $bookings->findBooking($bookingId);
        $this->assert(
            $closed !== null && booking_lifecycle_status($closed) === 'booking_closed',
            'Booking can be closed after QC',
        );

        $equipmentStatus->execute(['id' => (int) $closed['equipment_id']]);
        $this->assert($equipmentStatus->fetchColumn() === 'active', 'Equipment status returns to active');
    }

    private function testCancellation(): void
    {
        $db = Connection::get();
        $bookings = new BookingService();
        $customerId = $this->ensureTestCustomer();
        $locationId = (int) $db->query("SELECT id FROM locations WHERE slug = 'edmonton-outlet' LIMIT 1")->fetchColumn();
        [$start, $end] = $this->findAvailableWindow($locationId, 'pickup', 4, 35);

        $addr = sampleAddress();

        try {
            $booking = $bookings->createCustomerBooking(
                $customerId,
                $locationId,
                'pickup',
                $start,
                $end,
                [],
                'Self-test cancel booking',
                null,
                true,
                $addr,
                $addr,
                null,
                'etransfer',
            );
        } catch (\Starlink\Services\BookingUnavailableException $e) {
            $this->assert(false, 'Cancellation booking window available: ' . $e->getMessage());
            return;
        }

        $bookingId = (int) $booking['id'];
        $cancelled = $bookings->cancel($bookingId, $customerId, false);

        $this->assert($cancelled['status'] === 'cancelled', 'Customer can cancel unpaid booking');
        $this->assert((int) ($cancelled['cancellation_fee_cents'] ?? 0) === 0, 'Unpaid cancellation has no fee');
    }

    private function testDashboardStats(): void
    {
        $stats = (new OperationsService())->dashboardStats();
        $required = ['active_bookings', 'units_out', 'revenue_cents', 'deposits_held_cents', 'pending_appointments'];

        foreach ($required as $key) {
            $this->assert(array_key_exists($key, $stats), "Dashboard stat {$key} present");
        }
    }

    private function testPayoffReports(): void
    {
        $reports = (new PayoffService())->equipmentReports();
        $this->assert(count($reports) >= 2, 'Payoff reports include seeded equipment');

        if ($reports !== []) {
            $first = $reports[0];
            $this->assert(isset($first['capex_cents'], $first['revenue_cents'], $first['payoff_pct']), 'Payoff report has expected fields');
        }

        $byOwner = (new PayoffService())->revenueByOwner();
        $this->assert($byOwner !== [], 'Revenue by owner report returns rows');
    }

    private function testLocationToggle(): void
    {
        $ops = new OperationsService();
        $db = Connection::get();
        $locationId = (int) $db->query("SELECT id FROM locations WHERE slug = 'red-deer-bower' LIMIT 1")->fetchColumn();

        $ops->setLocationActive($locationId, true);
        $active = (int) $db->query("SELECT is_active FROM locations WHERE id = {$locationId}")->fetchColumn();
        $this->assert($active === 1, 'Red Deer location can be activated');

        $ops->setLocationActive($locationId, false);
        $inactive = (int) $db->query("SELECT is_active FROM locations WHERE id = {$locationId}")->fetchColumn();
        $this->assert($inactive === 0, 'Red Deer location can be deactivated');
    }

    private function testNotifications(): void
    {
        $db = Connection::get();
        $before = (int) $db->query('SELECT COUNT(*) FROM notification_log')->fetchColumn();

        (new NotificationService())->notify(
            'selftest@starlink.local',
            'Self-test notification',
            'This is a test notification body.',
            ['source' => 'self-test'],
        );

        $after = (int) $db->query('SELECT COUNT(*) FROM notification_log')->fetchColumn();
        $this->assert($after === $before + 1, 'Notification logged to database');

        $logPath = WEBSITE_ROOT . '/data/notifications.log';
        $this->assert(is_file($logPath), 'Notification file log exists');
    }

    private function testHttpRoutes(): void
    {
        $port = 18080;
        $cmd = sprintf(
            'php -S 127.0.0.1:%d -t %s %s',
            $port,
            escapeshellarg(WEBSITE_ROOT . '/public'),
            escapeshellarg(WEBSITE_ROOT . '/public/index.php'),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, WEBSITE_ROOT);
        if (!is_resource($process)) {
            $this->assert(false, 'Unable to start HTTP server for smoke tests');
            return;
        }

        usleep(400000);

        try {
            $base = "http://127.0.0.1:{$port}";
            $this->assertHttpStatus($base . '/', 200, 'GET / returns 200');
            $this->assertHttpStatus($base . '/book', 302, 'GET /book redirects to home calendar');
            $this->assertHttpStatus($base . '/login', 200, 'GET /login returns 200');
            $this->assertHttpStatus($base . '/admin', 302, 'GET /admin redirects when logged out');

            $locationId = (int) Connection::get()->query("SELECT id FROM locations WHERE slug = 'edmonton-outlet' LIMIT 1")->fetchColumn();
            $start = (new DateTimeImmutable('+14 days'))->format('Y-m-d');
            $end = (new DateTimeImmutable('+17 days'))->format('Y-m-d');
            $availabilityUrl = $base . '/availability?' . http_build_query([
                'location_id' => $locationId,
                'fulfillment_type' => 'store_pickup',
                'start' => $start,
                'end' => $end,
                'start_date' => $start,
                'end_date' => $end,
            ]);

            $response = $this->httpGet($availabilityUrl);
            $this->assert($response['status'] === 200, 'GET /availability returns 200');
            $json = json_decode($response['body'], true);
            $this->assert(
                is_array($json) && array_key_exists('range_available', $json) && array_key_exists('days', $json),
                '/availability returns JSON with range_available and days',
            );
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    /** @return array{status:int, body:string} */
    private function httpGet(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($GLOBALS['http_response_header'] ?? []);
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $status = (int) $matches[1];
        }

        return ['status' => $status, 'body' => $body === false ? '' : $body];
    }

    private function assertHttpStatus(string $url, int $expected, string $message): void
    {
        $response = $this->httpGet($url);
        $this->assert($response['status'] === $expected, $message . " (got {$response['status']})");
    }

    /** @return array{0:string,1:string} */
    private function findAvailableWindow(int $locationId, string $fulfillment, int $days, int $startOffset = 14): array
    {
        $availability = new AvailabilityService();

        for ($offset = $startOffset; $offset <= 120; $offset++) {
            $start = (new DateTimeImmutable('+' . $offset . ' days'))->format('Y-m-d');
            $end = (new DateTimeImmutable('+' . ($offset + $days - 1) . ' days'))->format('Y-m-d');
            if ($availability->isRangeAvailable($locationId, $fulfillment, $start, $end)) {
                return [$start, $end];
            }
        }

        throw new RuntimeException('No available booking window found for self-test.');
    }

    private function ensureTestCustomer(): int
    {
        $db = Connection::get();
        $email = 'selftest.customer@starlink.local';
        $existing = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existing->execute(['email' => $email]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO users (email, password_hash, role, name, phone, created_at)
                 VALUES (:email, :password_hash, :role, :name, NULL, :created_at)'
            )->execute([
                'email' => $email,
                'password_hash' => password_hash('changeme123', PASSWORD_DEFAULT),
                'role' => 'customer',
                'name' => 'Self Test Customer',
                'created_at' => now_utc(),
            ]);
            $userId = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO customers (user_id) VALUES (:user_id)')->execute(['user_id' => $userId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $userId;
    }
}

$runner = new SelfTestRunner();
exit($runner->run());

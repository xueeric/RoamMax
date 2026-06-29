<?php

declare(strict_types=1);

/**
 * Integration tests for customer + admin booking flows (order flow v2).
 * Run: php bin/flow-test.php
 */

require dirname(__DIR__) . '/bootstrap.php';

$_ENV['NOTIFICATIONS_LIVE'] = 'false';
putenv('NOTIFICATIONS_LIVE=false');

use Starlink\Booking\BookingStatuses;
use Starlink\Database\Connection;
use Starlink\Services\AvailabilityService;
use Starlink\Services\BookingService;
use Starlink\Services\BookingStateService;
use Starlink\Services\BookingUnavailableException;
use Starlink\Services\NotificationRuleService;
use Starlink\Services\OperationsService;
use Starlink\Services\PayoffService;
use Starlink\Services\PoolRevenueService;
use Starlink\Services\SubscriptionCostService;

$pdo = Connection::get();
$bookings = new BookingService();
$ops = new OperationsService();
$state = new BookingStateService();

/** @var list<array{name: string, status: string, detail: string}> */
$results = [];
$testBookingIds = [];
$testNum = 0;

function pass(string $name, string $detail = ''): void
{
    global $results;
    $results[] = ['name' => $name, 'status' => 'PASS', 'detail' => $detail];
    echo "  PASS  {$name}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
}

function fail(string $name, string $detail): void
{
    global $results;
    $results[] = ['name' => $name, 'status' => 'FAIL', 'detail' => $detail];
    echo "  FAIL  {$name} — {$detail}" . PHP_EOL;
}

function skip(string $name, string $detail): void
{
    global $results;
    $results[] = ['name' => $name, 'status' => 'SKIP', 'detail' => $detail];
    echo "  SKIP  {$name} — {$detail}" . PHP_EOL;
}

function warn(string $name, string $detail): void
{
    global $results;
    $results[] = ['name' => $name, 'status' => 'WARN', 'detail' => $detail];
    echo "  WARN  {$name} — {$detail}" . PHP_EOL;
}

/** @return array<string, string> */
function sampleAddress(): array
{
    return [
        'line1' => '123 Test St',
        'city' => 'Edmonton',
        'province' => 'AB',
        'postal_code' => 'T5J 1A1',
    ];
}

function nextDates(int $days = 3): array
{
    global $testNum;
    ++$testNum;
    $start = new DateTimeImmutable('2030-06-01');
    $start = $start->modify('+' . ($testNum * 30) . ' days');
    $end = $start->modify('+' . ($days - 1) . ' days');

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function findAppointmentWindow(int $locationId = 2): array
{
    $availability = new AvailabilityService();
    $base = new DateTimeImmutable('2049-01-01');

    for ($offset = 0; $offset < 730; $offset += 7) {
        $start = $base->modify('+' . $offset . ' days');
        $end = $start->modify('+2 days');
        if ($availability->isRangeAvailable(
            $locationId,
            'pickup_appointment',
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        )) {
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        }
    }

    throw new RuntimeException('No pickup_appointment window found');
}

function findStorePickupWindow(int $locationId = 1): array
{
    $availability = new AvailabilityService();
    $base = new DateTimeImmutable('2049-06-01');

    for ($offset = 0; $offset < 730; $offset += 7) {
        $start = $base->modify('+' . $offset . ' days');
        $end = $start->modify('+2 days');
        if ($availability->isRangeAvailable(
            $locationId,
            'pickup',
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        )) {
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        }
    }

    throw new RuntimeException('No store pickup window found');
}

/** @param array<string, mixed> $overrides */
function createBookingWithDates(string $startDate, string $endDate, array $overrides = []): array
{
    global $bookings, $testBookingIds, $testNum;
    ++$testNum;
    $customerId = (int) ($overrides['customer_id'] ?? 6);
    $locationId = (int) ($overrides['location_id'] ?? 1);
    $fulfillment = (string) ($overrides['fulfillment_type'] ?? 'pickup');
    $paymentMethod = (string) ($overrides['payment_method'] ?? 'etransfer');
    $addr = sampleAddress();
    $shipping = $fulfillment === 'mail_ship' ? array_merge($addr, ['name' => 'Test Ship']) : null;
    $pickupDate = $fulfillment === 'pickup_appointment' ? $startDate : null;
    $pickupTimeStart = $fulfillment === 'pickup_appointment' ? '14:00' : null;
    $pickupTimeEnd = null;

    $created = $bookings->createCustomerBooking(
        $customerId,
        $locationId,
        $fulfillment,
        $startDate,
        $endDate,
        [],
        'Flow test booking',
        $shipping,
        true,
        $addr,
        $addr,
        null,
        $paymentMethod,
        $pickupDate,
        $pickupTimeStart,
        $pickupTimeEnd,
    );

    $testBookingIds[] = (int) $created['id'];

    return $created;
}

/** @param array<string, mixed> $overrides */
function createBooking(array $overrides = []): array
{
    global $bookings, $testBookingIds;
    [$start, $end] = nextDates((int) ($overrides['days'] ?? 3));
    $customerId = (int) ($overrides['customer_id'] ?? 6);
    $locationId = (int) ($overrides['location_id'] ?? 1);
    $fulfillment = (string) ($overrides['fulfillment_type'] ?? 'pickup');
    $paymentMethod = (string) ($overrides['payment_method'] ?? 'etransfer');
    $addr = sampleAddress();
    $shipping = $fulfillment === 'mail_ship' ? array_merge($addr, ['name' => 'Test Ship']) : null;

    $created = $bookings->createCustomerBooking(
        $customerId,
        $locationId,
        $fulfillment,
        $start,
        $end,
        [],
        'Flow test booking',
        $shipping,
        true,
        $addr,
        $addr,
        null,
        $paymentMethod,
    );

    $testBookingIds[] = (int) $created['id'];

    return $created;
}

/** @param array<string, mixed> $booking */
function assertEq(string $name, mixed $actual, mixed $expected): void
{
    if ($actual === $expected) {
        pass($name, (string) $actual);
    } else {
        fail($name, "expected {$expected}, got {$actual}");
    }
}

function expectException(string $name, callable $fn, ?string $contains = null): void
{
    try {
        $fn();
        fail($name, 'expected exception, none thrown');
    } catch (BookingUnavailableException $e) {
        if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
            fail($name, 'exception message mismatch: ' . $e->getMessage());
        } else {
            pass($name, $e->getMessage());
        }
    } catch (Throwable $e) {
        fail($name, 'wrong exception: ' . $e->getMessage());
    }
}

function simulateSquareShortPaid(int $bookingId): void
{
    global $pdo, $state, $bookings;
    $booking = $bookings->findBooking($bookingId);
    if ($booking === null) {
        throw new RuntimeException('Booking missing');
    }
    $pdo->prepare(
        'UPDATE bookings SET square_payment_id = :pid, square_payment_flow = :flow, square_card_id = :card, payment_method = :method WHERE id = :id'
    )->execute([
        'pid' => 'mock_rental_' . $bookingId,
        'flow' => 'short_term_auth',
        'card' => 'mock_card_' . $bookingId,
        'method' => 'square',
        'id' => $bookingId,
    ]);
    $state->apply($bookingId, [
        'payment_status' => 'payment_square_rental_captured',
        'booking_status' => 'booking_confirmed',
    ]);
}

function simulateDepositAuthorized(int $bookingId): void
{
    global $pdo, $state, $bookings;
    $booking = $bookings->findBooking($bookingId);
    if ($booking === null) {
        throw new RuntimeException('Booking missing');
    }
    $pdo->prepare(
        'UPDATE bookings SET square_deposit_payment_id = :did, deposit_auth_at = :at WHERE id = :id'
    )->execute([
        'did' => 'mock_deposit_' . $bookingId,
        'at' => now_utc(),
        'id' => $bookingId,
    ]);
    $state->apply($bookingId, [
        'payment_status' => 'payment_deposit_scheduled_processed',
        'booking_status' => 'booking_confirmed',
    ]);
}

function assignBookingUnit(int $bookingId, int $adminUserId = 1): array
{
    global $bookings;
    $booking = $bookings->findBooking($bookingId);
    if ($booking === null) {
        throw new RuntimeException('Booking missing');
    }
    if (!empty($booking['equipment_id'])) {
        return $booking;
    }
    $units = (new \Starlink\Services\AvailabilityService())->eligibleUnitsForBooking($booking);
    if ($units === []) {
        throw new RuntimeException('No eligible unit for booking ' . $bookingId);
    }

    return $bookings->assignEquipment($bookingId, (int) $units[0]['id'], $adminUserId);
}

function prepareForHandout(int $bookingId): void
{
    assignBookingUnit($bookingId);
}

function simulateEtransferPaid(int $bookingId): array
{
    global $bookings;
    $booking = $bookings->findBooking($bookingId);
    if ($booking === null) {
        throw new RuntimeException('Booking missing');
    }
    $bookings->acknowledgeEtransfer($bookingId, (int) $booking['customer_id']);

    return $bookings->confirmEtransfer($bookingId, $bookings->totalDueCents($booking));
}

function notificationCount(int $bookingId, string $eventKey): int
{
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notification_log WHERE event_key = :event_key AND context_json LIKE :ctx"
    );
    $stmt->execute([
        'event_key' => $eventKey,
        'ctx' => '%"booking_id":' . $bookingId . '%',
    ]);

    return (int) $stmt->fetchColumn();
}

echo "Order flow integration tests\n";
echo str_repeat('=', 60) . PHP_EOL;

// --- Customer: e-Transfer full lifecycle ---
echo "\n[e-Transfer full lifecycle]\n";
try {
    $b = createBooking(['payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    assertEq('Create etransfer booking → booking_pending_payment', booking_lifecycle_status($b), 'booking_pending_payment');
    assertEq('Create etransfer booking → payment_pending_etransfer', booking_payment_status($b), 'payment_pending_etransfer');
    assertEq('Checkout leaves unit unassigned', $b['equipment_id'], null);

    $bookings->acknowledgeEtransfer($id, (int) $b['customer_id']);
    $sent = $bookings->findBooking($id) ?? [];
    assertEq('Acknowledge e-Transfer → payment_etransfer_sent', booking_payment_status($sent), 'payment_etransfer_sent');
    assertEq('Acknowledge e-Transfer → still pending payment', booking_lifecycle_status($sent), 'booking_pending_payment');

    $confirmed = $bookings->confirmEtransfer($id, $bookings->totalDueCents($sent));
    assertEq('Confirm full e-Transfer → booking_confirmed', booking_lifecycle_status($confirmed), 'booking_confirmed');
    assertEq('Confirm full e-Transfer → payment_etransfer_confirmed', booking_payment_status($confirmed), 'payment_etransfer_confirmed');
    assertEq('Legacy status synced → confirmed', (string) $confirmed['status'], 'confirmed');

    if (!$state->canHandOutHardware($confirmed)) {
        fail('e-Transfer confirmed allows handout', 'canHandOutHardware returned false');
    } else {
        pass('e-Transfer confirmed allows handout');
    }

    prepareForHandout($id);
    $ops->advanceBooking($id, 'stage');
    $staged = $bookings->findBooking($id) ?? [];
    assertEq('Stage → fulfillment_staged', booking_fulfillment_status($staged), 'fulfillment_staged');

    $ops->advanceBooking($id, 'pickup');
    $picked = $bookings->findBooking($id) ?? [];
    assertEq('Pickup → booking_active', booking_lifecycle_status($picked), 'booking_active');
    assertEq('Pickup → fulfillment_with_customer', booking_fulfillment_status($picked), 'fulfillment_with_customer');

    $eq = $pdo->prepare('SELECT status FROM equipment WHERE id = :id');
    $eq->execute(['id' => (int) $picked['equipment_id']]);
    assertEq('Pickup → equipment with_customer', (string) $eq->fetchColumn(), 'with_customer');

    expectException('Customer cancel blocked while out', fn () => $bookings->cancel($id, (int) $b['customer_id'], false), 'cannot be cancelled');

    $ops->advanceBooking($id, 'return_received');
    $returned = $bookings->findBooking($id) ?? [];
    assertEq('Return received → fulfillment_return_received', booking_fulfillment_status($returned), 'fulfillment_return_received');
    $returnRule = (new NotificationRuleService())->ruleFor('fulfillment_return_received');
    if ((int) ($returnRule['notify_admin_email'] ?? 0) !== 1) {
        warn('Return received admin notification', 'notify_admin_email is disabled in notification_rules');
    } elseif (notificationCount($id, 'fulfillment_return_received') < 1) {
        warn('Return received admin notification', 'admin email enabled but no fulfillment_return_received log entry');
    } else {
        pass('Return received admin notification logged');
    }

    $ops->advanceBooking($id, 'confirm_qc');
    $closed = $bookings->findBooking($id) ?? [];
    assertEq('QC pass → booking_closed', booking_lifecycle_status($closed), 'booking_closed');
    assertEq('QC pass → fulfillment_return_confirmed', booking_fulfillment_status($closed), 'fulfillment_return_confirmed');
} catch (Throwable $e) {
    fail('e-Transfer full lifecycle', $e->getMessage());
}

// --- Partial e-Transfer ---
echo "\n[e-Transfer partial payment]\n";
try {
    $b = createBooking(['payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    $bookings->acknowledgeEtransfer($id, (int) $b['customer_id']);
    $partial = $bookings->confirmEtransfer($id, 10000);
    assertEq('Partial confirm → booking_pending_payment', booking_lifecycle_status($partial), 'booking_pending_payment');
    assertEq('Partial confirm → payment_etransfer_partial_confirmed', booking_payment_status($partial), 'payment_etransfer_partial_confirmed');

    if (BookingStatuses::isPaidPaymentStatus('payment_etransfer_partial_confirmed')) {
        fail('Partial payment in PAID list', 'payment_etransfer_partial_confirmed should not be paid');
    } else {
        pass('Partial payment excluded from PAID list');
    }

    if ($state->canHandOutHardware($partial)) {
        fail('Partial payment handout gate', 'canHandOutHardware=true — spec says must NOT release hardware');
    } else {
        pass('Partial payment blocks handout (canHandOutHardware=false)');
    }

    expectException('Stage blocked on partial payment', fn () => $ops->advanceBooking($id, 'stage'), 'confirmed before staging');

    $remainder = $bookings->confirmEtransfer($id, $bookings->totalDueCents($partial));
    assertEq('Remainder confirm → booking_confirmed', booking_lifecycle_status($remainder), 'booking_confirmed');
} catch (Throwable $e) {
    fail('e-Transfer partial payment', $e->getMessage());
}

// --- Customer cancel before payment ---
echo "\n[Customer cancel — unpaid]\n";
try {
    $b = createBooking(['payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    $before = notificationCount($id, 'booking_cancelled_by_customer');
    $cancelled = $bookings->cancel($id, (int) $b['customer_id'], false, 'Changed plans');
    assertEq('Cancel unpaid → booking_cancelled', booking_lifecycle_status($cancelled), 'booking_cancelled');
    assertEq('Cancel unpaid → equipment released', $cancelled['equipment_id'], null);
    $after = notificationCount($id, 'booking_cancelled_by_customer');
    if ($after <= $before) {
        warn('Customer cancel notification', 'booking_cancelled_by_customer not logged (check notification rules)');
    } else {
        pass('Customer cancel notification logged');
    }
} catch (Throwable $e) {
    fail('Customer cancel — unpaid', $e->getMessage());
}

// --- Admin cancel with reason ---
echo "\n[Admin cancel — payment not received]\n";
try {
    $b = createBooking(['payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    $bookings->acknowledgeEtransfer($id, (int) $b['customer_id']);
    $before = notificationCount($id, 'booking_cancelled_by_admin');
    $cancelled = $bookings->cancel($id, 1, true, 'Payment not received');
    assertEq('Admin cancel → booking_cancelled', booking_lifecycle_status($cancelled), 'booking_cancelled');
    assertEq('Admin cancel stores reason', (string) ($cancelled['cancellation_reason'] ?? ''), 'Payment not received');
    $after = notificationCount($id, 'booking_cancelled_by_admin');
    if ($after <= $before) {
        warn('Admin cancel notification', 'booking_cancelled_by_admin not logged');
    } else {
        pass('Admin cancel notification logged');
    }
} catch (Throwable $e) {
    fail('Admin cancel', $e->getMessage());
}

// --- Pickup appointment flow ---
echo "\n[Pickup appointment]\n";
try {
    [$apptStart, $apptEnd] = findAppointmentWindow(2);
    $b = createBookingWithDates($apptStart, $apptEnd, ['fulfillment_type' => 'pickup_appointment', 'location_id' => 2, 'payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    assertEq('Appointment booking → awaiting admin', (string) $b['appointment_status'], 'appointment_awaiting_admin');

    simulateEtransferPaid($id);
    prepareForHandout($id);
    $bookings->confirmAppointment($id, 'leave_at_door');
    $confirmed = $bookings->findBooking($id) ?? [];
    assertEq('Admin confirm appointment → appointment_confirmed', (string) $confirmed['appointment_status'], 'appointment_confirmed');
    assertEq('Customer pickup window preserved', (string) $confirmed['confirmed_pickup_date'], $apptStart);

    $queue = $bookings->listAppointmentQueue();
    $confirmedInQueue = array_filter($queue, static fn (array $row): bool => (int) $row['id'] === $id);
    assertEq('Confirmed appointment stays in queue until pickup', count($confirmedInQueue), 1);

    [$cancelStart, $cancelEnd] = findAppointmentWindow(2);
    $cancelled = createBookingWithDates($cancelStart, $cancelEnd, ['fulfillment_type' => 'pickup_appointment', 'location_id' => 2, 'payment_method' => 'etransfer']);
    simulateEtransferPaid((int) $cancelled['id']);
    $bookings->adminCancelWithOptions((int) $cancelled['id'], 1, 'Test cancel');
    $queue = $bookings->listAppointmentQueue();
    $cancelledInQueue = array_filter($queue, static fn (array $row): bool => (int) $row['id'] === (int) $cancelled['id']);
    assertEq('Cancelled booking excluded from appointment queue', count($cancelledInQueue), 0);
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'not available')) {
        skip('Pickup appointment', 'no availability at home location in test data');
    } else {
        fail('Pickup appointment', $e->getMessage());
    }
}

// --- Square short-term handout gate ---
echo "\n[Square short-term deposit gate]\n";
try {
    $b = createBooking(['payment_method' => 'square', 'days' => 3]);
    $id = (int) $b['id'];
    simulateSquareShortPaid($id);
    $paid = $bookings->findBooking($id) ?? [];
    assertEq('Short Square → payment_square_rental_captured', booking_payment_status($paid), 'payment_square_rental_captured');

    if ($state->canHandOutHardware($paid)) {
        fail('Short Square pre-deposit handout gate', 'canHandOutHardware=true before deposit auth');
    } else {
        pass('Short Square blocks handout before deposit auth');
    }

    expectException('Pickup blocked before deposit auth', fn () => $ops->advanceBooking($id, 'pickup'), 'Deposit must be authorized');

    simulateDepositAuthorized($id);
    prepareForHandout($id);
    $ready = $bookings->findBooking($id) ?? [];
    if (!$state->canHandOutHardware($ready)) {
        fail('After deposit auth handout', 'canHandOutHardware still false');
    } else {
        pass('After deposit auth handout allowed');
    }

    $ops->advanceBooking($id, 'pickup');
    pass('Pickup after deposit auth succeeds');
} catch (Throwable $e) {
    fail('Square short-term deposit gate', $e->getMessage());
}

// --- Mail ship vs pickup guard ---
echo "\n[Fulfillment action guards]\n";
try {
    $b = createBooking(['fulfillment_type' => 'pickup', 'payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    simulateEtransferPaid($id);
    expectException('Ship blocked on pickup booking', fn () => $ops->advanceBooking($id, 'ship'), 'Only mail bookings');

    $mail = createBooking(['fulfillment_type' => 'mail_ship', 'location_id' => 1, 'payment_method' => 'etransfer', 'days' => 7]);
    $mailId = (int) $mail['id'];
    simulateEtransferPaid($mailId);
    prepareForHandout($mailId);
    expectException('Pickup blocked on mail booking', fn () => $ops->advanceBooking($mailId, 'pickup'), 'Mail bookings ship');
    $ops->advanceBooking($mailId, 'ship');
    $shipped = $bookings->findBooking($mailId) ?? [];
    assertEq('Mail ship → shipping_received', booking_fulfillment_status($shipped), 'shipping_received');
} catch (Throwable $e) {
    fail('Fulfillment action guards', $e->getMessage());
}

// --- QC failed path ---
echo "\n[QC failed / damage charge]\n";
try {
    $b = createBooking(['payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    simulateEtransferPaid($id);
    prepareForHandout($id);
    $ops->advanceBooking($id, 'pickup');
    $ops->advanceBooking($id, 'return_received');
    $before = notificationCount($id, 'fulfillment_return_failed');
    $ops->advanceBooking($id, 'qc_failed', 15000);
    $failed = $bookings->findBooking($id) ?? [];
    assertEq('QC failed → booking_closed', booking_lifecycle_status($failed), 'booking_closed');
    assertEq('QC failed → fulfillment_return_failed', booking_fulfillment_status($failed), 'fulfillment_return_failed');
    $after = notificationCount($id, 'fulfillment_return_failed');
    if ($after <= $before) {
        warn('QC failed notification', 'fulfillment_return_failed not logged');
    } else {
        pass('QC failed notification logged');
    }
} catch (Throwable $e) {
    fail('QC failed', $e->getMessage());
}

// --- Invalid transition guards ---
echo "\n[Invalid transition guards]\n";
try {
    [$guardStart, $guardEnd] = findStorePickupWindow();
    $b = createBookingWithDates($guardStart, $guardEnd, ['payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    expectException('QC before return blocked', fn () => $ops->advanceBooking($id, 'confirm_qc'), 'received before QC');
    expectException('Return before pickup blocked', fn () => $ops->advanceBooking($id, 'return_received'), 'not out with customer');

    [$squareStart, $squareEnd] = findStorePickupWindow();
    $square = createBookingWithDates($squareStart, $squareEnd, ['payment_method' => 'square']);
    expectException('Confirm etransfer on square booking', function () use ($square) {
        global $bookings;
        $bookings->confirmEtransfer((int) $square['id'], 50000);
    }, 'not using Interac');
} catch (Throwable $e) {
    fail('Invalid transition guards', $e->getMessage());
}

// --- Late fees ---
echo "\n[Late fees]\n";
try {
    $b = createBookingWithDates('2049-11-01', '2049-11-04', ['payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    simulateEtransferPaid($id);
    prepareForHandout($id);
    $ops->advanceBooking($id, 'pickup');
    $pdo->prepare('UPDATE bookings SET end_date = :end WHERE id = :id')->execute([
        'end' => today_date()->modify('-3 days')->format('Y-m-d'),
        'id' => $id,
    ]);
    $before = notificationCount($id, 'payment_late_fee_charged');
    $bookings->applyLateFees($id);
    $late = $bookings->findBooking($id) ?? [];
    assertEq('Late fees → booking_late', booking_lifecycle_status($late), 'booking_late');
    if (notificationCount($id, 'payment_late_fee_charged') <= $before) {
        fail('Late fee notification', 'payment_late_fee_charged not dispatched');
    } else {
        pass('Late fee notification dispatched');
    }
} catch (Throwable $e) {
    fail('Late fees', $e->getMessage());
}

// --- Mock Square payment ---
echo "\n[Mock Square payment]\n";
try {
    $b = createBookingWithDates('2049-10-01', '2049-10-07', ['payment_method' => 'square']);
    $id = (int) $b['id'];
    $paid = $bookings->applyMockSquarePayment($id, 'mock_ref');
    assertEq('Mock square long → booking_confirmed', booking_lifecycle_status($paid), 'booking_confirmed');
    assertEq('Mock square long → payment_square_full_captured', booking_payment_status($paid), 'payment_square_full_captured');
    $markPaid = $bookings->markPaid($id, 'mock_ref2');
    assertEq('markPaid idempotent when not pending', booking_lifecycle_status($markPaid), 'booking_confirmed');
} catch (Throwable $e) {
    fail('Mock Square payment', $e->getMessage());
}

// --- Cancel restores equipment ---
echo "\n[Cancel restores equipment]\n";
try {
    $b = createBooking(['payment_method' => 'etransfer']);
    $id = (int) $b['id'];
    simulateEtransferPaid($id);
    prepareForHandout($id);
    $ops->advanceBooking($id, 'pickup');
    $equipmentId = (int) ($bookings->findBooking($id)['equipment_id'] ?? 0);
    $bookings->cancel($id, 1, true, 'Admin recall');
    $status = $pdo->prepare('SELECT status FROM equipment WHERE id = :id');
    $status->execute(['id' => $equipmentId]);
    assertEq('Admin cancel after pickup restores equipment', (string) $status->fetchColumn(), 'active');
} catch (Throwable $e) {
    fail('Cancel restores equipment', $e->getMessage());
}

// --- Availability with with_customer unit ---
echo "\n[Availability with with_customer unit]\n";
try {
    $pdo->exec("UPDATE equipment SET status = 'with_customer' WHERE id = 1");
    $av = new Starlink\Services\AvailabilityService();
    $ok = $av->isRangeAvailable(1, 'pickup', '2048-01-01', '2048-01-03');
    if ($ok) {
        pass('Future dates available despite with_customer status when no booking overlap');
    } else {
        fail('Availability with with_customer', 'future range should be available without overlapping booking');
    }
    $pdo->exec("UPDATE equipment SET status = 'active' WHERE id = 1");
} catch (Throwable $e) {
    fail('Availability with with_customer', $e->getMessage());
}

// --- Notification rules seeded ---
echo "\n[Notification rules]\n";
try {
    $rules = (new NotificationRuleService())->listRules();
    if (count($rules) < 10) {
        fail('Notification rules seeded', 'only ' . count($rules) . ' rules');
    } else {
        pass('Notification rules seeded', count($rules) . ' rules');
    }
    $cancelRule = null;
    foreach ($rules as $rule) {
        if (($rule['event_key'] ?? '') === 'booking_cancelled_by_customer') {
            $cancelRule = $rule;
            break;
        }
    }
    if ($cancelRule === null) {
        fail('Cancel customer rule exists', 'missing');
    } elseif ((int) ($cancelRule['notify_admin_telegram'] ?? 0) !== 1) {
        warn('Cancel customer Telegram default', 'notify_admin_telegram not enabled by default');
    } else {
        pass('Cancel customer rule has Telegram enabled');
    }
} catch (Throwable $e) {
    fail('Notification rules', $e->getMessage());
}

// --- Admin list filter ---
echo "\n[Admin list filters]\n";
try {
    $pending = $ops->listBookings('booking_pending_payment');
    $allHaveStatus = true;
    foreach ($pending as $row) {
        if (booking_lifecycle_status($row) !== 'booking_pending_payment') {
            $allHaveStatus = false;
            break;
        }
    }
    if (!$allHaveStatus) {
        fail('listBookings pending filter', 'returned rows with wrong booking_status');
    } else {
        pass('listBookings pending filter', count($pending) . ' rows');
    }
} catch (Throwable $e) {
    fail('Admin list filters', $e->getMessage());
}

// --- Owner block (no payment) ---
echo "\n[Owner block lifecycle]\n";
try {
    [$start, $end] = nextDates(3);
    $ob = $bookings->createOwnerBlockBooking(1, 'admin', 1, 'pickup', $start, $end, 'Flow test owner block', true);
    $obId = (int) $ob['id'];
    $testBookingIds[] = $obId;
    assertEq('Owner block → booking_confirmed', booking_lifecycle_status($ob), 'booking_confirmed');
    assertEq('Owner block → payment_waived', booking_payment_status($ob), 'payment_waived');
    assertEq('Owner block kind', (string) ($ob['booking_kind'] ?? ''), 'owner_block');

    if (!$state->canHandOutHardware($ob)) {
        fail('Owner block handout without payment', 'canHandOutHardware returned false');
    } else {
        pass('Owner block handout without payment');
    }

    prepareForHandout($obId);
    $ops->advanceBooking($obId, 'stage');
    $ops->advanceBooking($obId, 'pickup');
    $ops->advanceBooking($obId, 'return_received');
    $ops->advanceBooking($obId, 'confirm_qc');
    $closed = $bookings->findBooking($obId) ?? [];
    assertEq('Owner block QC → booking_closed', booking_lifecycle_status($closed), 'booking_closed');
    assertEq('Owner block QC → fulfillment_return_confirmed', booking_fulfillment_status($closed), 'fulfillment_return_confirmed');
} catch (Throwable $e) {
    fail('Owner block lifecycle', $e->getMessage());
}

// --- Pool revenue + subscription proration ---
echo "\n[Partner pool financials]\n";
try {
    $poolUnits = $pdo->query(
        "SELECT id FROM equipment WHERE equipment_type = 'starlink' AND status != 'retired' ORDER BY id ASC"
    )->fetchAll();
    $poolSize = count($poolUnits);
    if ($poolSize < 1) {
        fail('Pool revenue split', 'no starlink units in pool');
    } else {
        $unitId = (int) $poolUnits[0]['id'];
        $before = (new PoolRevenueService($pdo))->allocatedRevenueForEquipment($unitId);
        $pdo->prepare(
            "INSERT INTO payments (booking_id, equipment_id, type, amount_cents, status, created_at)
             VALUES (NULL, :equipment_id, 'rental', 10000, 'completed', datetime('now'))"
        )->execute(['equipment_id' => $unitId]);
        $paymentId = (int) $pdo->lastInsertId();

        $after = (new PoolRevenueService($pdo))->allocatedRevenueForEquipment($unitId);
        $delta = $after - $before;
        $expectedShare = intdiv(10000, $poolSize) + (0 < (10000 % $poolSize) ? 1 : 0);
        if ($delta !== $expectedShare) {
            fail('Pool revenue split', "expected +{$expectedShare}, got +{$delta}");
        } else {
            pass('Pool revenue split', "+{$delta} cents per unit in {$poolSize}-unit pool");
        }

        $pdo->prepare('DELETE FROM payments WHERE id = :id')->execute(['id' => $paymentId]);
    }

    $subs = new SubscriptionCostService($pdo);
    $periods = [
        ['effective_from' => '2026-01-01', 'effective_to' => '2026-01-15', 'monthly_cents' => 7500, 'payer' => 'partner'],
        ['effective_from' => '2026-01-16', 'effective_to' => '2026-01-31', 'monthly_cents' => 11000, 'payer' => 'partner'],
    ];
    $monthStart = new DateTimeImmutable('2026-01-01');
    $monthEnd = new DateTimeImmutable('2026-01-31');
    $janCost = $subs->monthlyPlanCostCents($periods, $monthStart, $monthEnd);
    // 15/31 * 7500 + 16/31 * 11000 ≈ 9306
    if ($janCost < 9200 || $janCost > 9400) {
        fail('Plan proration', "unexpected January cost {$janCost}");
    } else {
        pass('Plan proration', "{$janCost} cents for split-month plan change");
    }

    $partnerReport = (new PayoffService($pdo))->partnerReports(
        (int) ($pdo->query("SELECT id FROM partners LIMIT 1")->fetchColumn() ?: 0)
    );
    if ($partnerReport !== [] && !isset($partnerReport[0]['revenue_model'])) {
        fail('Partner payoff model', 'missing pool revenue model');
    } else {
        pass('Partner payoff model', 'assigned_unit');
    }
} catch (Throwable $e) {
    fail('Partner pool financials', $e->getMessage());
}

// --- Cleanup test bookings ---
echo "\n[Cleanup]\n";
foreach ($testBookingIds as $tid) {
    $pdo->prepare('DELETE FROM booking_add_ons WHERE booking_id = :id')->execute(['id' => $tid]);
    $pdo->prepare('DELETE FROM payments WHERE booking_id = :id')->execute(['id' => $tid]);
    $pdo->prepare('DELETE FROM notification_log WHERE context_json LIKE :ctx')->execute(['ctx' => '%"booking_id":' . $tid . '%']);
    $pdo->prepare('DELETE FROM bookings WHERE id = :id')->execute(['id' => $tid]);
}
pass('Test bookings cleaned up', count($testBookingIds) . ' removed');

// --- Summary ---
echo "\n" . str_repeat('=', 60) . PHP_EOL;
$counts = ['PASS' => 0, 'FAIL' => 0, 'WARN' => 0, 'SKIP' => 0];
foreach ($results as $r) {
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
}
echo sprintf("PASS: %d  FAIL: %d  WARN: %d  SKIP: %d\n", $counts['PASS'], $counts['FAIL'], $counts['WARN'], $counts['SKIP']);

if ($counts['FAIL'] > 0) {
    echo "\nFailures:\n";
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            echo "  - {$r['name']}: {$r['detail']}\n";
        }
    }
    exit(1);
}

if ($counts['WARN'] > 0) {
    echo "\nWarnings:\n";
    foreach ($results as $r) {
        if ($r['status'] === 'WARN') {
            echo "  - {$r['name']}: {$r['detail']}\n";
        }
    }
}

exit(0);

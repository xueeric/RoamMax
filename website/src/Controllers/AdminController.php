<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use PDO;
use Starlink\Database\Connection;
use Starlink\Middleware\RequireRole;
use Starlink\Services\BookingService;
use Starlink\Services\BookingUnavailableException;
use Starlink\Services\SquareService;
use Starlink\Services\StagingService;

final class AdminController
{
    private readonly PDO $db;

    public function __construct(
        private readonly RequireRole $guard = new RequireRole(),
        ?PDO $db = null,
        private readonly SquareService $square = new SquareService(),
        private readonly StagingService $staging = new StagingService(),
        private readonly BookingService $bookings = new BookingService(),
    ) {
        $this->db = $db ?? Connection::get();
    }

    public function dashboard(): void
    {
        $user = $this->guard->handle(['admin']);

        $equipmentCount = (int) $this->db->query(
            "SELECT COUNT(*) FROM equipment WHERE equipment_type = 'starlink' AND status = 'active'"
        )->fetchColumn();
        $locationCount = (int) $this->db->query('SELECT COUNT(*) FROM locations WHERE is_active = 1')->fetchColumn();
        $partnerCount = (int) $this->db->query('SELECT COUNT(*) FROM partners')->fetchColumn();
        $scheduledStaging = (int) $this->db->query("SELECT COUNT(*) FROM staging_events WHERE status = 'scheduled'")->fetchColumn();
        $opsStats = (new \Starlink\Services\OperationsService())->dashboardStats();

        view('admin/dashboard', [
            'user' => $user,
            'stats' => array_merge([
                'equipment' => $equipmentCount,
                'locations' => $locationCount,
                'partners' => $partnerCount,
                'scheduled_staging' => $scheduledStaging,
            ], $opsStats),
            'squareConfigured' => $this->square->isConfigured(),
        ]);
    }

    public function equipment(): void
    {
        $user = $this->guard->handle(['admin']);
        $service = new \Starlink\Services\EquipmentService();

        view('admin/equipment', [
            'user' => $user,
            'equipment' => $service->withCommitments(\Starlink\Services\EquipmentService::TYPE_STARLINK),
            'citySummary' => $service->summaryByCity(),
        ]);
    }

    public function accessories(): void
    {
        $user = $this->guard->handle(['admin']);
        $service = new \Starlink\Services\EquipmentService();

        view('admin/accessories', [
            'user' => $user,
            'equipment' => $service->withCommitments(\Starlink\Services\EquipmentService::TYPE_ACCESSORY),
        ]);
    }

    public function blocks(): void
    {
        $user = $this->guard->handle(['admin']);
        $calendar = (new \Starlink\Services\BlockCalendarService())->context(allowLongTerm: true);

        view('admin/blocks', [
            'user' => $user,
            'reservations' => (new \Starlink\Services\BookingService())->listOwnerBlockBookings(),
            'calendarMode' => 'owner_block',
            'calendarSubmitUrl' => route_path('admin/blocks'),
            'calendarAllowLongTerm' => true,
            ...$calendar,
        ]);
    }

    public function createBlock(): void
    {
        $user = $this->guard->handle(['admin']);

        $locationId = (int) ($_POST['location_id'] ?? 0);
        $fulfillmentType = (string) ($_POST['fulfillment_type'] ?? 'pickup');
        $startDate = (string) ($_POST['start_date'] ?? '');
        $endDate = (string) ($_POST['end_date'] ?? '');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        try {
            $booking = (new \Starlink\Services\BookingService())->createOwnerBlockBooking(
                (int) $user['id'],
                'admin',
                $locationId,
                $fulfillmentType,
                $startDate,
                $endDate,
                $notes !== '' ? $notes : null,
                allowLongTerm: true,
            );
            \Starlink\Auth\Session::flash(
                'success',
                'Owner block ' . booking_reference($booking) . ' created — assign a unit when ready.',
            );
            redirect(route_path('admin/bookings/view') . '?booking_id=' . (int) $booking['id']);
        } catch (\Starlink\Services\BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
            redirect(route_path('admin/blocks'));
        }
    }

    public function staging(): void
    {
        $user = $this->guard->handle(['admin']);

        $events = $this->db->query(
            'SELECT s.*, e.nickname AS equipment_name, e.equipment_type,
                    lf.name AS from_name, lt.name AS to_name
             FROM staging_events s
             INNER JOIN equipment e ON e.id = s.equipment_id
             INNER JOIN locations lf ON lf.id = s.from_location_id
             INNER JOIN locations lt ON lt.id = s.to_location_id
             ORDER BY s.staging_date DESC, s.id DESC'
        )->fetchAll();

        $equipment = (new \Starlink\Services\EquipmentService())->allActiveForOps();

        $locations = $this->db->query(
            'SELECT id, name, slug FROM locations WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll();

        view('admin/staging', [
            'user' => $user,
            'events' => $events,
            'equipment' => $equipment,
            'locations' => $locations,
        ]);
    }

    public function scheduleStaging(): void
    {
        $user = $this->guard->handle(['admin']);

        $equipmentId = (int) ($_POST['equipment_id'] ?? 0);
        $toLocationId = (int) ($_POST['to_location_id'] ?? 0);
        $stagingDate = (string) ($_POST['staging_date'] ?? '');

        $equipment = $this->fetchEquipmentForStaging($equipmentId);
        if ($equipment === null || $toLocationId <= 0 || parse_date($stagingDate) === null) {
            \Starlink\Auth\Session::flash('error', 'Valid equipment, destination, and staging date are required.');
            redirect(route_path('admin/staging'));
        }

        $leadDays = $this->staging->stagingLeadDays($equipment, $toLocationId, 'pickup');
        if ($leadDays <= 0) {
            $leadDays = (int) pricing_config('staging_lead_days', (int) config('staging_lead_days', 1));
        }
        $readyDate = $this->staging->readyDateFromStaging(parse_date($stagingDate), $leadDays)->format('Y-m-d');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO staging_events (
                    equipment_id, from_location_id, to_location_id, staging_date, ready_date, status, created_at
                 ) VALUES (
                    :equipment_id, :from_location_id, :to_location_id, :staging_date, :ready_date, :status, :created_at
                 )'
            );
            $stmt->execute([
                'equipment_id' => $equipmentId,
                'from_location_id' => (int) $equipment['current_storage_location_id'],
                'to_location_id' => $toLocationId,
                'staging_date' => $stagingDate,
                'ready_date' => $readyDate,
                'status' => 'scheduled',
                'created_at' => now_utc(),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            \Starlink\Auth\Session::flash('error', 'Unable to schedule staging.');
            redirect(route_path('admin/staging'));
        }

        \Starlink\Auth\Session::flash('success', 'Staging scheduled.');
        redirect(route_path('admin/staging'));
    }

    public function swaps(): void
    {
        $this->guard->handle(['admin']);
        \Starlink\Auth\Session::flash(
            'success',
            'Swaps are retired. Use Blocks to hold dates on a unit — grab any kit for personal internet.',
        );
        redirect(route_path('admin/blocks'));
    }

    public function approveSwap(): void
    {
        $this->swaps();
    }

    public function appointments(): void
    {
        $user = $this->guard->handle(['admin']);

        view('admin/appointments', [
            'user' => $user,
            'appointments' => $this->bookings->listAppointmentQueue(),
        ]);
    }

    public function confirmAppointment(): void
    {
        $user = $this->guard->handle(['admin']);

        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $messageStyle = (string) ($_POST['message_style'] ?? 'home');
        $customMessage = trim((string) ($_POST['custom_message'] ?? ''));

        try {
            $this->bookings->confirmAppointment(
                $bookingId,
                $messageStyle,
                $customMessage !== '' ? $customMessage : null,
            );
            \Starlink\Auth\Session::flash('success', 'Pickup message sent and customer notified.');
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        redirect(route_path('admin/appointments'));
    }

    public function newBookingForm(): void
    {
        $user = $this->guard->handle(['admin']);

        $customers = $this->db->query(
            "SELECT u.id, u.name, u.email FROM users u
             INNER JOIN customers c ON c.user_id = u.id
             ORDER BY u.name ASC"
        )->fetchAll();

        $prefill = [
            'customer_id' => 0,
            'location_id' => 0,
            'fulfillment_type' => 'pickup',
            'start_date' => '',
            'end_date' => '',
            'customer_notes' => '',
        ];

        $requestId = (int) ($_GET['request_id'] ?? 0);
        if ($requestId > 0) {
            $request = (new \Starlink\Services\LongTermRequestService())->find($requestId);
            if ($request !== null) {
                foreach ($customers as $customer) {
                    if ((int) $customer['id'] === (int) $request['customer_id']) {
                        $prefill['customer_id'] = (int) $customer['id'];
                        break;
                    }
                }
                $prefill['location_id'] = (int) $request['location_id'];
                $prefill['fulfillment_type'] = (string) $request['fulfillment_type'];
                $prefill['start_date'] = (string) $request['start_date'];
                $prefill['end_date'] = (string) $request['end_date'];
                $prefill['customer_notes'] = (string) ($request['customer_notes'] ?? '');
            }
        }

        view('admin/booking-new', [
            'user' => $user,
            'customers' => $customers,
            'locations' => $this->db->query(
                'SELECT id, name FROM locations WHERE is_active = 1 AND location_type IN (\'store\', \'home\') ORDER BY name ASC'
            )->fetchAll(),
            'prefill' => $prefill,
        ]);
    }

    public function createBooking(): void
    {
        $user = $this->guard->handle(['admin']);

        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $fulfillmentType = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType((string) ($_POST['fulfillment_type'] ?? 'pickup'));
        $startDate = (string) ($_POST['start_date'] ?? '');
        $endDate = (string) ($_POST['end_date'] ?? '');
        $dailyRateCents = (int) round(((float) ($_POST['daily_rate'] ?? 0)) * 100);
        $rentalTotalCents = trim((string) ($_POST['rental_total'] ?? '')) !== ''
            ? (int) round(((float) $_POST['rental_total']) * 100)
            : null;
        $customerNotes = trim((string) ($_POST['customer_notes'] ?? ''));

        try {
            $booking = $this->bookings->createAdminBooking(
                (int) $user['id'],
                $customerId,
                $locationId,
                $fulfillmentType,
                $startDate,
                $endDate,
                $dailyRateCents,
                $rentalTotalCents,
                [],
                $customerNotes !== '' ? $customerNotes : null,
            );
            \Starlink\Auth\Session::flash('success', 'Admin booking #' . $booking['id'] . ' created (pending payment).');
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        redirect(route_path('admin/bookings/new'));
    }

    /** @return ?array<string, mixed> */
    private function fetchEquipmentForStaging(int $equipmentId): ?array
    {
        if ($equipmentId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT e.*, l.city AS storage_city
             FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             WHERE e.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $equipmentId]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return ?array<string, mixed> */
    private function fetchEquipment(int $equipmentId): ?array
    {
        if ($equipmentId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM equipment WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $equipmentId]);

        $row = $stmt->fetch();
        return $row ?: null;
    }
}

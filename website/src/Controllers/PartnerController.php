<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use PDO;
use Starlink\Auth\AuthService;
use Starlink\Database\Connection;
use Starlink\Middleware\RequireRole;
use Starlink\Services\BlockCalendarService;
use Starlink\Services\BookingUnavailableException;
use Starlink\Services\PartnerPersonalBookingService;
use Starlink\Services\PayoffService;

final class PartnerController
{
    private readonly PDO $db;
    private readonly AuthService $auth;

    public function __construct(
        private readonly RequireRole $guard = new RequireRole(),
        ?AuthService $auth = null,
        ?PDO $db = null,
    ) {
        $this->auth = $auth ?? new AuthService();
        $this->db = $db ?? Connection::get();
    }

    public function dashboard(): void
    {
        $user = $this->guard->handle(['partner']);
        $partnerId = $this->auth->partnerId();

        if ($partnerId === null) {
            $this->partnerMissing($user);
            return;
        }

        $personal = new PartnerPersonalBookingService();
        $calendar = (new BlockCalendarService())->context(allowLongTerm: false);

        view('partner/dashboard', [
            'user' => $user,
            'equipment' => $this->fetchPartnerEquipment($partnerId),
            'reports' => (new PayoffService())->partnerReports($partnerId),
            'personalBookings' => $personal->listBorrowerBookings((int) $user['id']),
            'lentBookings' => $personal->listLenderBookings($partnerId),
            'borrowPolicyMessage' => PartnerPersonalBookingService::BORROW_POLICY_MESSAGE,
            'calendarMode' => 'partner_personal',
            'calendarSubmitUrl' => route_path('partner/blocks'),
            'calendarAllowLongTerm' => false,
            ...$calendar,
        ]);
    }

    public function personalQuote(): void
    {
        $user = $this->guard->handle(['partner']);
        $partnerId = $this->auth->partnerId();
        if ($partnerId === null) {
            json_response(['error' => 'Partner profile not found.'], 403);
            return;
        }

        $locationId = (int) ($_GET['location_id'] ?? 0);
        $fulfillmentType = (string) ($_GET['fulfillment_type'] ?? 'pickup');
        $startDate = (string) ($_GET['start_date'] ?? $_GET['start'] ?? '');
        $endDate = (string) ($_GET['end_date'] ?? $_GET['end'] ?? '');

        try {
            json_response((new PartnerPersonalBookingService())->quote(
                $partnerId,
                $locationId,
                $fulfillmentType,
                $startDate,
                $endDate,
            ));
        } catch (BookingUnavailableException $e) {
            json_response(['error' => $e->getMessage(), 'available' => false], 422);
        }
    }

    public function equipment(): void
    {
        $this->guard->handle(['partner']);
        redirect(route_path('partner') . '#units');
    }

    public function financials(): void
    {
        $this->guard->handle(['partner']);
        redirect(route_path('partner') . '#financials');
    }

    public function blocks(): void
    {
        $this->guard->handle(['partner']);
        redirect(route_path('partner') . '#personal-use');
    }

    public function createBlock(): void
    {
        $user = $this->guard->handle(['partner']);
        $partnerId = $this->auth->partnerId();
        if ($partnerId === null) {
            $this->partnerMissing($user);
            return;
        }

        $locationId = (int) ($_POST['location_id'] ?? 0);
        $fulfillmentType = (string) ($_POST['fulfillment_type'] ?? 'pickup');
        $startDate = (string) ($_POST['start_date'] ?? '');
        $endDate = (string) ($_POST['end_date'] ?? '');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        try {
            $booking = (new PartnerPersonalBookingService())->create(
                $partnerId,
                (int) $user['id'],
                $locationId,
                $fulfillmentType,
                $startDate,
                $endDate,
                $notes !== '' ? $notes : null,
            );
            $fee = (int) ($booking['partner_borrow_fee_cents'] ?? 0);
            if ($fee > 0) {
                \Starlink\Auth\Session::flash(
                    'success',
                    'Personal trip ' . booking_reference($booking) . ' reserved — pay '
                    . \Starlink\Services\PricingService::formatMoney($fee)
                    . ' borrow fee (' . (int) ($booking['partner_conflict_days'] ?? 0) . ' conflict day(s)) before pickup.',
                );
            } else {
                \Starlink\Auth\Session::flash(
                    'success',
                    'Personal trip ' . booking_reference($booking) . ' confirmed on your unit — no borrow fee.',
                );
            }
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        redirect(route_path('partner') . '#personal-use');
    }

    public function swaps(): void
    {
        $this->guard->handle(['partner']);
        redirect(route_path('partner') . '#personal-use');
    }

    public function requestSwap(): void
    {
        $this->swaps();
    }

    /** @return list<array<string, mixed>> */
    private function fetchPartnerEquipment(int $partnerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, l.name AS location_name, l.slug AS location_slug, l.location_type,
                    sp.label AS plan_label, sp.data_gb, sp.monthly_cents AS plan_monthly_cents
             FROM equipment e
             INNER JOIN locations l ON l.id = e.current_storage_location_id
             LEFT JOIN starlink_plans sp ON sp.slug = e.data_plan
             WHERE e.partner_id = :partner_id
             ORDER BY e.id ASC'
        );
        $stmt->execute(['partner_id' => $partnerId]);

        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $user */
    private function partnerMissing(array $user): void
    {
        http_response_code(500);
        view('errors/forbidden', [
            'user' => $user,
            'message' => 'Partner profile not found for this account.',
        ]);
    }
}

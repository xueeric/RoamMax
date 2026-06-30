<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use Starlink\Auth\AuthService;
use Starlink\Services\BookingService;
use Starlink\Services\BookingUnavailableException;
use Starlink\Services\CustomerBookingPresenter;
use Starlink\Services\CustomerProfileService;
use Starlink\Services\LongTermRequestService;
use Starlink\Services\PaymentService;
use Starlink\Services\PricingService;
use Starlink\Services\SquareService;

final class AccountController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly BookingService $bookings = new BookingService(),
        private readonly CustomerProfileService $profiles = new CustomerProfileService(),
        private readonly LongTermRequestService $longTermRequests = new LongTermRequestService(),
        private readonly PaymentService $payments = new PaymentService(),
    ) {
    }

    public function profile(): void
    {
        $user = $this->requireCustomer();

        view('customer/profile', [
            'user' => $user,
            'profile' => $this->profiles->getProfile((int) $user['id']),
        ]);
    }

    public function saveProfile(): void
    {
        $user = $this->requireCustomer();

        try {
            $this->profiles->updateProfile((int) $user['id'], $_POST);
            \Starlink\Auth\Session::flash('success', 'Profile saved.');
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        redirect(route_path('account/profile'));
    }

    /** @return array<string, mixed> */
    private function requireCustomer(): array
    {
        $user = $this->auth->user();
        if ($user === null || ($user['role'] ?? '') !== 'customer') {
            redirect(route_path('login'));
        }

        return $user;
    }

    public function bookings(): void
    {
        $user = $this->requireCustomer();
        $customerId = (int) $user['id'];

        $bookings = $this->bookings->listCustomerBookings($customerId);
        $longTermRequests = $this->longTermRequests->listForCustomer($customerId);

        $entries = [];
        foreach ($bookings as $booking) {
            $entries[] = [
                'type' => 'booking',
                'created_at' => (string) ($booking['created_at'] ?? ''),
                'data' => $booking,
            ];
        }
        foreach ($longTermRequests as $request) {
            $entries[] = [
                'type' => 'long_term_request',
                'created_at' => (string) ($request['created_at'] ?? ''),
                'data' => $request,
            ];
        }

        usort(
            $entries,
            static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']),
        );

        view('customer/bookings', [
            'user' => $user,
            'entries' => $entries,
        ]);
    }

    public function bookingDetail(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            redirect(route_path('login'));
        }

        $bookingId = (int) ($_GET['booking_id'] ?? 0);
        $booking = $this->bookings->findCustomerBookingDetail($bookingId, (int) $user['id']);
        if ($booking === null) {
            http_response_code(404);
            view('errors/not-found');
            return;
        }

        $presenter = new CustomerBookingPresenter();
        $paymentRows = $booking['payments'] ?? [];
        unset($booking['payments']);

        view('customer/booking-detail', [
            'user' => $user,
            'booking' => $booking,
            'pickup' => $presenter->pickupSection($booking),
            'wifi' => $presenter->wifiSection($booking),
            'paymentTimeline' => $presenter->paymentTimeline($booking, $paymentRows),
            'cancelPreview' => $presenter->cancelPreview($booking),
            'canCancel' => (new \Starlink\Services\BookingStateService())->canCustomerCancel($booking),
        ]);
    }

    public function acceptProposal(): void
    {
        $user = $this->requireCustomer();

        $bookingId = (int) ($_POST['booking_id'] ?? 0);

        try {
            $this->bookings->acceptProposal($bookingId, (int) $user['id']);
            \Starlink\Auth\Session::flash('success', 'Pickup window accepted.');
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        redirect(route_path('account/bookings/view') . '?booking_id=' . $bookingId);
    }

    public function pay(): void
    {
        $user = $this->requireCustomer();

        $bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
        $booking = $this->bookings->findBooking($bookingId);
        if ($booking === null || (int) $booking['customer_id'] !== (int) $user['id']) {
            http_response_code(404);
            view('errors/not-found');
            return;
        }

        if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
            \Starlink\Auth\Session::flash('error', 'This booking is not awaiting payment.');
            redirect(route_path('account/bookings'));
        }

        if (is_post()) {
            $method = (string) ($_POST['payment_method'] ?? '');
            try {
                $booking = $this->bookings->setPaymentMethod($bookingId, (int) $user['id'], $method);
            } catch (BookingUnavailableException $e) {
                \Starlink\Auth\Session::flash('error', $e->getMessage());
                redirect(route_path('account/bookings/pay') . '?booking_id=' . $bookingId);
            }

            $checkout = $this->payments->startCheckout($booking, $this->bookings);
            if (!$checkout['ok']) {
                \Starlink\Auth\Session::flash('error', $checkout['message'] ?? 'Unable to start checkout.');
                redirect(route_path('account/bookings'));
            }

            redirect($checkout['url']);
        }

        $method = (string) ($booking['payment_method'] ?? '');
        $changeMethod = isset($_GET['change']);
        if ($method !== '' && !$changeMethod && !is_post()) {
            $checkout = $this->payments->startCheckout($booking, $this->bookings);
            if ($checkout['ok']) {
                redirect($checkout['url']);
            }
        }

        view('customer/payment-method', [
            'user' => $user,
            'booking' => $booking,
            'totalDue' => PricingService::formatMoney($this->bookings->totalDueCents($booking)),
            'squareAvailable' => $this->payments->isSquareAvailable(),
            'etransferEmail' => $this->payments->etransferEmail(),
        ]);
    }

    public function cancel(): void
    {
        $user = $this->requireCustomer();

        $bookingId = (int) ($_POST['booking_id'] ?? 0);

        try {
            $updated = $this->bookings->requestCancellation($bookingId, (int) $user['id']);
            $lifecycle = booking_lifecycle_status($updated);
            if ($lifecycle === 'booking_cancellation_pending') {
                $refund = (int) ($updated['cancellation_refund_cents'] ?? 0);
                $msg = 'Cancellation request submitted. Our team will review it';
                if ($refund > 0) {
                    $msg .= ' — estimated refund ' . PricingService::formatMoney($refund) . ' after approval';
                }
                $msg .= '.';
                \Starlink\Auth\Session::flash('success', $msg);
            } else {
                \Starlink\Auth\Session::flash('success', 'Booking cancelled.');
            }
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        redirect(route_path('account/bookings/view') . '?booking_id=' . $bookingId);
    }
}

<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use PDO;
use Starlink\Auth\AuthService;
use Starlink\Database\Connection;
use Starlink\Services\AddressService;
use Starlink\Services\AgreementService;
use Starlink\Services\AvailabilityService;
use Starlink\Middleware\RequireRole;
use Starlink\Services\MigrationService;
use Starlink\Services\BookingService;
use Starlink\Services\BookingUnavailableException;
use Starlink\Services\CustomerProfileService;
use Starlink\Services\EquipmentService;
use Starlink\Services\LongTermRequestService;
use Starlink\Services\PaymentService;
use Starlink\Services\PricingService;
use Starlink\Services\SquareDepositService;
use Starlink\Services\SquareService;

final class BookingController
{
    private readonly PDO $db;

    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly BookingService $bookings = new BookingService(),
        private readonly PricingService $pricing = new PricingService(),
        private readonly AvailabilityService $availability = new AvailabilityService(),
        private readonly SquareService $square = new SquareService(),
        private readonly SquareDepositService $squareDeposits = new SquareDepositService(),
        private readonly PaymentService $payments = new PaymentService(),
        private readonly LongTermRequestService $longTermRequests = new LongTermRequestService(),
        private readonly CustomerProfileService $profiles = new CustomerProfileService(),
        private readonly AgreementService $agreements = new AgreementService(),
        private readonly MigrationService $migrations = new MigrationService(),
        private readonly RequireRole $guard = new RequireRole(),
        ?PDO $db = null,
    ) {
        $this->db = $db ?? Connection::get();
    }

    public function quote(): void
    {
        $locationId = (int) ($_GET['location_id'] ?? 0);
        $fulfillmentType = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType((string) ($_GET['fulfillment_type'] ?? 'pickup'));
        $startDate = (string) ($_GET['start_date'] ?? '');
        $endDate = (string) ($_GET['end_date'] ?? '');
        $addOnQuantities = $this->parseAddOnQuantities($_GET['addons'] ?? []);
        $taxProvince = trim((string) ($_GET['tax_province'] ?? ''));
        $paymentMethod = $this->payments->normalizeMethod((string) ($_GET['payment_method'] ?? ''));

        try {
            $quote = $this->pricing->quote(
                $locationId,
                $fulfillmentType,
                $startDate,
                $endDate,
                $addOnQuantities,
                false,
                null,
                null,
                $taxProvince !== '' ? $taxProvince : null,
                null,
                $paymentMethod,
            );

            if ($startDate !== '' && $endDate !== '') {
                $availabilityLocationId = $fulfillmentType === 'mail_ship' ? 0 : $locationId;
                $quote['range_available'] = $this->availability->isRangeAvailable(
                    $availabilityLocationId,
                    $fulfillmentType,
                    $startDate,
                    $endDate,
                );
            }

            json_response(PricingService::formatQuote($quote));
        } catch (BookingUnavailableException $e) {
            json_response(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if (config('debug')) {
                error_log('Quote failed: ' . $e->getMessage());
            }
            json_response(['error' => 'Unable to calculate price. Please try again.'], 500);
        }
    }

    public function checkoutForm(): void
    {
        $locationId = (int) ($_GET['location_id'] ?? 0);
        $fulfillmentType = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType((string) ($_GET['fulfillment_type'] ?? 'pickup'));
        $startDate = (string) ($_GET['start_date'] ?? '');
        $endDate = (string) ($_GET['end_date'] ?? '');

        $checkoutReturn = $this->buildCheckoutReturn($locationId, $fulfillmentType, $startDate, $endDate);

        $user = $this->auth->user();
        if ($user !== null && ($user['role'] ?? '') !== 'customer') {
            \Starlink\Auth\Session::flash(
                'error',
                'Customer checkout requires a customer account. Sign out and sign in with a customer account to book.',
            );
            redirect(route_path('/') . '?' . http_build_query(array_filter([
                'location_id' => $locationId > 0 ? $locationId : null,
                'fulfillment_type' => $fulfillmentType !== '' ? $fulfillmentType : null,
                'start_date' => $startDate !== '' ? $startDate : null,
                'end_date' => $endDate !== '' ? $endDate : null,
            ], static fn ($value): bool => $value !== null)) . '#book');
        }

        if ($user === null) {
            $prepared = $this->prepareCheckout($locationId, $fulfillmentType, $startDate, $endDate, null);
            view('customer/checkout-gate', [
                'user' => null,
                'location' => $prepared['location'],
                'fulfillmentType' => $fulfillmentType,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'quote' => $prepared['quote'],
                'checkoutReturn' => $checkoutReturn,
            ]);
            return;
        }

        $profile = $this->profiles->getProfile((int) $user['id']);
        $defaultPaymentMethod = $this->payments->isSquareAvailable() ? 'square' : 'etransfer';
        $prepared = $this->prepareCheckout(
            $locationId,
            $fulfillmentType,
            $startDate,
            $endDate,
            $profile,
            $defaultPaymentMethod,
        );
        $agreement = $this->agreements->render();

        view('customer/checkout', [
            'user' => $user,
            'profile' => $profile,
            'location' => $prepared['location'],
            'fulfillmentType' => $fulfillmentType,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'quote' => $prepared['quote'],
            'addOns' => $prepared['addOns'],
            'squareAvailable' => $this->payments->isSquareAvailable(),
            'etransferEmail' => $this->payments->etransferEmail(),
            'agreementHtml' => $agreement['html'],
            'agreementVersion' => $agreement['version'],
        ]);
    }

    public function checkoutSubmit(): void
    {
        $user = $this->auth->user();
        if ($user === null || ($user['role'] ?? '') !== 'customer') {
            redirect(route_path('login'));
        }

        $locationId = (int) ($_POST['location_id'] ?? 0);
        $fulfillmentType = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType((string) ($_POST['fulfillment_type'] ?? 'pickup'));
        $startDate = (string) ($_POST['start_date'] ?? '');
        $endDate = (string) ($_POST['end_date'] ?? '');
        $customerNotes = trim((string) ($_POST['customer_notes'] ?? ''));
        $pickupDate = trim((string) ($_POST['pickup_date'] ?? ''));
        $pickupTime = trim((string) ($_POST['pickup_time'] ?? ''));
        $agreementAccepted = isset($_POST['agreement_accepted']);
        $addOnQuantities = $this->parseAddOnQuantities($_POST['addons'] ?? []);
        $saveProfile = isset($_POST['save_profile']);
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');
        $requiresPayment = true;

        $profileInput = [
            'name' => trim((string) ($_POST['contact_name'] ?? $user['name'] ?? '')),
            'phone' => trim((string) ($_POST['contact_phone'] ?? '')),
            'company_name' => trim((string) ($_POST['company_name'] ?? '')),
            'home_line1' => $_POST['home_line1'] ?? '',
            'home_line2' => $_POST['home_line2'] ?? '',
            'home_city' => $_POST['home_city'] ?? '',
            'home_province' => $_POST['home_province'] ?? '',
            'home_postal_code' => $_POST['home_postal_code'] ?? '',
            'home_country' => $_POST['home_country'] ?? 'CA',
            'billing_line1' => $_POST['billing_line1'] ?? '',
            'billing_line2' => $_POST['billing_line2'] ?? '',
            'billing_city' => $_POST['billing_city'] ?? '',
            'billing_province' => $_POST['billing_province'] ?? '',
            'billing_postal_code' => $_POST['billing_postal_code'] ?? '',
            'billing_country' => $_POST['billing_country'] ?? 'CA',
        ];

        if (in_array($fulfillmentType, ['mail_ship', 'city_delivery'], true)) {
            $profileInput['shipping_name'] = $_POST['shipping_name'] ?? '';
            $profileInput['shipping_line1'] = $_POST['shipping_line1'] ?? '';
            $profileInput['shipping_line2'] = $_POST['shipping_line2'] ?? '';
            $profileInput['shipping_city'] = $_POST['shipping_city'] ?? '';
            $profileInput['shipping_province'] = $_POST['shipping_province'] ?? '';
            $profileInput['shipping_postal_code'] = $_POST['shipping_postal_code'] ?? '';
            $profileInput['shipping_country'] = $_POST['shipping_country'] ?? 'CA';
        }

        $homeAddress = $this->profiles->parseAddressInput($profileInput, 'home_');
        $billingAddress = $this->profiles->parseAddressInput($profileInput, 'billing_');
        $companyName = trim((string) ($_POST['company_name'] ?? ''));

        $shippingAddress = null;
        if (in_array($fulfillmentType, ['mail_ship', 'city_delivery'], true)) {
            $shippingAddress = $this->profiles->parseAddressInput($profileInput, 'shipping_');
            $shippingAddress['name'] = trim((string) ($_POST['shipping_name'] ?? $profileInput['name'] ?? ''));
        }

        if ($saveProfile) {
            try {
                $this->profiles->saveFromCheckout((int) $user['id'], $profileInput);
            } catch (BookingUnavailableException $e) {
                \Starlink\Auth\Session::flash('error', $e->getMessage());
                redirect(route_path('checkout') . '?' . http_build_query([
                    'location_id' => $locationId,
                    'fulfillment_type' => $fulfillmentType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]));
            }
        }

        if ($requiresPayment && $this->payments->normalizeMethod($paymentMethod) === null) {
            \Starlink\Auth\Session::flash('error', 'Choose a payment method.');
            redirect(route_path('checkout') . '?' . http_build_query([
                'location_id' => $locationId,
                'fulfillment_type' => $fulfillmentType,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]));
        }

        if ($requiresPayment && $paymentMethod === 'square' && !$this->payments->isSquareAvailable()) {
            \Starlink\Auth\Session::flash('error', 'Card payment is temporarily unavailable. Choose Interac e-Transfer instead.');
            redirect(route_path('checkout') . '?' . http_build_query([
                'location_id' => $locationId,
                'fulfillment_type' => $fulfillmentType,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]));
        }

        $start = parse_date($startDate);
        $end = parse_date($endDate);
        if ($start !== null && $end !== null && $paymentMethod === 'square') {
            $days = inclusive_day_count($start, $end);
            $shortMax = (int) config('payments.square_short_max_rental_days', 4);
            $longMin = (int) config('payments.square_long_term_min_days', 5);
            if ($days > $shortMax && $days < $longMin) {
                \Starlink\Auth\Session::flash('error', 'Square card bookings must be ' . $shortMax . ' days or less, or ' . $longMin . ' days or more.');
                redirect(route_path('checkout') . '?' . http_build_query([
                    'location_id' => $locationId,
                    'fulfillment_type' => $fulfillmentType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]));
            }
        }

        try {
            $agreement = $this->agreements->render();
            $booking = $this->bookings->createCustomerBooking(
                (int) $user['id'],
                $locationId,
                $fulfillmentType,
                $startDate,
                $endDate,
                $addOnQuantities,
                $customerNotes !== '' ? $customerNotes : null,
                $shippingAddress,
                $agreementAccepted,
                $homeAddress,
                $billingAddress,
                $companyName !== '' ? $companyName : null,
                $requiresPayment ? $paymentMethod : null,
                $pickupDate !== '' ? $pickupDate : null,
                $pickupTime !== '' ? $pickupTime : null,
                null,
                request_client_ip(),
                $agreement['version'],
                $agreement['text'],
            );
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
            redirect(route_path('checkout') . '?' . http_build_query([
                'location_id' => $locationId,
                'fulfillment_type' => $fulfillmentType,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]));
        }

        $this->redirectToPayment($booking);
    }

    public function etransferPay(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            redirect(route_path('login'));
        }

        $bookingId = (int) ($_GET['booking_id'] ?? 0);
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

        if (($booking['payment_method'] ?? '') !== 'etransfer') {
            redirect(route_path('account/bookings/pay') . '?booking_id=' . $bookingId);
        }

        if (is_post()) {
            try {
                $this->bookings->acknowledgeEtransfer($bookingId, (int) $user['id']);
                \Starlink\Auth\Session::flash('success', 'Thanks — we will confirm your booking once your e-Transfer arrives.');
            } catch (BookingUnavailableException $e) {
                \Starlink\Auth\Session::flash('error', $e->getMessage());
            }
            redirect(route_path('booking/etransfer') . '?booking_id=' . $bookingId);
        }

        view('customer/etransfer-pay', [
            'user' => $user,
            'booking' => $booking,
            'totalDue' => PricingService::formatMoney($this->bookings->totalDueCents($booking)),
            'etransferEmail' => $this->payments->etransferEmail(),
            'etransferMemo' => $this->payments->etransferMemo($booking),
        ]);
    }

    /** @param array<string, mixed> $booking */
    private function redirectToPayment(array $booking): void
    {
        $checkout = $this->payments->startCheckout($booking, $this->bookings);
        if (!$checkout['ok']) {
            \Starlink\Auth\Session::flash('error', $checkout['message'] ?? 'Unable to start checkout.');
            redirect(route_path('account/bookings'));
        }

        redirect($checkout['url']);
    }

    public function success(): void
    {
        $bookingId = (int) ($_GET['booking_id'] ?? 0);
        $user = $this->auth->user();
        $booking = null;

        if ($bookingId > 0) {
            $booking = $this->bookings->findBooking($bookingId);
            if ($booking === null) {
                http_response_code(404);
                view('errors/not-found');
                return;
            }

            if ($user === null || (int) $booking['customer_id'] !== (int) $user['id']) {
                http_response_code(403);
                view('errors/forbidden', [
                    'message' => 'Sign in to view this booking confirmation.',
                ]);
                return;
            }
        }

        view('customer/booking-success', [
            'user' => $user,
            'booking' => $booking,
        ]);
    }

    public function cardPay(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            redirect(route_path('login'));
        }

        $bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
        $booking = $this->bookings->findBooking($bookingId);
        if ($booking === null || (int) $booking['customer_id'] !== (int) $user['id']) {
            http_response_code(404);
            view('errors/not-found');
            return;
        }

        if (($booking['payment_method'] ?? '') !== 'square') {
            redirect(route_path('account/bookings'));
        }

        if (booking_lifecycle_status($booking) !== 'booking_pending_payment') {
            redirect(route_path('booking/success') . '?booking_id=' . $bookingId);
        }

        if (is_post()) {
            $sourceId = trim((string) ($_POST['source_id'] ?? ''));
            if ($sourceId === '') {
                json_response(['ok' => false, 'message' => 'Missing payment token.'], 422);
            }

            $booking = $this->bookings->findBooking($bookingId) ?? $booking;

            try {
                $result = $this->squareDeposits->processCardCheckout($booking, $sourceId);
            } catch (\Throwable $e) {
                if (config('debug')) {
                    error_log('Square pay-card failed: ' . $e->getMessage());
                }
                json_response(['ok' => false, 'message' => 'Payment could not be completed. Please try again.'], 500);
            }

            if (!$result['ok']) {
                json_response(['ok' => false, 'message' => $result['message'] ?? 'Payment failed.'], 422);
            }

            json_response(['ok' => true, 'redirect' => $result['redirect'] ?? route_path('booking/success') . '?booking_id=' . $bookingId]);
        }

        $squareCard = $this->squareCardConfig($booking);
        $credentialsError = $this->square->webCredentialsEnvironmentError();
        if ($credentialsError === null && !$this->payments->isSquareAvailable()) {
            $credentialsError = 'Square is not configured on this server. Set SQUARE_ACCESS_TOKEN, SQUARE_APPLICATION_ID, and SQUARE_LOCATION_ID in .env.';
        }

        view('customer/square-pay', [
            'user' => $user,
            'booking' => $booking,
            'isShortTerm' => $this->bookings->isShortTermRental($booking),
            'chargeCents' => $this->bookings->isShortTermRental($booking)
                ? $this->bookings->rentalChargeCents($booking)
                : $this->bookings->totalDueCents($booking),
            'depositCents' => (int) $booking['deposit_cents'],
            'squareApplicationId' => $this->square->applicationId(),
            'squareSdkUrl' => $this->square->webPaymentsSdkUrl(),
            'squareLocationId' => config('square.location_id'),
            'squareCredentialsError' => $credentialsError,
            'squareIsSandbox' => $this->square->isSandbox(),
            'squareCard' => $squareCard,
        ]);
    }

    public function updateCard(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            redirect(route_path('login'));
        }

        $bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
        $booking = $this->bookings->findBooking($bookingId);
        if ($booking === null || (int) $booking['customer_id'] !== (int) $user['id']) {
            http_response_code(404);
            view('errors/not-found');
            return;
        }

        if (booking_payment_status($booking) !== 'payment_deposit_scheduled_failed') {
            redirect(route_path('account/bookings'));
        }

        if (!$this->payments->isSquareAvailable()) {
            \Starlink\Auth\Session::flash('error', 'Card update is unavailable — Square is not configured.');
            redirect(route_path('account/bookings'));
        }

        if (is_post()) {
            $sourceId = trim((string) ($_POST['source_id'] ?? ''));
            if ($sourceId === '') {
                json_response(['ok' => false, 'message' => 'Missing payment token.'], 422);
            }

            $booking = $this->bookings->findBooking($bookingId) ?? $booking;

            try {
                $result = $this->squareDeposits->updateCardAndRetryDeposit($booking, $sourceId);
            } catch (\Throwable $e) {
                if (config('debug')) {
                    error_log('Square update-card failed: ' . $e->getMessage());
                }
                json_response(['ok' => false, 'message' => 'Unable to update card. Please try again.'], 500);
            }

            if (!$result['ok']) {
                json_response(['ok' => false, 'message' => $result['message'] ?? 'Unable to update card.'], 422);
            }

            json_response(['ok' => true, 'redirect' => $result['redirect'] ?? route_path('booking/success') . '?booking_id=' . $bookingId]);
        }

        $squareCard = $this->squareCardConfig($booking);

        view('customer/update-card', [
            'user' => $user,
            'booking' => $booking,
            'depositCents' => (int) $booking['deposit_cents'],
            'squareApplicationId' => $this->square->applicationId(),
            'squareSdkUrl' => $this->square->webPaymentsSdkUrl(),
            'squareLocationId' => config('square.location_id'),
            'squareCard' => $squareCard,
        ]);
    }

    /** @param array<string, mixed> $booking */
    /** @return array{locale: string, billingPostalCode: ?string, sandboxDefaultPostal: ?string, isSandbox: bool} */
    private function squareCardConfig(array $booking): array
    {
        $billing = AddressService::decode($booking['billing_address_json'] ?? null);
        $postal = strtoupper(str_replace(' ', '', trim((string) ($billing['postal_code'] ?? ''))));

        return [
            'locale' => 'en-CA',
            'billingPostalCode' => $postal !== '' ? $postal : null,
            'sandboxDefaultPostal' => $this->square->isSandbox() ? '94103' : null,
            'isSandbox' => $this->square->isSandbox(),
        ];
    }

    public function agreement(): void
    {
        $user = $this->auth->user();
        $agreement = $this->agreements->render();
        $isAdmin = ($user['role'] ?? '') === 'admin';
        view('customer/agreement', [
            'user' => $user,
            'agreementHtml' => $agreement['html'],
            'agreementVersion' => $agreement['version'],
            'isAdmin' => $isAdmin,
            'pendingMigrations' => $isAdmin ? $this->migrations->pending() : [],
        ]);
    }

    public function agreementMigrate(): void
    {
        $this->guard->handle(['admin']);

        $result = $this->migrations->runPending();

        if ($result['failed'] !== null) {
            \Starlink\Auth\Session::flash('error', 'Migration failed: ' . $result['failed']);
            redirect(route_path('agreement'));
        }

        if ($result['applied'] === []) {
            \Starlink\Auth\Session::flash('success', 'Database is already up to date — no pending migrations.');
        } else {
            $names = implode(', ', $result['applied']);
            \Starlink\Auth\Session::flash('success', 'Applied ' . count($result['applied']) . ' migration(s): ' . $names . '.');
        }

        redirect(route_path('agreement'));
    }

    public function longTermRequestSubmit(): void
    {
        $user = $this->auth->user();
        if ($user === null || ($user['role'] ?? '') !== 'customer') {
            \Starlink\Auth\Session::flash('error', 'Sign in with a customer account to submit a long-term request.');
            redirect(route_path('login'));
        }

        $locationId = (int) ($_POST['location_id'] ?? 0);
        $fulfillmentType = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType((string) ($_POST['fulfillment_type'] ?? 'pickup'));
        $startDate = (string) ($_POST['start_date'] ?? '');
        $endDate = (string) ($_POST['end_date'] ?? '');
        $customerNotes = trim((string) ($_POST['customer_notes'] ?? ''));

        try {
            $request = $this->longTermRequests->create(
                (int) $user['id'],
                $locationId,
                $fulfillmentType,
                $startDate,
                $endDate,
                $customerNotes !== '' ? $customerNotes : null,
            );
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
            redirect(route_path('/') . '#book');
        }

        redirect(route_path('book/long-term-request/success') . '?request_id=' . (int) $request['id']);
    }

    public function longTermRequestSuccess(): void
    {
        $user = $this->auth->user();
        if ($user === null || ($user['role'] ?? '') !== 'customer') {
            redirect(route_path('login'));
        }

        $requestId = (int) ($_GET['request_id'] ?? 0);
        $request = $requestId > 0 ? $this->longTermRequests->find($requestId) : null;
        if ($request === null || (int) $request['customer_id'] !== (int) $user['id']) {
            http_response_code(404);
            view('errors/not-found');
            return;
        }

        view('customer/long-term-success', [
            'user' => $user,
            'request' => $request,
        ]);
    }

    /** @param array<int|string, mixed> $input @return array<int, int> */
    private function parseAddOnQuantities(array $input): array
    {
        $quantities = [];
        foreach ($input as $itemId => $quantity) {
            $quantities[(int) $itemId] = max(0, (int) $quantity);
        }

        return array_filter($quantities, static fn (int $qty): bool => $qty > 0);
    }

    /** @return ?array<string, mixed> */
    private function fetchLocation(int $locationId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM locations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $locationId]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    private function fetchAddOns(int $locationId, string $startDate, string $endDate): array
    {
        return (new EquipmentService($this->db))->availableAddOnCatalog($locationId, $startDate, $endDate);
    }

    private function buildCheckoutReturn(int $locationId, string $fulfillmentType, string $startDate, string $endDate): string
    {
        return route_path('checkout') . '?' . http_build_query(array_filter([
            'location_id' => $locationId > 0 ? $locationId : null,
            'fulfillment_type' => $fulfillmentType !== '' ? $fulfillmentType : null,
            'start_date' => $startDate !== '' ? $startDate : null,
            'end_date' => $endDate !== '' ? $endDate : null,
        ], static fn ($value): bool => $value !== null));
    }

    /**
     * @param ?array<string, mixed> $profile
     * @return array{
     *   locationId: int,
     *   location: ?array<string, mixed>,
     *   quote: array<string, mixed>,
     *   addOns: list<array<string, mixed>>
     * }
     */
    private function prepareCheckout(
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
        ?array $profile,
        ?string $paymentMethod = null,
    ): array {
        if ($fulfillmentType !== 'mail_ship' && $locationId <= 0) {
            \Starlink\Auth\Session::flash('error', 'Select valid dates on the calendar first.');
            redirect(route_path('/') . '#book');
        }

        if (parse_date($startDate) === null || parse_date($endDate) === null) {
            \Starlink\Auth\Session::flash('error', 'Select valid dates on the calendar first.');
            redirect(route_path('/') . '#book');
        }

        $availabilityLocationId = $fulfillmentType === 'mail_ship' ? 0 : $locationId;

        if (!$this->availability->isRangeAvailable($availabilityLocationId, $fulfillmentType, $startDate, $endDate)) {
            \Starlink\Auth\Session::flash('error', 'Those dates are no longer available.');
            redirect(route_path('/') . '#book');
        }

        $start = parse_date($startDate);
        $end = parse_date($endDate);
        $days = $start !== null && $end !== null ? inclusive_day_count($start, $end) : 0;
        $maxSelfServe = (int) pricing_config('max_self_serve_days', 30);
        if ($days > $maxSelfServe) {
            redirect(route_path('/') . '?' . http_build_query([
                'location_id' => $locationId,
                'fulfillment_type' => $fulfillmentType,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'long_term' => '1',
            ]) . '#book');
        }

        $location = $this->fetchLocation($locationId);
        $addOns = $this->fetchAddOns($locationId, $startDate, $endDate);
        $initialTaxProvince = $this->initialTaxProvince($fulfillmentType, $profile ?? []);

        try {
            $quote = $this->pricing->quote(
                $locationId,
                $fulfillmentType,
                $startDate,
                $endDate,
                [],
                false,
                null,
                null,
                $initialTaxProvince,
                null,
                $paymentMethod,
            );
            $quote = PricingService::formatQuote($quote);
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
            redirect(route_path('/') . '#book');
        }

        return [
            'locationId' => $locationId,
            'location' => $location,
            'quote' => $quote,
            'addOns' => $addOns,
        ];
    }

    /** @param array<string, mixed> $profile */
    private function initialTaxProvince(string $fulfillmentType, array $profile): ?string
    {
        if (in_array($fulfillmentType, ['mail_ship', 'city_delivery'], true)) {
            $province = $profile['shipping_address']['province'] ?? null;

            return is_string($province) && $province !== '' ? $province : null;
        }

        return null;
    }
}

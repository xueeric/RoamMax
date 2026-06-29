<?php

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name !== '' && !array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}

function config(string $key, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require WEBSITE_ROOT . '/config/app.php';
    }

    if (!str_contains($key, '.')) {
        return $config[$key] ?? $default;
    }

    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function notifications_live(): bool
{
    if (array_key_exists('NOTIFICATIONS_LIVE', $_ENV)) {
        return filter_var($_ENV['NOTIFICATIONS_LIVE'], FILTER_VALIDATE_BOOL);
    }

    return config('env') === 'production';
}

function db_path(): string
{
    $path = config('db_path', 'data/starlink.db');
    if (!str_starts_with($path, '/')) {
        $path = WEBSITE_ROOT . '/' . $path;
    }

    return $path;
}

function now_utc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function format_pickup_time(string $value): string
{
    if (!preg_match('/^(\d{1,2}):\d{2}$/', $value, $matches)) {
        return $value;
    }

    $hour = (int) $matches[1];

    return match (true) {
        $hour === 0 => '12 AM',
        $hour < 12 => $hour . ' AM',
        $hour === 12 => '12 PM',
        default => ($hour - 12) . ' PM',
    };
}

function format_pickup_window_phrase(
    string $date,
    string $timeStart = '',
    string $timeEnd = '',
    string $prefix = 'Your home pickup window is',
): string {
    $date = trim($date);
    $timeStart = trim($timeStart);
    $timeEnd = trim($timeEnd);

    if ($date === '') {
        return '';
    }

    $phrase = $prefix . ' ' . $date;
    if ($timeStart === '') {
        return rtrim($phrase, '.') . '.';
    }

    if ($timeEnd === '' || $timeEnd === $timeStart) {
        return $phrase . ' at ' . format_pickup_time($timeStart) . '.';
    }

    return $phrase . ' from ' . format_pickup_time($timeStart) . ' to ' . format_pickup_time($timeEnd) . '.';
}

/** @param array<string, mixed> $booking @return ?array{status: string, slot: string, message: string, customer_notes: string} */
function booking_appointment_summary(array $booking): ?array
{
    $type = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType((string) ($booking['fulfillment_type'] ?? 'pickup'));
    if ($type !== 'pickup_appointment') {
        return null;
    }

    $rawStatus = (string) ($booking['appointment_status'] ?? 'appointment_na');
    $status = match ($rawStatus) {
        'appointment_awaiting_admin', 'awaiting_admin' => 'Awaiting confirmation',
        'appointment_proposed', 'proposed' => 'Proposed — waiting on customer',
        'appointment_confirmed', 'confirmed' => 'Confirmed',
        default => ucwords(str_replace('_', ' ', $rawStatus)),
    };

    $prefix = in_array($rawStatus, ['appointment_proposed', 'proposed'], true) ? 'proposed' : 'confirmed';
    $date = trim((string) ($booking[$prefix . '_pickup_date'] ?? ''));
    $timeStart = trim((string) ($booking[$prefix . '_pickup_time_start'] ?? ''));
    $timeEnd = trim((string) ($booking[$prefix . '_pickup_time_end'] ?? ''));
    $slot = $date !== ''
        ? format_pickup_window_phrase($date, $timeStart, $timeEnd, 'Pickup')
        : '';

    return [
        'status' => $status,
        'slot' => $slot,
        'message' => trim((string) ($booking['admin_pickup_message'] ?? '')),
        'customer_notes' => trim((string) ($booking['customer_notes'] ?? '')),
    ];
}

function request_client_ip(): ?string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $raw) {
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }

        $raw = trim(explode(',', $raw)[0]);
        if (filter_var($raw, FILTER_VALIDATE_IP) !== false) {
            return $raw;
        }
    }

    return null;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function safe_redirect_path(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }

    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '://')) {
        return null;
    }

    return $path;
}

function view(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $contentTemplate = WEBSITE_ROOT . '/templates/' . $template . '.php';

    if (!is_file($contentTemplate)) {
        http_response_code(500);
        echo 'Template not found: ' . htmlspecialchars($template, ENT_QUOTES, 'UTF-8');
        exit;
    }

    require WEBSITE_ROOT . '/templates/layout.php';
}

function escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** @param array<string, mixed> $booking */
function booking_reference(array $booking): string
{
    $code = strtoupper(trim((string) ($booking['reference_code'] ?? '')));
    if ($code !== '') {
        return $code;
    }

    return str_pad((string) max(0, (int) ($booking['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
}

/** @param array<string, mixed> $booking */
function booking_payment_status(array $booking): string
{
    if (!empty($booking['payment_status'])) {
        return (string) $booking['payment_status'];
    }

    return (new \Starlink\Services\BookingStateService())->mapLegacyRow($booking)['payment_status'];
}

/** @param array<string, mixed> $booking */
function booking_lifecycle_status(array $booking): string
{
    if (!empty($booking['booking_status'])) {
        return (string) $booking['booking_status'];
    }

    return (new \Starlink\Services\BookingStateService())->mapLegacyRow($booking)['booking_status'];
}

/** @param array<string, mixed> $booking */
function booking_fulfillment_status(array $booking): string
{
    if (!empty($booking['fulfillment_status'])) {
        return (string) $booking['fulfillment_status'];
    }

    return (new \Starlink\Services\BookingStateService())->mapLegacyRow($booking)['fulfillment_status'];
}

/** @param array<string, mixed> $booking */
function booking_is_owner_block(array $booking): bool
{
    return \Starlink\Booking\BookingStatuses::isOwnerBlockKind((string) ($booking['booking_kind'] ?? 'customer'));
}

function booking_is_partner_personal(array $booking): bool
{
    return \Starlink\Booking\BookingStatuses::isPartnerPersonalKind((string) ($booking['booking_kind'] ?? 'customer'));
}

/** @param array<string, mixed> $booking */
function booking_customer_status_label(array $booking): string
{
    return \Starlink\Booking\BookingStatuses::customerLabel(booking_lifecycle_status($booking));
}

function long_term_request_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Quote pending',
        'contacted' => 'Contacted',
        'quoted' => 'Quote sent',
        'converted' => 'Converted',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

/**
 * @param array<string, mixed> $booking
 * @return array{
 *   method: string,
 *   flow: string,
 *   rental_cents: int,
 *   deposit_cents: int,
 *   rental_state: string,
 *   deposit_state: string,
 *   status_label: string,
 *   status_class: string,
 *   release_blocked: bool
 * }
 */
/**
 * @param array<string, mixed> $booking
 * @return array{method: string, flow: string}
 */
function booking_resolved_payment_context(array $booking): array
{
    $payment = booking_payment_status($booking);
    $method = trim((string) ($booking['payment_method'] ?? ''));
    $flow = trim((string) ($booking['square_payment_flow'] ?? ''));

    if ($method === '') {
        if (str_starts_with($payment, 'payment_etransfer_')) {
            $method = 'etransfer';
        } elseif (str_starts_with($payment, 'payment_square_') || str_contains($payment, 'deposit_scheduled')) {
            $method = 'square';
        }
    }

    if ($flow === '' && $method === 'square') {
        if ($payment === 'payment_square_full_captured') {
            $flow = 'long_term_capture';
        } elseif (in_array($payment, [
            'payment_square_rental_captured',
            'payment_deposit_scheduled',
            'payment_deposit_scheduled_processed',
            'payment_deposit_scheduled_failed',
            'payment_deposit_scheduled_released',
        ], true)) {
            $flow = 'short_term_auth';
        } else {
            $start = parse_date((string) ($booking['start_date'] ?? ''));
            $end = parse_date((string) ($booking['end_date'] ?? ''));
            $days = $start !== null && $end !== null ? inclusive_day_count($start, $end) : 0;
            $longMin = (int) config('payments.square_long_term_min_days', 5);
            $flow = $days >= $longMin ? 'long_term_capture' : 'short_term_auth';
        }
    }

    return ['method' => $method, 'flow' => $flow];
}

function booking_admin_payment_summary(array $booking): array
{
    if (booking_is_partner_personal($booking)) {
        $fee = (int) ($booking['partner_borrow_fee_cents'] ?? 0);
        return [
            'method' => $fee > 0 ? 'e-Transfer' : 'Waived',
            'flow' => 'partner_personal',
            'flow_label' => 'Partner personal trip',
            'rental_cents' => $fee,
            'deposit_cents' => 0,
            'rental_state' => $fee > 0 ? \Starlink\Services\PricingService::formatMoney($fee) . ' borrow fee' : 'No charge',
            'deposit_state' => 'None',
            'status_label' => str_replace('_', ' ', booking_lifecycle_status($booking) . ' · ' . booking_fulfillment_status($booking)),
            'status_class' => 'badge-active',
            'release_blocked' => false,
            'payment_mismatch' => false,
        ];
    }

    if (booking_is_owner_block($booking)) {
        return [
            'method' => 'Waived',
            'flow' => 'owner_block',
            'flow_label' => 'Owner / partner block',
            'rental_cents' => 0,
            'deposit_cents' => 0,
            'rental_state' => 'No charge',
            'deposit_state' => 'None',
            'status_label' => str_replace('_', ' ', booking_lifecycle_status($booking) . ' · ' . booking_fulfillment_status($booking)),
            'status_class' => 'badge-active',
            'release_blocked' => false,
            'payment_mismatch' => false,
        ];
    }

    $payment = booking_payment_status($booking);
    $lifecycle = booking_lifecycle_status($booking);
    $fulfillment = booking_fulfillment_status($booking);
    $resolved = booking_resolved_payment_context($booking);
    $method = $resolved['method'];
    $flow = $resolved['flow'];

    $rentalCents = (int) $booking['rental_total_cents']
        + (int) $booking['shipping_fee_cents']
        + (int) $booking['add_ons_total_cents']
        + (int) ($booking['tax_cents'] ?? 0);
    $depositCents = (int) ($booking['deposit_cents'] ?? 0);
    $hasDepositAuth = trim((string) ($booking['square_deposit_payment_id'] ?? '')) !== '';

    $start = parse_date((string) ($booking['start_date'] ?? ''));
    $end = parse_date((string) ($booking['end_date'] ?? ''));
    $rentalDays = $start !== null && $end !== null ? inclusive_day_count($start, $end) : 0;
    $shortMax = (int) config('payments.square_short_max_rental_days', 4);
    $longMin = (int) config('payments.square_long_term_min_days', 5);

    if ($method === 'etransfer') {
        $flowLabel = 'e-Transfer · full amount';
    } elseif ($flow === 'short_term_auth') {
        $flowLabel = 'Square · short (≤ ' . $shortMax . ' days)';
    } elseif ($flow === 'long_term_capture') {
        $flowLabel = 'Square · long (' . $longMin . '+ days)';
    } elseif ($method === 'square') {
        $flowLabel = 'Square';
    } else {
        $flowLabel = $method !== '' ? ucfirst($method) : 'Not selected';
    }

    $square = new \Starlink\Services\SquareService();
    $rentalPaymentId = trim((string) ($booking['square_payment_id'] ?? ''));
    $depositPaymentId = trim((string) ($booking['square_deposit_payment_id'] ?? ''));
    $ledger = new \Starlink\Services\BookingPaymentLedgerService();
    $rentalMismatch = $ledger->hasRentalPaymentMismatch($booking);
    $depositMismatch = $ledger->hasDepositPaymentMismatch($booking);
    $paymentMismatch = $rentalMismatch || $depositMismatch;
    $depositCharged = $ledger->isDepositCharged($booking);

    $rentalState = match (true) {
        $rentalMismatch => 'Not verified — re-pay required',
        str_starts_with($payment, 'payment_pending_') => 'Awaiting payment',
        $payment === 'payment_etransfer_sent' => 'Awaiting admin confirm',
        $payment === 'payment_etransfer_partial_confirmed' => 'Partial — balance due',
        $rentalPaymentId !== '' && $square->isLocalPaymentId($rentalPaymentId) => 'Test/mock only',
        default => 'Captured',
    };

    $depositState = match (true) {
        $depositMismatch => 'Not verified — action required',
        $depositCents <= 0 => 'None',
        $payment === 'payment_pending_etransfer' || $payment === 'payment_pending_square' => 'Awaiting rental payment',
        $depositCharged => 'Charged',
        $payment === 'payment_square_rental_captured' && $flow === 'short_term_auth' => 'Scheduled · hold T-24h before ' . ($booking['start_date'] ?? 'start'),
        $payment === 'payment_deposit_scheduled' => 'Auth at T-24h before ' . ($booking['start_date'] ?? 'start'),
        $payment === 'payment_deposit_scheduled_failed' => 'Authorization failed — do not release',
        $payment === 'payment_deposit_scheduled_released' => 'Hold released',
        $flow === 'long_term_capture' && $lifecycle === 'booking_closed' => 'Refunded on return',
        $flow === 'long_term_capture' => 'Captured upfront',
        $flow === 'short_term_auth' && $hasDepositAuth && $ledger->hasPendingDepositHold($booking) && $lifecycle !== 'booking_closed' => 'Hold active',
        $payment === 'payment_deposit_scheduled_processed' && $hasDepositAuth && !$depositMismatch && $ledger->hasPendingDepositHold($booking) => 'Hold active',
        $payment === 'payment_deposit_scheduled_processed' && $hasDepositAuth && $depositCharged => 'Charged',
        $payment === 'payment_deposit_scheduled_processed' && $depositPaymentId !== '' && $square->isLocalPaymentId($depositPaymentId) => 'Test/mock only',
        $payment === 'payment_deposit_scheduled_processed' => 'Authorized — verify in Square',
        $method === 'etransfer' && $payment !== 'payment_pending_etransfer' => 'Collected via e-Transfer',
        default => '—',
    };

    $statusLabel = str_replace('_', ' ', $lifecycle . ' · ' . $fulfillment);
    $statusClass = match ($lifecycle) {
        'booking_cancelled', 'booking_closed' => 'badge-muted',
        'booking_late' => 'badge-warning',
        'booking_pending_payment' => 'badge-muted',
        default => 'badge-active',
    };
    if (in_array($payment, ['payment_deposit_scheduled_failed'], true)) {
        $statusClass = 'badge-warning';
    }

    $depositHoldRelease = (new \Starlink\Services\SquareDepositService())->depositHoldReleaseInfo($booking);

    return [
        'method' => $method !== '' ? ($method === 'etransfer' ? 'e-Transfer' : ucfirst($method)) : '—',
        'flow' => $flowLabel,
        'rental_cents' => $rentalCents,
        'deposit_cents' => $depositCents,
        'rental_days' => $rentalDays,
        'rental_state' => $rentalState,
        'deposit_state' => $depositState,
        'status_label' => $statusLabel,
        'status_class' => $statusClass,
        'payment_status' => $payment,
        'booking_status' => $lifecycle,
        'fulfillment_status' => $fulfillment,
        'rental_mismatch' => $rentalMismatch,
        'deposit_mismatch' => $depositMismatch,
        'release_blocked' => in_array($payment, [
            'payment_deposit_scheduled',
            'payment_deposit_scheduled_failed',
            'payment_square_rental_captured',
        ], true)
            || $rentalMismatch
            || $depositMismatch,
        'deposit_hold_release' => $depositHoldRelease,
    ];
}

/** @param array<string, mixed> $booking */
function booking_admin_stage_summary(array $booking): array
{
    return (new Starlink\Services\StagingService())->stageSummaryForBooking((int) $booking['id'], $booking);
}

function route_path(string $path = ''): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    if ($base === '/' || $base === '\\') {
        $base = '';
    }

    return $base . '/' . ltrim($path, '/');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function old(string $key, string $default = ''): string
{
    return escape((string) (Starlink\Auth\Session::flash('old_' . $key) ?? $default));
}

function flash(string $key): ?string
{
    return Starlink\Auth\Session::flash($key);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

function pricing_config(string $key, mixed $default = null): mixed
{
    return (new \Starlink\Services\PricingConfigService())->getSetting($key, $default);
}

function parse_date(string $value): ?\DateTimeImmutable
{
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date === false) {
        return null;
    }

    return $date->setTime(0, 0);
}

function today_date(): \DateTimeImmutable
{
    return new \DateTimeImmutable('today', business_timezone());
}

function business_timezone(): \DateTimeZone
{
    static $timezone = null;
    if ($timezone === null) {
        $timezone = new \DateTimeZone((string) config('timezone', 'America/Edmonton'));
    }

    return $timezone;
}

function request_is_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    return $forwarded === 'https';
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://sandbox.web.squarecdn.com https://web.squarecdn.com; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "connect-src 'self' https://*.squareup.com https://*.squareupsandbox.com; "
        . "frame-src https://*.squareup.com https://*.squareupsandbox.com https://sandbox.web.squarecdn.com https://web.squarecdn.com; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

function assert_config_security(array $config): void
{
    $env = strtolower((string) ($config['env'] ?? 'local'));
    if (!in_array($env, ['production', 'prod'], true)) {
        return;
    }

    $secret = trim((string) ($config['session_secret'] ?? ''));
    if ($secret === '' || $secret === 'dev-insecure-secret' || strlen($secret) < 32) {
        throw new RuntimeException('Production requires a strong SESSION_SECRET (32+ characters) in .env');
    }
}

function honeypot_field(): string
{
    return '<div class="hp-field" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">'
        . '<label>Website<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>'
        . '</div>';
}

function honeypot_triggered(): bool
{
    return trim((string) ($_POST['website'] ?? '')) !== '';
}

function csrf_token(): string
{
    return \Starlink\Auth\CsrfService::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . escape(csrf_token()) . '">';
}

function csrf_validate_or_abort(): void
{
    if (\Starlink\Auth\CsrfService::validate($_POST['_csrf'] ?? null)) {
        return;
    }

    http_response_code(419);
    view('errors/forbidden', [
        'message' => 'Your session expired or the form was invalid. Refresh the page and try again.',
    ]);
    exit;
}

function inclusive_day_count(\DateTimeImmutable $start, \DateTimeImmutable $end): int
{
    return (int) $start->diff($end)->days + 1;
}

function page_version_service(): \Starlink\Services\PageVersionService
{
    static $service = null;
    if ($service === null) {
        $service = new \Starlink\Services\PageVersionService();
    }

    return $service;
}

function is_version_footer_visible(): bool
{
    return page_version_service()->isDevDisplayHost();
}

/**
 * @return array{key: string, label: string, route: string, version: string, modified: string, template: string}
 */
function page_version_for_template(string $contentTemplate): array
{
    return page_version_service()->forTemplatePath($contentTemplate);
}

function page_version_badge_class(string $pageVersion): string
{
    return page_version_service()->versionBadgeClass($pageVersion);
}

function seo_service(): \Starlink\Services\SeoService
{
    static $service = null;
    if ($service === null) {
        $service = new \Starlink\Services\SeoService();
    }

    return $service;
}

/**
 * @return array{
 *   title: string,
 *   description: string,
 *   robots: string,
 *   canonical: string,
 *   og_image: string,
 *   json_ld: list<array<string, mixed>>
 * }
 */
function seo_meta(string $contentTemplate, ?string $pageTitle = null): array
{
    return seo_service()->forTemplate($contentTemplate, $pageTitle);
}

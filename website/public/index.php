<?php

declare(strict_types=1);

use Starlink\Controllers\AdminController;
use Starlink\Controllers\AdminOpsController;
use Starlink\Controllers\AccountController;
use Starlink\Controllers\AuthController;
use Starlink\Controllers\AvailabilityController;
use Starlink\Controllers\BookingController;
use Starlink\Controllers\CalendarController;
use Starlink\Controllers\HomeController;
use Starlink\Controllers\PartnerController;
use Starlink\Controllers\SeoController;
use Starlink\Controllers\VersionController;
use Starlink\Controllers\WebhookController;
use Starlink\Router;

define('WEBSITE_ROOT', dirname(__DIR__));

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$decodedPath = rawurldecode($requestPath);
$staticFile = __DIR__ . $decodedPath;
$publicRoot = realpath(__DIR__);
if (
    $requestPath !== '/'
    && $publicRoot !== false
    && is_file($staticFile)
) {
    $resolved = realpath($staticFile);
    if ($resolved !== false && str_starts_with($resolved, $publicRoot . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

$config = require WEBSITE_ROOT . '/bootstrap.php';

$home = new HomeController();
$auth = new AuthController();
$admin = new AdminController();
$adminOps = new AdminOpsController();
$partner = new PartnerController();
$webhooks = new WebhookController();
$availability = new AvailabilityController();
$calendar = new CalendarController();
$booking = new BookingController();
$account = new AccountController();
$version = new VersionController();
$seo = new SeoController();

$router = new Router();
$router
    ->get('/', static fn () => $home->index())
    ->get('/robots.txt', static fn () => $seo->robots())
    ->get('/sitemap.xml', static fn () => $seo->sitemap())
    ->get('/version', static fn () => $version->index())
    ->get('/version.php', static fn () => $version->index())
    ->get('/book', static fn () => $calendar->show())
    ->get('/availability', static fn () => $availability->show())
    ->get('/quote', static fn () => $booking->quote())
    ->get('/checkout', static fn () => $booking->checkoutForm())
    ->post('/checkout', static fn () => $booking->checkoutSubmit())
    ->post('/book/long-term-request', static fn () => $booking->longTermRequestSubmit())
    ->get('/book/long-term-request/success', static fn () => $booking->longTermRequestSuccess())
    ->get('/agreement', static fn () => $booking->agreement())
    ->post('/agreement/migrate', static fn () => $booking->agreementMigrate())
    ->get('/booking/success', static fn () => $booking->success())
    ->get('/booking/etransfer', static fn () => $booking->etransferPay())
    ->post('/booking/etransfer', static fn () => $booking->etransferPay())
    ->get('/booking/pay-card', static fn () => $booking->cardPay())
    ->post('/booking/pay-card', static fn () => $booking->cardPay())
    ->get('/booking/update-card', static fn () => $booking->updateCard())
    ->post('/booking/update-card', static fn () => $booking->updateCard())
    ->get('/account/bookings', static fn () => $account->bookings())
    ->get('/account/bookings/view', static fn () => $account->bookingDetail())
    ->get('/account/profile', static fn () => $account->profile())
    ->post('/account/profile', static fn () => $account->saveProfile())
    ->post('/account/bookings/accept', static fn () => $account->acceptProposal())
    ->post('/account/bookings/cancel', static fn () => $account->cancel())
    ->get('/account/bookings/pay', static fn () => $account->pay())
    ->post('/account/bookings/pay', static fn () => $account->pay())
    ->get('/login', static fn () => $auth->showLogin())
    ->post('/login', static fn () => $auth->login())
    ->get('/register', static fn () => $auth->showRegister())
    ->post('/register', static fn () => $auth->register())
    ->post('/logout', static fn () => $auth->logout())
    ->get('/admin', static fn () => $admin->dashboard())
    ->get('/admin/bookings', static fn () => $adminOps->bookings())
    ->get('/admin/bookings/view', static fn () => $adminOps->bookingDetail())
    ->post('/admin/bookings/action', static fn () => $adminOps->bookingAction())
    ->get('/admin/equipment', static fn () => $admin->equipment())
    ->get('/admin/equipment/edit', static fn () => $adminOps->equipmentForm())
    ->get('/admin/equipment/accessories', static fn () => $admin->accessories())
    ->get('/admin/equipment/accessories/edit', static fn () => $adminOps->accessoryForm())
    ->post('/admin/equipment/save', static fn () => $adminOps->saveEquipment())
    ->post('/admin/equipment/delete', static fn () => $adminOps->deleteEquipment())
    ->get('/admin/inventory', static fn () => $adminOps->inventory())
    ->post('/admin/inventory/save', static fn () => $adminOps->saveInventory())
    ->get('/admin/transfers', static fn () => $adminOps->transfers())
    ->post('/admin/transfers', static fn () => $adminOps->createTransfer())
    ->post('/admin/transfers/complete', static fn () => $adminOps->completeTransfer())
    ->get('/admin/locations', static fn () => $adminOps->locations())
    ->get('/admin/locations/edit', static fn () => $adminOps->locationForm())
    ->post('/admin/locations/save', static fn () => $adminOps->saveLocation())
    ->post('/admin/locations/toggle', static fn () => $adminOps->toggleLocation())
    ->get('/admin/long-term-requests', static fn () => $adminOps->longTermRequests())
    ->post('/admin/long-term-requests/update', static fn () => $adminOps->updateLongTermRequest())
    ->get('/admin/pricing', static fn () => $adminOps->pricing())
    ->post('/admin/pricing/settings', static fn () => $adminOps->savePricingSettings())
    ->post('/admin/pricing/tier', static fn () => $adminOps->savePricingTier())
    ->post('/admin/pricing/rule', static fn () => $adminOps->savePricingRule())
    ->post('/admin/pricing/rule/delete', static fn () => $adminOps->deletePricingRule())
    ->get('/admin/financials', static fn () => $adminOps->financials())
    ->get('/admin/notifications', static fn () => $adminOps->notifications())
    ->get('/admin/notifications/preview', static fn () => $adminOps->notificationPreview())
    ->post('/admin/notifications', static fn () => $adminOps->saveNotifications())
    ->get('/admin/users', static fn () => $adminOps->users())
    ->get('/admin/users/view', static fn () => $adminOps->userDetail())
    ->post('/admin/users/email', static fn () => $adminOps->updateUserEmail())
    ->post('/admin/users/password', static fn () => $adminOps->resetUserPassword())
    ->get('/admin/users/new', static fn () => $adminOps->newUserForm())
    ->post('/admin/users/new', static fn () => $adminOps->createUser())
    ->post('/admin/users/delete', static fn () => $adminOps->deleteUser())
    ->post('/admin/staging/complete', static fn () => $adminOps->completeStaging())
    ->post('/admin/staging/settings', static fn () => $adminOps->saveStagingSettings())
    ->get('/admin/blocks', static fn () => $admin->blocks())
    ->post('/admin/blocks', static fn () => $admin->createBlock())
    ->get('/admin/staging', static fn () => $admin->staging())
    ->post('/admin/staging', static fn () => $admin->scheduleStaging())
    ->get('/admin/swaps', static fn () => $admin->swaps())
    ->post('/admin/swaps', static fn () => $admin->approveSwap())
    ->get('/admin/appointments', static fn () => $admin->appointments())
    ->post('/admin/appointments/confirm', static fn () => $admin->confirmAppointment())
    ->get('/admin/bookings/new', static fn () => $admin->newBookingForm())
    ->post('/admin/bookings/new', static fn () => $admin->createBooking())
    ->get('/partner', static fn () => $partner->dashboard())
    ->get('/partner/equipment', static fn () => $partner->equipment())
    ->get('/partner/financials', static fn () => $partner->financials())
    ->get('/partner/blocks', static fn () => $partner->blocks())
    ->get('/partner/personal-quote', static fn () => $partner->personalQuote())
    ->post('/partner/blocks', static fn () => $partner->createBlock())
    ->get('/partner/swaps', static fn () => $partner->swaps())
    ->post('/partner/swaps', static fn () => $partner->requestSwap())
    ->post('/webhooks/square', static fn () => $webhooks->square());

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');

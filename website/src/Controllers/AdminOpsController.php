<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use PDO;
use Starlink\Database\Connection;
use Starlink\Middleware\RequireRole;
use Starlink\Services\AdminBookingPresenter;
use Starlink\Services\BookingPaymentLedgerService;
use Starlink\Services\BookingService;
use Starlink\Services\BookingUnavailableException;
use Starlink\Services\EquipmentService;
use Starlink\Services\OperationsService;
use Starlink\Services\LongTermRequestService;
use Starlink\Services\NotificationRuleService;
use Starlink\Services\PayoffService;
use Starlink\Services\SquareDepositService;
use Starlink\Services\PricingConfigService;
use Starlink\Services\UserService;

final class AdminOpsController
{
    private readonly PDO $db;

    public function __construct(
        private readonly RequireRole $guard = new RequireRole(),
        ?PDO $db = null,
        private readonly OperationsService $ops = new OperationsService(),
        private readonly BookingService $bookings = new BookingService(),
        private readonly EquipmentService $equipment = new EquipmentService(),
        private readonly PayoffService $payoff = new PayoffService(),
        private readonly UserService $users = new UserService(),
        private readonly PricingConfigService $pricingConfig = new PricingConfigService(),
        private readonly LongTermRequestService $longTermRequests = new LongTermRequestService(),
        private readonly SquareDepositService $deposits = new SquareDepositService(),
    ) {
        $this->db = $db ?? Connection::get();
    }

    public function bookings(): void
    {
        $user = $this->guard->handle(['admin']);
        $statusFilter = (string) ($_GET['status'] ?? '');
        view('admin/bookings', [
            'user' => $user,
            'bookings' => $this->ops->listBookings($statusFilter !== '' ? $statusFilter : null),
            'statusFilter' => $statusFilter,
            'tabCounts' => $this->ops->bookingTabCounts(),
        ]);
    }

    public function bookingDetail(): void
    {
        $user = $this->guard->handle(['admin']);
        $bookingId = (int) ($_GET['booking_id'] ?? 0);
        $booking = $this->bookings->findBooking($bookingId);

        if ($booking === null) {
            \Starlink\Auth\Session::flash('error', 'Booking not found.');
            redirect(route_path('admin/bookings'));
        }

        $presenter = new AdminBookingPresenter();
        $lifecycle = booking_lifecycle_status($booking);
        $fStatus = booking_fulfillment_status($booking);
        $canHandOut = (new \Starlink\Services\BookingStateService())->canHandOutHardware($booking);
        $stageSummary = ($canHandOut && $lifecycle === 'booking_confirmed' && $fStatus === 'fulfillment_pending')
            ? booking_admin_stage_summary($booking)
            : null;
        $eligibleUnits = (new \Starlink\Services\AssignmentService())->eligibleUnitsForBooking($booking);

        $ledger = new BookingPaymentLedgerService();

        view('admin/booking-detail', [
            'user' => $user,
            'booking' => $booking,
            'pay' => booking_admin_payment_summary($booking),
            'attention' => $presenter->attentionItems($booking),
            'stageSummary' => $stageSummary,
            'eligibleUnits' => $eligibleUnits,
            'statusFilter' => (string) ($_GET['status'] ?? ''),
            'paymentTimeline' => $ledger->transactionTimeline($bookingId, $booking),
            'paymentMismatch' => $ledger->hasPaymentMismatch($booking),
            'depositActions' => $presenter->depositActions($booking),
            'cancelDefaults' => $ledger->cancelPaymentDefaults($booking),
        ]);
    }

    public function bookingAction(): void
    {
        $user = $this->guard->handle(['admin']);
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'reset_payment') {
                $this->bookings->resetPaymentForRepay($bookingId, (int) $user['id']);
                \Starlink\Auth\Session::flash(
                    'success',
                    'Payment reset. Customer can pay again from their booking page.',
                );
            } elseif ($action === 'verify_square') {
                $booking = $this->bookings->findBooking($bookingId);
                if ($booking === null) {
                    throw new BookingUnavailableException('Booking not found.');
                }
                $squarePaymentId = trim((string) ($_POST['square_payment_id'] ?? ''));
                $result = (new BookingPaymentLedgerService())->verifyAndStoreSquareTransaction($bookingId, $squarePaymentId);
                if (!$result['ok']) {
                    throw new BookingUnavailableException($result['message'] ?? 'Square verification failed.');
                }
                \Starlink\Auth\Session::flash('success', (string) ($result['message'] ?? 'Verified in Square.'));
            } elseif (in_array($action, ['deposit_hold', 'deposit_charge', 'deposit_capture', 'deposit_release'], true)) {
                $booking = $this->bookings->findBooking($bookingId);
                if ($booking === null) {
                    throw new BookingUnavailableException('Booking not found.');
                }
                $result = match ($action) {
                    'deposit_hold' => $this->deposits->adminAuthorizeDepositHold($booking, (int) $user['id']),
                    'deposit_charge' => $this->deposits->adminChargeDeposit($booking, (int) $user['id']),
                    'deposit_capture' => $this->deposits->adminCaptureDepositHold($booking, (int) $user['id']),
                    'deposit_release' => $this->deposits->adminReleaseDepositHold($booking, (int) $user['id']),
                };
                if (!$result['ok']) {
                    throw new BookingUnavailableException($result['message'] ?? 'Deposit action failed.');
                }
                \Starlink\Auth\Session::flash('success', (string) ($result['message'] ?? 'Deposit action completed.'));
            } elseif ($action === 'assign_equipment') {
                $equipmentId = (int) ($_POST['equipment_id'] ?? 0);
                if ($equipmentId <= 0) {
                    throw new BookingUnavailableException('Choose a unit to assign.');
                }
                $this->bookings->assignEquipment($bookingId, $equipmentId, (int) $user['id']);
                \Starlink\Auth\Session::flash('success', 'Unit assigned — check staging due date.');
            } elseif ($action === 'approve_cancellation') {
                $note = trim((string) ($_POST['cancellation_reason'] ?? ''));
                $this->bookings->approveCancellation(
                    $bookingId,
                    (int) $user['id'],
                    $note !== '' ? $note : null,
                    $this->refundCentsFromPost(),
                    $this->releaseDepositFromPost(),
                );
                \Starlink\Auth\Session::flash('success', 'Cancellation approved — refund and deposit release processed.');
            } elseif ($action === 'reject_cancellation') {
                $note = trim((string) ($_POST['cancellation_reason'] ?? ''));
                $this->bookings->rejectCancellation($bookingId, (int) $user['id'], $note !== '' ? $note : null);
                \Starlink\Auth\Session::flash('success', 'Cancellation request declined — booking remains confirmed.');
            } elseif ($action === 'cancel') {
                $reason = trim((string) ($_POST['cancellation_reason'] ?? ''));
                $this->bookings->adminCancelWithOptions($bookingId, (int) $user['id'], $reason !== '' ? $reason : null, [
                    'refund_cents' => $this->refundCentsFromPost(),
                    'release_deposit' => $this->releaseDepositFromPost(),
                ]);
                \Starlink\Auth\Session::flash('success', 'Booking cancelled.');
            } elseif ($action === 'confirm_etransfer') {
                $booking = $this->bookings->findBooking($bookingId);
                if ($booking === null || ($booking['payment_method'] ?? '') !== 'etransfer') {
                    throw new BookingUnavailableException('This booking is not awaiting e-Transfer confirmation.');
                }
                $amountCents = (int) round(((float) ($_POST['etransfer_amount'] ?? 0)) * 100);
                if ($amountCents <= 0) {
                    $amountCents = $this->bookings->totalDueCents($booking);
                }
                $this->bookings->confirmEtransfer($bookingId, $amountCents);
                \Starlink\Auth\Session::flash('success', 'E-Transfer recorded for booking ' . booking_reference($booking) . '.');
            } elseif ($action === 'late_fee') {
                $this->bookings->applyLateFees($bookingId);
                \Starlink\Auth\Session::flash('success', 'Late fees applied.');
            } elseif ($action === 'qc_failed') {
                $damageCents = (int) round(((float) ($_POST['damage_cents'] ?? 0)) * 100);
                $this->ops->advanceBooking($bookingId, 'qc_failed', $damageCents > 0 ? $damageCents : null);
                \Starlink\Auth\Session::flash('success', 'QC failed — damage charge recorded.');
            } else {
                $booking = $this->bookings->findBooking($bookingId);
                $stageNote = '';
                if ($action === 'stage' && $booking !== null) {
                    $stage = (new \Starlink\Services\StagingService())->stageSummaryForBooking($bookingId, $booking);
                    $stageNote = ' — ' . $stage['move_line'];
                }
                $this->ops->advanceBooking($bookingId, $action);
                $booking = $this->bookings->findBooking($bookingId);
                $ref = $booking !== null ? booking_reference($booking) . ' ' : '';
                $message = match ($action) {
                    'stage' => $ref . 'marked as staged' . $stageNote . '.',
                    'pickup' => $ref . 'marked as picked up — rental is now active.',
                    'ship' => $ref . 'marked as shipped — rental is now active.',
                    'return_received' => $ref . 'return received.',
                    'confirm_qc' => $ref . 'completed — equipment OK.',
                    default => 'Booking updated.',
                };
                \Starlink\Auth\Session::flash('success', $message);
            }
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        $statusFilter = trim((string) ($_POST['status_filter'] ?? ''));
        $returnToDetail = (string) ($_POST['return_to'] ?? '') === 'detail';

        if ($returnToDetail) {
            $redirectUrl = route_path('admin/bookings/view') . '?booking_id=' . $bookingId;
            if ($statusFilter !== '') {
                $redirectUrl .= '&status=' . rawurlencode($statusFilter);
            }
        } else {
            $redirectUrl = route_path('admin/bookings');
            if ($statusFilter !== '') {
                $redirectUrl .= '?status=' . rawurlencode($statusFilter);
            }
        }

        redirect($redirectUrl);
    }

    public function equipmentForm(): void
    {
        $this->renderEquipmentForm(EquipmentService::TYPE_STARLINK);
    }

    public function accessoryForm(): void
    {
        $this->renderEquipmentForm(EquipmentService::TYPE_ACCESSORY);
    }

    private function renderEquipmentForm(string $equipmentType): void
    {
        $user = $this->guard->handle(['admin']);
        $id = (int) ($_GET['id'] ?? 0);
        $equipment = $id > 0 ? $this->equipment->find($id) : null;
        if ($equipment !== null && (string) ($equipment['equipment_type'] ?? EquipmentService::TYPE_STARLINK) !== $equipmentType) {
            \Starlink\Auth\Session::flash('error', 'That item belongs to a different fleet list.');
            redirect($equipmentType === EquipmentService::TYPE_ACCESSORY
                ? route_path('admin/equipment/accessories')
                : route_path('admin/equipment'));
        }

        view('admin/equipment-form', [
            'user' => $user,
            'equipment' => $equipment,
            'equipmentType' => $equipmentType,
            'locations' => $this->ops->listLocations(),
            'partners' => $this->db->query('SELECT id, name FROM partners ORDER BY name ASC')->fetchAll(),
            'costs' => $id > 0 && $equipmentType === EquipmentService::TYPE_STARLINK ? $this->equipment->costs($id) : [],
            'starlinkPlans' => (new \Starlink\Services\StarlinkPlanService())->activePlans(),
            'planPeriods' => $id > 0 && $equipmentType === EquipmentService::TYPE_STARLINK
                ? (new \Starlink\Services\SubscriptionCostService())->planPeriods($id)
                : [],
            'starlinkPassword' => $equipment !== null
                ? \Starlink\Services\CredentialCipher::decrypt($equipment['starlink_account_password_enc'] ?? null)
                : null,
            'wifiPassword' => $equipment !== null
                ? \Starlink\Services\CredentialCipher::decrypt($equipment['wifi_password_enc'] ?? null)
                : null,
        ]);
    }

    public function saveEquipment(): void
    {
        $this->guard->handle(['admin']);
        $id = (int) ($_POST['id'] ?? 0);
        $equipmentType = (string) ($_POST['equipment_type'] ?? EquipmentService::TYPE_STARLINK);
        if (!in_array($equipmentType, [EquipmentService::TYPE_STARLINK, EquipmentService::TYPE_ACCESSORY], true)) {
            $equipmentType = EquipmentService::TYPE_STARLINK;
        }

        $data = $_POST;
        $data['equipment_type'] = $equipmentType;
        $data['purchase_cost_cents'] = (int) round(((float) ($data['purchase_cost'] ?? 0)) * 100);
        $data['partner_id'] = ($data['owner_type'] ?? '') === 'partner' ? (int) ($data['partner_id'] ?? 0) : null;
        if (trim((string) ($data['plan_slug'] ?? '')) !== '') {
            $data['data_plan'] = trim((string) $data['plan_slug']);
        }

        $listPath = $equipmentType === EquipmentService::TYPE_ACCESSORY
            ? route_path('admin/equipment/accessories')
            : route_path('admin/equipment');
        $editPath = $equipmentType === EquipmentService::TYPE_ACCESSORY
            ? route_path('admin/equipment/accessories/edit')
            : route_path('admin/equipment/edit');

        try {
            $savedId = $this->equipment->save($data, $id > 0 ? $id : null);
            if ($equipmentType === EquipmentService::TYPE_STARLINK) {
                $planSlug = trim((string) ($data['plan_slug'] ?? ''));
                if ($planSlug !== '') {
                    $payer = (string) ($data['subscription_payer'] ?? 'admin');
                    if (!in_array($payer, ['partner', 'admin'], true)) {
                        $payer = ($data['owner_type'] ?? '') === 'partner' ? 'partner' : 'admin';
                    }
                    $effectiveFrom = trim((string) ($data['plan_effective_from'] ?? ''));
                    if ($effectiveFrom === '') {
                        $effectiveFrom = today_date()->format('Y-m-d');
                    }
                    $current = (new \Starlink\Services\SubscriptionCostService())->currentPlanPeriod($savedId);
                    if ($current === null
                        || (string) $current['plan_slug'] !== $planSlug
                        || (string) $current['payer'] !== $payer
                    ) {
                        (new \Starlink\Services\StarlinkPlanService())->changePlan(
                            $savedId,
                            $planSlug,
                            $payer,
                            $effectiveFrom,
                            trim((string) ($data['plan_change_notes'] ?? '')) ?: null,
                        );
                    }
                }
            }
            if ($equipmentType === EquipmentService::TYPE_STARLINK
                && !empty($_POST['cost_amount'])
                && (float) $_POST['cost_amount'] > 0
            ) {
                $this->equipment->addCost(
                    $savedId,
                    (string) ($_POST['cost_type'] ?? 'other'),
                    (int) round(((float) $_POST['cost_amount']) * 100),
                    (string) ($_POST['cost_date'] ?? today_date()->format('Y-m-d')),
                    trim((string) ($_POST['cost_description'] ?? '')) ?: null,
                );
            }
            \Starlink\Auth\Session::flash('success', $equipmentType === EquipmentService::TYPE_ACCESSORY ? 'Accessory saved.' : 'Equipment saved.');
            redirect($editPath . '?id=' . $savedId);
        } catch (\Throwable $e) {
            \Starlink\Auth\Session::flash('error', 'Unable to save item.');
            redirect($listPath);
        }
    }

    public function deleteEquipment(): void
    {
        $this->guard->handle(['admin']);
        $equipmentId = (int) ($_POST['id'] ?? 0);
        $equipment = $equipmentId > 0 ? $this->equipment->find($equipmentId) : null;
        $equipmentType = (string) ($equipment['equipment_type'] ?? EquipmentService::TYPE_STARLINK);
        $listPath = $equipmentType === EquipmentService::TYPE_ACCESSORY
            ? route_path('admin/equipment/accessories')
            : route_path('admin/equipment');

        try {
            $this->equipment->delete($equipmentId);
            \Starlink\Auth\Session::flash('success', $equipmentType === EquipmentService::TYPE_ACCESSORY
                ? 'Accessory deleted.'
                : 'Equipment deleted.');
        } catch (\Throwable $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to delete equipment.';
            \Starlink\Auth\Session::flash('error', $message);
        }

        redirect($listPath);
    }

    public function inventory(): void
    {
        $this->guard->handle(['admin']);
        redirect(route_path('admin/equipment/accessories'));
    }

    public function saveInventory(): void
    {
        $this->guard->handle(['admin']);
        \Starlink\Auth\Session::flash('success', 'Accessories are managed under Fleet → Accessories.');
        redirect(route_path('admin/equipment/accessories'));
    }

    public function transfers(): void
    {
        $this->guard->handle(['admin']);
        \Starlink\Auth\Session::flash(
            'success',
            'Transfers are retired. Schedule all moves under Fleet → Staging.',
        );
        redirect(route_path('admin/staging'));
    }

    public function createTransfer(): void
    {
        $this->transfers();
    }

    public function completeTransfer(): void
    {
        $this->transfers();
    }

    public function completeStaging(): void
    {
        $this->guard->handle(['admin']);
        try {
            $this->ops->completeStaging((int) ($_POST['staging_id'] ?? 0));
            \Starlink\Auth\Session::flash('success', 'Staging marked complete.');
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }
        redirect(route_path('admin/staging'));
    }

    public function locations(): void
    {
        $user = $this->guard->handle(['admin']);
        view('admin/locations', [
            'user' => $user,
            'locations' => $this->ops->listLocations(),
        ]);
    }

    public function toggleLocation(): void
    {
        $this->guard->handle(['admin']);
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $active = (string) ($_POST['active'] ?? '0') === '1';
        $this->ops->setLocationActive($locationId, $active);
        \Starlink\Auth\Session::flash('success', 'Location updated.');
        redirect(route_path('admin/locations'));
    }

    public function locationForm(): void
    {
        $user = $this->guard->handle(['admin']);
        $locationId = (int) ($_GET['id'] ?? 0);
        $location = $this->ops->findLocation($locationId);

        if ($location === null) {
            \Starlink\Auth\Session::flash('error', 'Location not found.');
            redirect(route_path('admin/locations'));
        }

        view('admin/location-form', [
            'user' => $user,
            'location' => $location,
        ]);
    }

    public function saveLocation(): void
    {
        $this->guard->handle(['admin']);
        $locationId = (int) ($_POST['id'] ?? 0);

        try {
            $this->ops->saveLocation($locationId, $_POST);
            \Starlink\Auth\Session::flash('success', 'Location saved.');
        } catch (BookingUnavailableException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
            redirect(route_path('admin/locations/edit') . '?id=' . $locationId);
        }

        redirect(route_path('admin/locations'));
    }

    public function financials(): void
    {
        $user = $this->guard->handle(['admin']);
        view('admin/financials', [
            'user' => $user,
            'summary' => $this->payoff->financialSummary(),
            'reports' => $this->payoff->equipmentReports(),
            'revenueByOwner' => $this->payoff->revenueByOwner(),
            'ledger' => $this->payoff->paymentLedger(50),
        ]);
    }

    public function users(): void
    {
        $user = $this->guard->handle(['admin']);
        $roleFilter = (string) ($_GET['role'] ?? 'customer');
        $search = trim((string) ($_GET['q'] ?? ''));

        view('admin/users', [
            'user' => $user,
            'users' => $this->users->listUsers(
                $roleFilter !== '' ? $roleFilter : null,
                $search !== '' ? $search : null,
            ),
            'roleFilter' => $roleFilter,
            'search' => $search,
        ]);
    }

    public function userDetail(): void
    {
        $user = $this->guard->handle(['admin']);
        $userId = (int) ($_GET['id'] ?? 0);
        $profile = $this->users->findUser($userId);

        if ($profile === null) {
            \Starlink\Auth\Session::flash('error', 'User not found.');
            redirect(route_path('admin/users'));
        }

        view('admin/user-detail', [
            'user' => $user,
            'profile' => $profile,
            'summary' => $this->users->spendingSummary($userId),
            'bookings' => $this->users->userBookings($userId),
            'payments' => $this->users->userPayments($userId),
        ]);
    }

    public function updateUserEmail(): void
    {
        $this->guard->handle(['admin']);
        $userId = (int) ($_POST['user_id'] ?? 0);
        $email = trim((string) ($_POST['email'] ?? ''));

        try {
            $this->users->updateCustomerEmail($userId, $email);
            \Starlink\Auth\Session::flash('success', 'Customer email updated.');
        } catch (\Starlink\Auth\AuthException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        redirect(route_path('admin/users/view') . '?id=' . $userId);
    }

    public function resetUserPassword(): void
    {
        $this->guard->handle(['admin']);
        $userId = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if ($password !== $confirm) {
            \Starlink\Auth\Session::flash('error', 'Passwords do not match.');
            redirect(route_path('admin/users/view') . '?id=' . $userId);
        }

        try {
            $this->users->resetPassword($userId, $password);
            \Starlink\Auth\Session::flash('success', 'Password updated.');
        } catch (\Starlink\Auth\AuthException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
        }

        redirect(route_path('admin/users/view') . '?id=' . $userId);
    }

    public function newUserForm(): void
    {
        $user = $this->guard->handle(['admin']);
        $role = (string) ($_GET['role'] ?? 'customer');

        if (!in_array($role, ['customer', 'admin', 'partner'], true)) {
            redirect(route_path('admin/users'));
        }

        view('admin/user-new', [
            'user' => $user,
            'role' => $role,
        ]);
    }

    public function createUser(): void
    {
        $this->guard->handle(['admin']);

        $role = (string) ($_POST['role'] ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if (!in_array($role, ['customer', 'admin', 'partner'], true)) {
            \Starlink\Auth\Session::flash('error', 'Invalid account role.');
            redirect(route_path('admin/users'));
        }

        if ($password !== $confirm) {
            \Starlink\Auth\Session::flash('error', 'Passwords do not match.');
            redirect(route_path('admin/users/new') . '?role=' . rawurlencode($role));
        }

        try {
            $userId = $this->users->createUser(
                $role,
                $name,
                $email,
                $password,
                $phone !== '' ? $phone : null,
            );
            \Starlink\Auth\Session::flash('success', ucfirst($role) . ' account created.');
            redirect(route_path('admin/users/view') . '?id=' . $userId);
        } catch (\Starlink\Auth\AuthException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
            redirect(route_path('admin/users/new') . '?role=' . rawurlencode($role));
        }
    }

    public function deleteUser(): void
    {
        $admin = $this->guard->handle(['admin']);
        $userId = (int) ($_POST['user_id'] ?? 0);
        $listPath = route_path('admin/users');

        try {
            $this->users->deleteUser($userId, (int) ($admin['id'] ?? 0));
            \Starlink\Auth\Session::flash('success', 'User account deleted.');
            redirect($listPath);
        } catch (\Starlink\Auth\AuthException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
            redirect($userId > 0 ? route_path('admin/users/view') . '?id=' . $userId : $listPath);
        }
    }

    public function pricing(): void
    {
        $user = $this->guard->handle(['admin']);
        $settings = $this->pricingConfig->allSettings();

        view('admin/pricing', [
            'user' => $user,
            'settings' => $settings,
            'tiers' => $this->pricingConfig->listTiers(),
            'rules' => $this->pricingConfig->listSpecialRules(),
        ]);
    }

    public function saveStagingSettings(): void
    {
        $this->guard->handle(['admin']);

        try {
            $this->pricingConfig->saveSettings([
                'staging_lead_days' => (string) max(0, min(14, (int) ($_POST['staging_lead_days'] ?? 1))),
                'inter_city_staging_lead_days' => (string) max(0, min(14, (int) ($_POST['inter_city_staging_lead_days'] ?? 2))),
            ]);
            PricingConfigService::clearCache();
            \Starlink\Auth\Session::flash('success', 'Staging lead times saved.');
        } catch (\Throwable $e) {
            \Starlink\Auth\Session::flash('error', 'Unable to save staging lead times.');
        }

        redirect(route_path('admin/staging'));
    }

    public function savePricingSettings(): void
    {
        $this->guard->handle(['admin']);

        try {
            $this->pricingConfig->saveSettings([
                'minimum_rental_days' => (string) max(1, (int) ($_POST['minimum_rental_days'] ?? 3)),
                'max_self_serve_days' => (string) max(1, (int) ($_POST['max_self_serve_days'] ?? 30)),
                'deposit_cents' => (string) (int) round(((float) ($_POST['deposit'] ?? 0)) * 100),
                'shipping_fee_cents' => (string) (int) round(((float) ($_POST['shipping'] ?? 0)) * 100),
                'shipping_lead_days' => (string) max(0, min(14, (int) ($_POST['shipping_lead_days'] ?? 1))),
                'shipping_arrival_lead_days' => (string) max(1, min(30, (int) ($_POST['shipping_arrival_lead_days'] ?? 7))),
                'city_delivery_radius_km' => (string) max(1, min(500, (int) ($_POST['city_delivery_radius_km'] ?? 50))),
                'city_delivery_fee_cents' => (string) (int) round(((float) ($_POST['city_delivery_fee'] ?? 25)) * 100),
                'long_term_contact_note' => trim((string) ($_POST['long_term_contact_note'] ?? '')),
            ]);
            PricingConfigService::clearCache();
            \Starlink\Auth\Session::flash('success', 'Pricing settings saved.');
        } catch (\Throwable $e) {
            \Starlink\Auth\Session::flash('error', 'Unable to save pricing settings.');
        }

        redirect(route_path('admin/pricing'));
    }

    public function savePricingTier(): void
    {
        $this->guard->handle(['admin']);
        $tierId = (int) ($_POST['id'] ?? 0);

        try {
            $this->pricingConfig->saveTier($_POST, $tierId > 0 ? $tierId : null);
            \Starlink\Auth\Session::flash('success', 'Pricing tier saved.');
        } catch (\Throwable $e) {
            \Starlink\Auth\Session::flash('error', 'Unable to save pricing tier.');
        }

        redirect(route_path('admin/pricing'));
    }

    public function savePricingRule(): void
    {
        $this->guard->handle(['admin']);
        $ruleId = (int) ($_POST['id'] ?? 0);

        try {
            $this->pricingConfig->saveSpecialRule($_POST, $ruleId > 0 ? $ruleId : null);
            \Starlink\Auth\Session::flash('success', 'Pricing rule saved.');
        } catch (\Throwable $e) {
            \Starlink\Auth\Session::flash('error', 'Unable to save pricing rule.');
        }

        redirect(route_path('admin/pricing'));
    }

    public function deletePricingRule(): void
    {
        $this->guard->handle(['admin']);

        try {
            $this->pricingConfig->deleteSpecialRule((int) ($_POST['id'] ?? 0));
            \Starlink\Auth\Session::flash('success', 'Pricing rule deleted.');
        } catch (\Throwable $e) {
            \Starlink\Auth\Session::flash('error', 'Unable to delete pricing rule.');
        }

        redirect(route_path('admin/pricing'));
    }

    public function longTermRequests(): void
    {
        $user = $this->guard->handle(['admin']);
        $statusFilter = (string) ($_GET['status'] ?? '');

        view('admin/long-term-requests', [
            'user' => $user,
            'requests' => $this->longTermRequests->listAll($statusFilter !== '' ? $statusFilter : null),
            'statusFilter' => $statusFilter,
        ]);
    }

    public function updateLongTermRequest(): void
    {
        $this->guard->handle(['admin']);

        try {
            $this->longTermRequests->updateStatus(
                (int) ($_POST['request_id'] ?? 0),
                (string) ($_POST['status'] ?? 'pending'),
                trim((string) ($_POST['admin_notes'] ?? '')) ?: null,
            );
            \Starlink\Auth\Session::flash('success', 'Request updated.');
        } catch (\Throwable $e) {
            \Starlink\Auth\Session::flash('error', 'Unable to update request.');
        }

        redirect(route_path('admin/long-term-requests'));
    }

    public function notifications(): void
    {
        $user = $this->guard->handle(['admin']);
        $rules = new NotificationRuleService();

        view('admin/notifications', [
            'user' => $user,
            'groups' => $rules->listRuleGroups(),
            'mailLive' => (new \Starlink\Services\BrevoMailService())->isConfigured(),
            'telegramLive' => (new \Starlink\Services\TelegramService())->isConfigured(),
            'previewUrl' => route_path('admin/notifications/preview'),
        ]);
    }

    public function saveNotifications(): void
    {
        $this->guard->handle(['admin']);

        try {
            (new NotificationRuleService())->saveRules($_POST);
            \Starlink\Auth\Session::flash('success', 'Notification rules saved.');
        } catch (\Throwable $e) {
            \Starlink\Auth\Session::flash('error', 'Unable to save notification rules.');
        }

        redirect(route_path('admin/notifications'));
    }

    public function notificationPreview(): void
    {
        $this->guard->handle(['admin']);

        $eventKey = trim((string) ($_GET['event_key'] ?? ''));
        $bookingId = max(0, (int) ($_GET['booking_id'] ?? 0));

        try {
            $preview = (new \Starlink\Services\NotificationPreviewService())->preview($eventKey, $bookingId);
        } catch (\InvalidArgumentException) {
            json_response(['error' => 'Unknown notification event.'], 404);
        }

        json_response($preview);
    }

    private function refundCentsFromPost(): ?int
    {
        if (!isset($_POST['refund_cents']) || trim((string) $_POST['refund_cents']) === '') {
            return null;
        }

        return max(0, (int) round(((float) $_POST['refund_cents']) * 100));
    }

    private function releaseDepositFromPost(): bool
    {
        return isset($_POST['release_deposit']) && (string) $_POST['release_deposit'] === '1';
    }
}

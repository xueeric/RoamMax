<?php

declare(strict_types=1);

use Starlink\Services\PricingService;

$pageTitle = 'Checkout';

$profile = $profile ?? [];
$homeAddress = $profile['home_address'] ?? [];
$shippingAddress = $profile['shipping_address'] ?? [];
$billingAddress = $profile['billing_address'] ?? [];
$needsShipping = in_array($fulfillmentType, ['mail_ship', 'city_delivery'], true);
$shippingLabel = $fulfillmentType === 'city_delivery' ? 'Delivery address' : 'Shipping address';
$requiresPayment = true;
$squareAvailable = $squareAvailable ?? false;
$etransferEmail = $etransferEmail ?? 'payments@roammax.ca';
?>
<?php
$fulfillmentLabel = match ($fulfillmentType) {
    'mail_ship' => 'Mail shipping',
    'city_delivery' => 'Local delivery',
    'pickup_appointment', 'pickup', 'home_appointment', 'store_pickup' => 'Pickup',
    default => str_replace('_', ' ', $fulfillmentType),
};
$pickupInfo = location_display()->preCheckoutPickupInfo($location, $fulfillmentType);
?>
<div class="micro-label">03. Checkout & Agreement</div>
<h1 class="page-title">Complete your booking</h1>
<p class="lead">
    <?php if ($fulfillmentType === 'mail_ship'): ?>
        Mail shipping · <span class="mono"><?= escape($startDate) ?> → <?= escape($endDate) ?></span>
    <?php else: ?>
        <?= escape(location_display()->areaLabel($location)) ?> · <?= escape($fulfillmentLabel) ?> ·
        <span class="mono"><?= escape($startDate) ?> → <?= escape($endDate) ?></span>
    <?php endif; ?>
</p>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-error"><?= escape($message) ?></div>
<?php endif; ?>

<?php if ($pickupInfo !== null): ?>
    <div class="location-pickup-info location-pickup-info-static">
        <div class="location-pickup-info-label"><?= escape($pickupInfo['label']) ?></div>
        <div class="location-pickup-info-name"><?= escape($pickupInfo['name']) ?></div>
        <?php if (!empty($pickupInfo['instructions'])): ?>
            <div class="location-pickup-info-instructions"><?= escape($pickupInfo['instructions']) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="calendar-layout">
    <div class="card">
        <form method="post" action="<?= escape(route_path('checkout')) ?>" class="form-grid" id="checkout-form">
    <?= csrf_field() ?>
            <input type="hidden" name="location_id" value="<?= (int) $location['id'] ?>">
            <input type="hidden" name="fulfillment_type" value="<?= escape($fulfillmentType) ?>">
            <input type="hidden" name="start_date" value="<?= escape($startDate) ?>">
            <input type="hidden" name="end_date" value="<?= escape($endDate) ?>">

            <div class="micro-label">Contact</div>
            <div class="form-row">
                <label>
                    <span>Full name</span>
                    <input type="text" name="contact_name" value="<?= escape($profile['name'] ?? $user['name']) ?>" required>
                </label>
                <label>
                    <span>Phone</span>
                    <input type="tel" name="contact_phone" value="<?= escape($profile['phone'] ?? $user['phone'] ?? '') ?>">
                </label>
            </div>
            <label>
                <span>Company name (optional)</span>
                <input type="text" name="company_name" value="<?= escape($profile['company_name'] ?? '') ?>">
            </label>

            <?php if ($fulfillmentType === 'mail_ship'): ?>
                <?php
                $daysUntilStart = (int) floor((strtotime($startDate) - strtotime(date('Y-m-d'))) / 86400);
                $arrivalLead = (int) pricing_config('shipping_arrival_lead_days', 7);
                ?>
                <?php if ($daysUntilStart < $arrivalLead): ?>
                    <div class="alert alert-error">
                        Your rental starts in <?= $daysUntilStart ?> day<?= $daysUntilStart === 1 ? '' : 's' ?>, but mail delivery usually needs about <?= $arrivalLead ?> days.
                        The kit may arrive after your booking start date.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            $prefix = 'home_';
            $address = $homeAddress;
            $includeName = false;
            $required = true;
            $sectionLabel = 'Home address (required for account security)';
            require WEBSITE_ROOT . '/templates/customer/_address-fields.php';
            ?>

            <?php if ($needsShipping): ?>
                <label class="checkbox-inline">
                    <input type="checkbox" id="ship-same-as-home" data-copy-source="home_" data-copy-target="shipping_">
                    <span><?= $fulfillmentType === 'city_delivery' ? 'Delivery address same as home address' : 'Shipping address same as home address' ?></span>
                </label>
                <?php
                $prefix = 'shipping_';
                $address = $shippingAddress;
                $includeName = true;
                $required = true;
                $sectionLabel = $shippingLabel;
                require WEBSITE_ROOT . '/templates/customer/_address-fields.php';
                ?>
            <?php endif; ?>

            <label class="checkbox-inline">
                <input type="checkbox" id="bill-same-as-shipping" data-copy-source="<?= $needsShipping ? 'shipping_' : 'home_' ?>" data-copy-target="billing_" data-toggle-target="billing-address-fields">
                <span>Billing address same as <?= $needsShipping ? 'shipping' : 'home' ?> address</span>
            </label>
            <?php
            $prefix = 'billing_';
            $address = $billingAddress;
            $includeName = false;
            $required = true;
            $sectionLabel = 'Billing address';
            $fieldsetId = 'billing-address-fields';
            require WEBSITE_ROOT . '/templates/customer/_address-fields.php';
            ?>

            <?php if ($addOns !== []): ?>
                <div>
                    <div class="micro-label">Add-ons</div>
                    <?php foreach ($addOns as $item): ?>
                        <label style="display:flex;justify-content:space-between;gap:1rem;align-items:center;margin-bottom:0.75rem;">
                            <span>
                                <strong><?= escape($item['name']) ?></strong><br>
                                <span class="lead" style="margin:0;font-size:0.75rem;">
                                    <?php if ($item['rental_price_cents_flat']): ?>
                                        <?= escape(PricingService::formatMoney((int) $item['rental_price_cents_flat'])) ?> flat
                                    <?php else: ?>
                                        <?= escape(PricingService::formatMoney((int) $item['rental_price_cents_per_day'])) ?>/day
                                    <?php endif; ?>
                                    · <?= (int) $item['quantity_available'] ?> available
                                </span>
                            </span>
                            <input type="number" min="0" max="<?= (int) $item['quantity_available'] ?>" name="addons[<?= (int) $item['id'] ?>]" value="0" style="width:5rem;" data-addon-input>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (in_array($fulfillmentType, ['pickup_appointment', 'home_appointment'], true)): ?>
                <div class="micro-label">Preferred pickup time</div>
                <p class="lead pickup-schedule-lead">
                    Choose when you are planning to pick up the kit. We will try to accommodate at our best.
                </p>
                <input type="hidden" name="pickup_date" value="<?= escape($startDate) ?>">
                <div class="pickup-schedule-row">
                    <span class="pickup-schedule-date">
                        Pickup date: <span class="mono"><?= escape($startDate) ?></span>
                    </span>
                    <label class="pickup-schedule-time">
                        <select name="pickup_time" class="pickup-time-select" required>
                            <option value="">Select time</option>
                            <?php for ($hour = 7; $hour <= 21; $hour++): ?>
                                <?php $timeValue = sprintf('%02d:00', $hour); ?>
                                <option value="<?= escape($timeValue) ?>"><?= escape(format_pickup_time($timeValue)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </div>
            <?php endif; ?>

            <label>
                <span>Notes (optional)</span>
                <textarea name="customer_notes"></textarea>
            </label>

            <div class="checkout-profile-pref">
                <label class="checkbox-inline">
                    <input type="checkbox" name="save_profile" value="1" checked>
                    <span>Remember these details for future bookings</span>
                </label>
                <p class="lead checkout-profile-pref-note">
                    Your addresses and contact info are always saved with this booking. Uncheck if you do not want these details pre-filled on future checkouts.
                </p>
            </div>

            <?php if ($requiresPayment): ?>
                <div class="payment-method-section">
                    <div class="micro-label">Payment method</div>
                    <?php if (!$squareAvailable && config('debug')): ?>
                        <p class="lead checkout-square-hint" style="font-size:0.75rem;margin:0 0 0.75rem;">
                            Card (Square) is hidden because Square is not configured in <code>.env</code>
                            (<code>SQUARE_ACCESS_TOKEN</code>, <code>SQUARE_APPLICATION_ID</code>, <code>SQUARE_LOCATION_ID</code>).
                        </p>
                    <?php endif; ?>
                    <div class="payment-method-options">
                        <?php if ($squareAvailable): ?>
                            <label class="payment-method-option">
                                <input type="radio" name="payment_method" value="square" checked required>
                                <span class="payment-method-copy">
                                    <strong>Pay by card (Square)</strong>
                                    <span>Card on file — rental charged now; deposit held or captured based on rental length.</span>
                                </span>
                            </label>
                        <?php endif; ?>
                        <label class="payment-method-option">
                            <input type="radio" name="payment_method" value="etransfer" <?= $squareAvailable ? '' : 'checked' ?> required>
                            <span class="payment-method-copy">
                                <strong>Interac e-Transfer</strong>
                                <span>Send payment to <?= escape($etransferEmail) ?> — we confirm once it arrives.</span>
                            </span>
                        </label>
                    </div>
                </div>
            <?php endif; ?>

            <div class="checkout-submit-block">
                <label class="checkout-agreement">
                    <input type="checkbox" name="agreement_accepted" value="1" required>
                    <span>
                        I have read and agree to the
                        <a href="<?= escape(route_path('agreement')) ?>" target="_blank" rel="noopener noreferrer" data-agreement-open>RoamMax Equipment Rental Agreement</a>.
                    </span>
                </label>
                <button class="btn btn-primary checkout-submit-btn" type="submit">Proceed to payment</button>
            </div>
        </form>
    </div>

    <?php require WEBSITE_ROOT . '/templates/customer/_quote-panel.php'; ?>
</div>

<div class="agreement-modal" id="checkout-agreement-modal" hidden>
    <div class="agreement-modal-backdrop" data-agreement-close tabindex="-1"></div>
    <div class="agreement-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="checkout-agreement-title">
        <div class="agreement-modal-header">
            <div>
                <div class="micro-label">Legal</div>
                <h2 id="checkout-agreement-title" class="agreement-modal-title">Rental agreement</h2>
            </div>
            <button type="button" class="agreement-modal-close" data-agreement-close aria-label="Close agreement">&times;</button>
        </div>
        <div class="agreement-modal-body">
            <?php require WEBSITE_ROOT . '/templates/customer/_agreement-body.php'; ?>
        </div>
        <div class="agreement-modal-footer">
            <a href="<?= escape(route_path('agreement')) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">Open in new tab</a>
            <button type="button" class="btn btn-primary" data-agreement-close>Close</button>
        </div>
    </div>
</div>

<script>
    window.STARLINK_CHECKOUT = {
        quoteUrl: <?= json_encode(route_path('quote'), JSON_THROW_ON_ERROR) ?>,
        locationId: <?= (int) $location['id'] ?>,
        fulfillmentType: <?= json_encode($fulfillmentType, JSON_THROW_ON_ERROR) ?>,
        startDate: <?= json_encode($startDate, JSON_THROW_ON_ERROR) ?>,
        endDate: <?= json_encode($endDate, JSON_THROW_ON_ERROR) ?>,
        locationProvince: <?= json_encode($location['province'] ?? 'AB', JSON_THROW_ON_ERROR) ?>,
        needsShipping: <?= $needsShipping ? 'true' : 'false' ?>,
    };
</script>
<script src="<?= escape(route_path('assets/js/checkout.js')) ?>" defer></script>

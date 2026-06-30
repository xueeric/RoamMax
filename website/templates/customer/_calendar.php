<?php

declare(strict_types=1);

$calendarMode = $calendarMode ?? 'checkout';
$calendarSubmitUrl = $calendarSubmitUrl ?? null;
$calendarAllowLongTerm = $calendarAllowLongTerm ?? false;
$calendarHeading = $calendarHeading ?? 'Check availability';
$calendarLead = $calendarLead ?? 'Choose your Alberta pickup city, then pickup or local delivery, and select your dates.';
$mailShipEnabled = (bool) config('customer_mail_ship_enabled', false);

$defaultLocationId = isset($defaultLocationId)
    ? (int) $defaultLocationId
    : (!empty($locations) ? (int) $locations[0]['id'] : 0);
$hideLocationDetails = ($calendarMode ?? 'checkout') === 'checkout';
if (!isset($calendarLocations)) {
    $calendarLocations = $hideLocationDetails
        ? location_display()->calendarLocations($locations ?? [])
        : location_display()->internalCalendarLocations($locations ?? []);
}
?>
<section class="home-section">
    <div class="section-heading" id="book">
        <h2 class="section-title"><?= escape($calendarHeading) ?></h2>
        <span class="micro-label"><?= match ($calendarMode) {
            'owner_block' => 'Personal use',
            'partner_personal' => 'Partner trip',
            default => 'Book online',
        } ?></span>
    </div>
    <p class="lead"><?= escape($calendarLead) ?></p>

    <div class="calendar-layout">
        <div class="card calendar-panel">
            <div class="calendar-controls">
                <label id="location-field">
                    <span>Location</span>
                    <select id="location-id">
                        <?php foreach ($calendarLocations as $location): ?>
                            <option
                                value="<?= (int) $location['id'] ?>"
                                <?= (int) $location['id'] === $defaultLocationId ? 'selected' : '' ?>
                            >
                                <?= escape($location['name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Fulfillment</span>
                    <select id="fulfillment-type">
                        <option value="pickup">Pickup</option>
                        <option value="city_delivery">Local delivery</option>
                        <?php if ($mailShipEnabled): ?>
                            <option value="mail_ship">Mail shipping (Canada)</option>
                        <?php endif; ?>
                    </select>
                </label>
                <div class="calendar-nav">
                    <button type="button" class="btn btn-secondary" id="prev-month" aria-label="Previous 5 weeks">&larr;</button>
                    <div id="calendar-month-label" class="calendar-month-label"></div>
                    <button type="button" class="btn btn-secondary" id="next-month" aria-label="Next 5 weeks">&rarr;</button>
                </div>
            </div>

            <div id="calendar-grid" class="calendar-grid" aria-live="polite"></div>

            <div class="calendar-legend">
                <span><i class="legend-dot legend-available"></i> Available start</span>
                <span><i class="legend-dot legend-blocked"></i> Blocked</span>
                <span><i class="legend-dot legend-selected"></i> Selected</span>
            </div>
        </div>

        <aside class="card calendar-sidebar">
            <div class="micro-label">Selection summary</div>
            <div id="selection-summary" class="selection-summary">
                Select a start date, then an end date (minimum <?= (int) $minimumRentalDays ?> days).
            </div>
            <div id="location-pickup-info" class="location-pickup-info" hidden></div>
            <div id="availability-result" class="availability-result"></div>
            <?php if ($calendarSubmitUrl): ?>
            <template id="starlink-calendar-reserve-template">
                <form method="post" action="<?= escape($calendarSubmitUrl) ?>" class="form-grid" style="margin-top:0.75rem;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="location_id" data-cal-field="location_id" value="">
                    <input type="hidden" name="fulfillment_type" data-cal-field="fulfillment_type" value="">
                    <input type="hidden" name="start_date" data-cal-field="start_date" value="">
                    <input type="hidden" name="end_date" data-cal-field="end_date" value="">
                    <label><span>Notes (optional)</span><textarea name="notes" rows="2" data-cal-notes placeholder=""></textarea></label>
                    <button class="btn btn-primary" type="submit" data-cal-submit>Confirm</button>
                </form>
            </template>
            <?php endif; ?>
            <?php if ($calendarMode === 'checkout'): ?>
            <template id="starlink-calendar-long-term-template">
                <form method="post" action="<?= escape(route_path('book/long-term-request')) ?>" style="margin-top:0.75rem;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="location_id" data-cal-field="location_id" value="">
                    <input type="hidden" name="fulfillment_type" data-cal-field="fulfillment_type" value="">
                    <input type="hidden" name="start_date" data-cal-field="start_date" value="">
                    <input type="hidden" name="end_date" data-cal-field="end_date" value="">
                    <button class="btn btn-primary" type="submit" data-cal-submit>Submit a quote</button>
                </form>
            </template>
            <?php endif; ?>
            <?php if ($calendarMode === 'checkout' && empty($user)): ?>
                <p class="lead" style="font-size:0.75rem;margin-top:1rem;margin-bottom:0;">
                    <a href="<?= escape(route_path('register')) ?>">Create an account</a> or
                    <a href="<?= escape(route_path('login')) ?>">sign in</a> to book online.
                </p>
            <?php endif; ?>
        </aside>
    </div>
</section>

<script>
    window.STARLINK_CALENDAR = {
        mode: <?= json_encode($calendarMode, JSON_THROW_ON_ERROR) ?>,
        submitUrl: <?= json_encode($calendarSubmitUrl, JSON_THROW_ON_ERROR) ?>,
        allowLongTerm: <?= $calendarAllowLongTerm ? 'true' : 'false' ?>,
        availabilityUrl: <?= json_encode(route_path('availability'), JSON_THROW_ON_ERROR) ?>,
        quoteUrl: <?= json_encode(route_path('quote'), JSON_THROW_ON_ERROR) ?>,
        partnerQuoteUrl: <?= json_encode(route_path('partner/personal-quote'), JSON_THROW_ON_ERROR) ?>,
        borrowPolicyMessage: <?= json_encode($borrowPolicyMessage ?? '', JSON_THROW_ON_ERROR) ?>,
        checkoutUrl: <?= json_encode(route_path('checkout'), JSON_THROW_ON_ERROR) ?>,
        longTermRequestUrl: <?= json_encode(route_path('book/long-term-request'), JSON_THROW_ON_ERROR) ?>,
        loginUrl: <?= json_encode(route_path('login'), JSON_THROW_ON_ERROR) ?>,
        minimumRentalDays: <?= (int) $minimumRentalDays ?>,
        maxSelfServeDays: <?= (int) $maxSelfServeDays ?>,
        longTermMessage: <?= json_encode($longTermMessage, JSON_THROW_ON_ERROR) ?>,
        shippingArrivalLeadDays: <?= (int) $shippingArrivalLeadDays ?>,
        cityDeliveryRadiusKm: <?= (int) $cityDeliveryRadiusKm ?>,
        cityDeliveryFeeCents: <?= (int) $cityDeliveryFeeCents ?>,
        defaultLocationId: <?= (int) $defaultLocationId ?>,
        isLoggedIn: <?= !empty($user) ? 'true' : 'false' ?>,
        canCheckout: <?= !empty($user) && (($user['role'] ?? '') === 'customer' || in_array($calendarMode, ['owner_block', 'partner_personal'], true)) ? 'true' : 'false' ?>,
        isAuthenticated: <?= !empty($user) ? 'true' : 'false' ?>,
        registerUrl: <?= json_encode(route_path('register'), JSON_THROW_ON_ERROR) ?>,
        csrfToken: <?= json_encode(csrf_token(), JSON_THROW_ON_ERROR) ?>,
        customerShippingProvince: <?= json_encode($customerShippingProvince ?? null, JSON_THROW_ON_ERROR) ?>,
        hideLocationDetails: <?= $hideLocationDetails ? 'true' : 'false' ?>,
        locations: <?= json_encode($calendarLocations, JSON_THROW_ON_ERROR) ?>,
        prefill: <?= json_encode([
            'location_id' => (int) ($_GET['location_id'] ?? 0),
            'fulfillment_type' => (string) ($_GET['fulfillment_type'] ?? ''),
            'start_date' => (string) ($_GET['start_date'] ?? ''),
            'end_date' => (string) ($_GET['end_date'] ?? ''),
            'long_term' => !empty($_GET['long_term']),
        ], JSON_THROW_ON_ERROR) ?>,
    };
</script>
<?php
$calendarJsPath = WEBSITE_ROOT . '/public/assets/js/calendar.js';
$calendarJsVersion = is_file($calendarJsPath) ? (string) filemtime($calendarJsPath) : '1';
?>
<script src="<?= escape(route_path('assets/js/calendar.js')) ?>?v=<?= escape($calendarJsVersion) ?>" defer></script>

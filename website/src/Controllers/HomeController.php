<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use PDO;
use Starlink\Auth\AuthService;
use Starlink\Database\Connection;
use Starlink\Services\CustomerProfileService;
use Starlink\Services\LocationSelectionService;
use Starlink\Services\PricingConfigService;
use Starlink\Services\PricingService;

final class HomeController
{
    private readonly PDO $db;

    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        ?PDO $db = null,
        private readonly PricingService $pricing = new PricingService(),
        private readonly PricingConfigService $pricingConfig = new PricingConfigService(),
        private readonly CustomerProfileService $profiles = new CustomerProfileService(),
        private readonly LocationSelectionService $locationSelection = new LocationSelectionService(),
    ) {
        $this->db = $db ?? Connection::get();
    }

    public function index(): void
    {
        $locations = $this->db->query(
            'SELECT id, slug, name, city, province, address, pickup_instructions, location_type, latitude, longitude
             FROM locations
             WHERE is_active = 1
             AND location_type IN (\'store\', \'home\')
             ORDER BY name ASC'
        )->fetchAll();

        $rules = array_values(array_filter(
            $this->pricingConfig->listSpecialRules(),
            static fn (array $rule): bool => (int) ($rule['is_active'] ?? 0) === 1,
        ));

        $user = $this->auth->user();
        $customerShippingProvince = null;
        $defaultLocationId = $this->locationSelection->defaultLocationIdForUser(
            $user !== null ? (int) $user['id'] : null,
            $locations,
            request_client_ip(),
        );
        if ($user !== null && ($user['role'] ?? '') === 'customer') {
            $customerShippingProvince = $this->profiles->shippingProvinceForQuote((int) $user['id']);
        }

        view('customer/home', [
            'user' => $user,
            'customerShippingProvince' => $customerShippingProvince,
            'defaultLocationId' => $defaultLocationId,
            'locations' => $locations,
            'pricingTiers' => $this->pricing->publicTiers(),
            'pricingRules' => $rules,
            'depositCents' => (int) pricing_config('deposit_cents', 35000),
            'shippingCents' => (int) pricing_config('shipping_fee_cents', 15000),
            'minimumRentalDays' => (int) pricing_config('minimum_rental_days', 3),
            'maxSelfServeDays' => (int) pricing_config('max_self_serve_days', 30),
            'longTermMessage' => (string) pricing_config(
                'long_term_contact_note',
                'Submit a quote for long-term commercial rates',
            ),
            'shippingArrivalLeadDays' => (int) pricing_config('shipping_arrival_lead_days', 7),
            'cityDeliveryRadiusKm' => (int) pricing_config('city_delivery_radius_km', 50),
            'cityDeliveryFeeCents' => (int) pricing_config('city_delivery_fee_cents', 2500),
            'stagingLeadDays' => (int) pricing_config('staging_lead_days', 1),
        ]);
    }
}

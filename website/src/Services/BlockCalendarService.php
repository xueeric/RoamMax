<?php

declare(strict_types=1);

namespace Starlink\Services;

use PDO;
use Starlink\Database\Connection;

final class BlockCalendarService
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::get();
    }

    /** @return array<string, mixed> */
    public function context(bool $allowLongTerm = false): array
    {
        $locations = $this->db->query(
            'SELECT id, slug, name, city, province, address, pickup_instructions, location_type
             FROM locations
             WHERE is_active = 1
             AND location_type IN (\'store\', \'home\')
             ORDER BY name ASC'
        )->fetchAll();

        return [
            'locations' => $locations,
            'minimumRentalDays' => (int) pricing_config('minimum_rental_days', 3),
            'maxSelfServeDays' => (int) pricing_config('max_self_serve_days', 30),
            'longTermMessage' => (string) pricing_config(
                'long_term_contact_note',
                'Rentals over 30 days require admin approval.',
            ),
            'shippingArrivalLeadDays' => (int) pricing_config('shipping_arrival_lead_days', 7),
            'cityDeliveryRadiusKm' => (int) pricing_config('city_delivery_radius_km', 50),
            'cityDeliveryFeeCents' => (int) pricing_config('city_delivery_fee_cents', 2500),
            'allowLongTerm' => $allowLongTerm,
        ];
    }
}

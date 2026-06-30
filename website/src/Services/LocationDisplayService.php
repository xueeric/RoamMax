<?php

declare(strict_types=1);

namespace Starlink\Services;

/**
 * Public-facing location labels before checkout.
 * Store names and street addresses are revealed only after booking is confirmed.
 */
final class LocationDisplayService
{
    private const PRE_CHECKOUT_PICKUP_NOTE = 'Exact pickup address and instructions are shared after you complete your booking.';

    /**
     * @param list<array<string, mixed>> $locations
     */
    public function publicLabel(array $location, array $locations): string
    {
        $city = $this->cityLabel($location);
        if (!$this->hasMultipleLocationsInCity($location, $locations)) {
            return $city;
        }

        return match ((string) ($location['location_type'] ?? 'store')) {
            'home' => $city . ' · Appointment pickup',
            default => $city . ' · Store pickup',
        };
    }

    public function areaLabel(array $location): string
    {
        return $this->cityLabel($location);
    }

    /**
     * @param list<array<string, mixed>> $locations
     * @return array<int, array<string, mixed>>
     */
    public function internalCalendarLocations(array $locations): array
    {
        $mapped = [];

        foreach ($locations as $location) {
            $id = (int) ($location['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $mapped[$id] = [
                'id' => $id,
                'name' => (string) ($location['name'] ?? ''),
                'address' => (string) ($location['address'] ?? ''),
                'city' => trim((string) ($location['city'] ?? '')),
                'province' => trim((string) ($location['province'] ?? '')),
                'location_type' => (string) ($location['location_type'] ?? 'store'),
                'pickup_instructions' => $location['pickup_instructions'] ?? null,
                'latitude' => isset($location['latitude']) && is_numeric($location['latitude'])
                    ? (float) $location['latitude']
                    : null,
                'longitude' => isset($location['longitude']) && is_numeric($location['longitude'])
                    ? (float) $location['longitude']
                    : null,
            ];
        }

        return $mapped;
    }

    /**
     * @param list<array<string, mixed>> $locations
     * @return array<int, array<string, mixed>>
     */
    public function calendarLocations(array $locations): array
    {
        $mapped = [];

        foreach ($locations as $location) {
            $id = (int) ($location['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $mapped[$id] = [
                'id' => $id,
                'public_label' => $this->publicLabel($location, $locations),
                'city' => trim((string) ($location['city'] ?? '')),
                'province' => trim((string) ($location['province'] ?? '')),
                'location_type' => (string) ($location['location_type'] ?? 'store'),
                'latitude' => isset($location['latitude']) && is_numeric($location['latitude'])
                    ? (float) $location['latitude']
                    : null,
                'longitude' => isset($location['longitude']) && is_numeric($location['longitude'])
                    ? (float) $location['longitude']
                    : null,
            ];
        }

        return $mapped;
    }

    /**
     * @return array{
     *   label: string,
     *   name: string,
     *   address: ?string,
     *   instructions: string
     * }|null
     */
    public function preCheckoutPickupInfo(array $location, string $fulfillmentType): ?array
    {
        $city = $this->cityLabel($location);
        $isHome = (string) ($location['location_type'] ?? 'store') === 'home';

        return match ($fulfillmentType) {
            'pickup', 'pickup_appointment', 'store_pickup', 'home_appointment' => [
                'label' => $isHome ? 'Pickup (appointment)' : 'Pickup location',
                'name' => $city,
                'address' => null,
                'instructions' => $isHome
                    ? 'Choose your preferred pickup time at checkout. ' . self::PRE_CHECKOUT_PICKUP_NOTE
                    : self::PRE_CHECKOUT_PICKUP_NOTE,
            ],
            'city_delivery' => [
                'label' => 'Local delivery area',
                'name' => $city . ' · within ' . (int) pricing_config('city_delivery_radius_km', 50) . ' km',
                'address' => null,
                'instructions' => 'Delivery fee based on your selected dates. Tax uses your delivery province.',
            ],
            'mail_ship' => [
                'label' => 'Mail shipping',
                'name' => 'Ships from the first available unit in our fleet',
                'address' => null,
                'instructions' => 'Availability is checked across all hubs. ' . self::PRE_CHECKOUT_PICKUP_NOTE,
            ],
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $locations
     */
    private function hasMultipleLocationsInCity(array $location, array $locations): bool
    {
        $city = trim((string) ($location['city'] ?? ''));
        if ($city === '') {
            return count($locations) > 1;
        }

        $matches = 0;
        foreach ($locations as $candidate) {
            if (trim((string) ($candidate['city'] ?? '')) === $city) {
                $matches++;
            }
        }

        return $matches > 1;
    }

    private function cityLabel(array $location): string
    {
        $city = trim((string) ($location['city'] ?? ''));
        if ($city !== '') {
            return $city;
        }

        return 'Your area';
    }
}

<?php

declare(strict_types=1);

namespace Starlink\Services;

/**
 * Public-facing location labels before checkout.
 * Location names are shown so customers can choose an area; street addresses
 * and detailed pickup instructions are revealed only after booking is confirmed.
 */
final class LocationDisplayService
{
    private const PRE_CHECKOUT_ADDRESS_NOTE = 'The exact address and pickup instructions are shared after you complete your booking.';

    public function areaLabel(array $location): string
    {
        return $this->locationName($location);
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
     * Customer calendar data: names visible, street addresses omitted.
     *
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
                'name' => $this->locationName($location),
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
        $name = $this->locationName($location);
        $isHome = (string) ($location['location_type'] ?? 'store') === 'home';

        return match ($fulfillmentType) {
            'pickup', 'pickup_appointment', 'store_pickup', 'home_appointment' => [
                'label' => $isHome ? 'Pickup (appointment)' : 'Pickup location',
                'name' => $name,
                'address' => null,
                'instructions' => $isHome
                    ? 'Choose your preferred pickup time at checkout. ' . self::PRE_CHECKOUT_ADDRESS_NOTE
                    : self::PRE_CHECKOUT_ADDRESS_NOTE,
            ],
            'city_delivery' => [
                'label' => 'Local delivery area',
                'name' => $name . ' · within ' . (int) pricing_config('city_delivery_radius_km', 50) . ' km',
                'address' => null,
                'instructions' => 'Delivery fee based on your selected dates. Tax uses your delivery province.',
            ],
            'mail_ship' => [
                'label' => 'Mail shipping',
                'name' => 'Ships from the first available unit in our fleet',
                'address' => null,
                'instructions' => 'Your selected area (' . $name . ') is for reference only. Availability is checked across all hubs.',
            ],
            default => null,
        };
    }

    private function locationName(array $location): string
    {
        $name = trim((string) ($location['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $this->cityLabel($location);
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

<?php

declare(strict_types=1);

namespace Starlink\Services;

final class LocationSelectionService
{
    public function __construct(
        private readonly CustomerProfileService $profiles = new CustomerProfileService(),
        private readonly GeocodingService $geocoder = new GeocodingService(),
        private readonly IpGeolocationService $ipGeolocation = new IpGeolocationService(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $locations
     */
    public function defaultLocationIdForUser(?int $userId, array $locations, ?string $clientIp = null): int
    {
        $fallbackId = $this->fallbackLocationId($locations);

        if ($locations === []) {
            return $fallbackId;
        }

        if ($userId !== null) {
            $fromProfile = $this->locationIdFromUserProfile($userId, $locations);
            if ($fromProfile !== null) {
                return $fromProfile;
            }
        }

        $fromIp = $this->locationIdFromIp($clientIp, $locations);
        if ($fromIp !== null) {
            return $fromIp;
        }

        return $fallbackId;
    }

    /**
     * @param list<array<string, mixed>> $locations
     */
    private function locationIdFromUserProfile(int $userId, array $locations): ?int
    {
        try {
            $profile = $this->profiles->getProfile($userId);
        } catch (BookingUnavailableException) {
            return null;
        }

        if (($profile['role'] ?? '') !== 'customer') {
            return null;
        }

        $selection = $this->addressForLocationSelection($profile);
        if ($selection === null) {
            return null;
        }

        $coords = $this->coordinatesForAddress(
            $userId,
            $selection['field'],
            $selection['address'],
        );
        if ($coords === null) {
            return null;
        }

        return $this->findNearestLocationId($locations, $coords['latitude'], $coords['longitude']);
    }

    /**
     * @param list<array<string, mixed>> $locations
     */
    private function locationIdFromIp(?string $clientIp, array $locations): ?int
    {
        if ($clientIp === null || trim($clientIp) === '') {
            return null;
        }

        $coords = $this->ipGeolocation->coordinatesForIp(trim($clientIp));
        if ($coords === null) {
            return null;
        }

        return $this->findNearestLocationId($locations, $coords['latitude'], $coords['longitude']);
    }

    /**
     * @param list<array<string, mixed>> $locations
     */
    public function findNearestLocationId(array $locations, float $latitude, float $longitude): ?int
    {
        $nearestId = null;
        $nearestDistance = null;

        foreach ($locations as $location) {
            $locationLat = $location['latitude'] ?? null;
            $locationLng = $location['longitude'] ?? null;
            if (!is_numeric($locationLat) || !is_numeric($locationLng)) {
                continue;
            }

            $distance = self::haversineKm(
                $latitude,
                $longitude,
                (float) $locationLat,
                (float) $locationLng,
            );

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearestId = (int) $location['id'];
            }
        }

        return $nearestId;
    }

    /**
     * @param list<array<string, mixed>> $locations
     */
    private function fallbackLocationId(array $locations): int
    {
        if ($locations === []) {
            return 0;
        }

        $slug = (string) config('default_home_location_slug', 'edmonton-north');
        foreach ($locations as $location) {
            if (($location['slug'] ?? '') === $slug) {
                return (int) $location['id'];
            }
        }

        return (int) $locations[0]['id'];
    }

    /**
     * @param array<string, mixed> $profile
     * @return ?array{field: string, address: array<string, string>}
     */
    private function addressForLocationSelection(array $profile): ?array
    {
        $home = $profile['home_address'] ?? null;
        if (is_array($home) && $this->isGeocodable($home)) {
            return ['field' => 'home', 'address' => $home];
        }

        $shipping = $profile['shipping_address'] ?? null;
        if (is_array($shipping) && $this->isGeocodable($shipping)) {
            return ['field' => 'shipping', 'address' => $shipping];
        }

        return null;
    }

    /** @param array<string, string> $address */
    private function isGeocodable(array $address): bool
    {
        if (($address['postal_code'] ?? '') !== '') {
            return true;
        }

        return ($address['line1'] ?? '') !== ''
            && ($address['city'] ?? '') !== ''
            && ($address['province'] ?? '') !== '';
    }

    /**
     * @param array<string, string> $address
     * @return ?array{latitude: float, longitude: float}
     */
    private function coordinatesForAddress(int $userId, string $field, array $address): ?array
    {
        $stored = $this->geocoder->coordinatesFromAddress($address);
        if ($stored !== null) {
            return $stored;
        }

        $geocoded = $this->geocoder->geocodeAddress($address);
        if ($geocoded === null) {
            return null;
        }

        $this->profiles->saveAddressCoordinates($userId, $field, $geocoded);

        return $geocoded;
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

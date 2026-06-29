<?php

declare(strict_types=1);

namespace Starlink\Services;

final class GeocodingService
{
    /**
     * @param array<string, mixed> $address
     * @return ?array{latitude: float, longitude: float}
     */
    public function geocodeAddress(array $address): ?array
    {
        $stored = $this->coordinatesFromAddress($address);
        if ($stored !== null) {
            return $stored;
        }

        $query = AddressService::formatSingleLine($address);
        if ($query === '') {
            return null;
        }

        return $this->geocodeQuery($query . ', Canada');
    }

    /**
     * @param array<string, mixed> $address
     * @return ?array{latitude: float, longitude: float}
     */
    public function coordinatesFromAddress(array $address): ?array
    {
        if (!isset($address['latitude'], $address['longitude'])) {
            return null;
        }

        if (!is_numeric($address['latitude']) || !is_numeric($address['longitude'])) {
            return null;
        }

        $latitude = (float) $address['latitude'];
        $longitude = (float) $address['longitude'];
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /** @return ?array{latitude: float, longitude: float} */
    public function geocodeQuery(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'ca',
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $mailFrom = (string) config('mail.from_email', 'noreply@roammax.ca');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: RoamMax/1.0 (' . $mailFrom . ')',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        $first = $decoded[0] ?? null;
        if (!is_array($first) || !isset($first['lat'], $first['lon'])) {
            return null;
        }

        if (!is_numeric($first['lat']) || !is_numeric($first['lon'])) {
            return null;
        }

        return [
            'latitude' => (float) $first['lat'],
            'longitude' => (float) $first['lon'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Starlink\Services;

final class IpGeolocationService
{
    /** @var array<string, ?array{latitude: float, longitude: float}> */
    private array $cache = [];

    /** @return ?array{latitude: float, longitude: float} */
    public function coordinatesForIp(string $ip): ?array
    {
        $ip = trim($ip);
        if ($ip === '' || !$this->isPublicIp($ip)) {
            return null;
        }

        if (array_key_exists($ip, $this->cache)) {
            return $this->cache[$ip];
        }

        $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,lat,lon';

        $ch = curl_init($url);
        if ($ch === false) {
            $this->cache[$ip] = null;

            return null;
        }

        $mailFrom = (string) config('mail.from_email', 'noreply@roammax.ca');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: RoamMax/1.0 (' . $mailFrom . ')',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            $this->cache[$ip] = null;

            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->cache[$ip] = null;

            return null;
        }

        if (!is_array($decoded)
            || ($decoded['status'] ?? '') !== 'success'
            || !isset($decoded['lat'], $decoded['lon'])
            || !is_numeric($decoded['lat'])
            || !is_numeric($decoded['lon'])) {
            $this->cache[$ip] = null;

            return null;
        }

        $latitude = (float) $decoded['lat'];
        $longitude = (float) $decoded['lon'];
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            $this->cache[$ip] = null;

            return null;
        }

        $this->cache[$ip] = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        return $this->cache[$ip];
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}

<?php

declare(strict_types=1);

namespace Starlink\Services;

final class AddressService
{
    /** @var array<string, string> */
    public const PROVINCES = [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];

    /** @return array<string, string> */
    public static function provinces(): array
    {
        return self::PROVINCES;
    }

    /** @return ?array<string, string> */
    public static function decode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        return self::normalize($decoded);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        $tax = new TaxService();

        $normalized = array_filter([
            'name' => trim((string) ($input['name'] ?? '')),
            'line1' => trim((string) ($input['line1'] ?? '')),
            'line2' => trim((string) ($input['line2'] ?? '')),
            'city' => trim((string) ($input['city'] ?? '')),
            'province' => $tax->normalizeProvince((string) ($input['province'] ?? '')),
            'postal_code' => strtoupper(str_replace(' ', '', trim((string) ($input['postal_code'] ?? '')))),
            'country' => strtoupper(trim((string) ($input['country'] ?? 'CA'))) ?: 'CA',
        ], static fn (string $value): bool => $value !== '');

        if (isset($input['latitude'], $input['longitude'])
            && is_numeric($input['latitude'])
            && is_numeric($input['longitude'])) {
            $latitude = (float) $input['latitude'];
            $longitude = (float) $input['longitude'];
            if ($latitude >= -90.0 && $latitude <= 90.0 && $longitude >= -180.0 && $longitude <= 180.0) {
                $normalized['latitude'] = (string) $latitude;
                $normalized['longitude'] = (string) $longitude;
            }
        }

        return $normalized;
    }

    public static function encode(?array $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $normalized = self::normalize($address);
        if ($normalized === []) {
            return null;
        }

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $requiredKeys
     */
    public static function validate(array $input, array $requiredKeys, string $label): void
    {
        $address = self::normalize($input);
        $missing = [];

        foreach ($requiredKeys as $key) {
            if (($address[$key] ?? '') === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new BookingUnavailableException("Complete the {$label} address.");
        }

        if (!isset(self::PROVINCES[$address['province'] ?? ''])) {
            throw new BookingUnavailableException("Select a valid province for the {$label} address.");
        }

        if (($address['country'] ?? 'CA') !== 'CA') {
            throw new BookingUnavailableException("{$label} address must be in Canada.");
        }
    }

    /** @param array<string, string> $address */
    public static function formatSingleLine(array $address): string
    {
        $parts = array_filter([
            $address['line1'] ?? '',
            $address['line2'] ?? '',
            trim(($address['city'] ?? '') . ', ' . ($address['province'] ?? '')),
            $address['postal_code'] ?? '',
        ]);

        return implode(', ', $parts);
    }

    /**
     * @param array<string, string> $source
     * @return array<string, string>
     */
    public static function copyWithoutName(array $source): array
    {
        $copy = self::normalize($source);
        unset($copy['name']);

        return $copy;
    }
}

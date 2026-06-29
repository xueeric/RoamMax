<?php

declare(strict_types=1);

namespace Starlink\Services;

final class TaxService
{
    /** @var array<string, array{rate: float, label: string}> */
    private const PROVINCE_RATES = [
        'AB' => ['rate' => 5.0, 'label' => 'GST'],
        'BC' => ['rate' => 12.0, 'label' => 'GST + PST'],
        'MB' => ['rate' => 12.0, 'label' => 'GST + PST'],
        'NB' => ['rate' => 15.0, 'label' => 'HST'],
        'NL' => ['rate' => 15.0, 'label' => 'HST'],
        'NS' => ['rate' => 15.0, 'label' => 'HST'],
        'NT' => ['rate' => 5.0, 'label' => 'GST'],
        'NU' => ['rate' => 5.0, 'label' => 'GST'],
        'ON' => ['rate' => 13.0, 'label' => 'HST'],
        'PE' => ['rate' => 15.0, 'label' => 'HST'],
        'QC' => ['rate' => 14.975, 'label' => 'GST + QST'],
        'SK' => ['rate' => 11.0, 'label' => 'GST + PST'],
        'YT' => ['rate' => 5.0, 'label' => 'GST'],
    ];

    /** @var array<string, string> */
    private const PROVINCE_ALIASES = [
        'ALBERTA' => 'AB',
        'BRITISH COLUMBIA' => 'BC',
        'MANITOBA' => 'MB',
        'NEW BRUNSWICK' => 'NB',
        'NEWFOUNDLAND AND LABRADOR' => 'NL',
        'NEWFOUNDLAND' => 'NL',
        'NOVA SCOTIA' => 'NS',
        'NORTHWEST TERRITORIES' => 'NT',
        'NUNAVUT' => 'NU',
        'ONTARIO' => 'ON',
        'PRINCE EDWARD ISLAND' => 'PE',
        'QUEBEC' => 'QC',
        'SASKATCHEWAN' => 'SK',
        'YUKON' => 'YT',
    ];

    public function normalizeProvince(string $province): string
    {
        return $this->resolveProvince($province) ?? 'AB';
    }

    public function resolveProvince(string $province): ?string
    {
        $value = strtoupper(trim($province));
        if ($value === '') {
            return null;
        }

        if (strlen($value) === 2 && isset(self::PROVINCE_RATES[$value])) {
            return $value;
        }

        return self::PROVINCE_ALIASES[$value] ?? null;
    }

    /**
     * Pickup and home appointments are always charged GST at 5%.
     *
     * @return array{
     *   tax_cents: int,
     *   tax_label: string,
     *   tax_rate_percent: float,
     *   tax_province: null
     * }
     */
    public function calculatePickup(int $taxableSubtotalCents): array
    {
        return [
            'tax_cents' => (int) round($taxableSubtotalCents * 0.05),
            'tax_label' => 'GST',
            'tax_rate_percent' => 5.0,
            'tax_province' => null,
        ];
    }

    /**
     * Mail shipping and local delivery tax follows the shipping address province.
     *
     * @return array{
     *   tax_cents: int,
     *   tax_label: string,
     *   tax_rate_percent: float,
     *   tax_province: string
     * }
     */
    public function calculateForShippingProvince(int $taxableSubtotalCents, string $province): array
    {
        $code = $this->resolveProvince($province);
        if ($code === null) {
            throw new BookingUnavailableException('Enter a valid Canadian province or territory.');
        }

        $config = self::PROVINCE_RATES[$code];

        if ($code === 'AB' || in_array($code, ['NT', 'NU', 'YT'], true)) {
            return [
                'tax_cents' => (int) round($taxableSubtotalCents * 0.05),
                'tax_label' => 'GST',
                'tax_rate_percent' => 5.0,
                'tax_province' => $code,
            ];
        }

        $taxCents = (int) round($taxableSubtotalCents * ($config['rate'] / 100));

        return [
            'tax_cents' => $taxCents,
            'tax_label' => $config['label'],
            'tax_rate_percent' => $config['rate'],
            'tax_province' => $code,
        ];
    }

    /**
     * @return array{
     *   tax_cents: int,
     *   tax_label: string,
     *   tax_rate_percent: float,
     *   tax_province: string
     * }
     */
    public function calculate(int $taxableSubtotalCents, string $province): array
    {
        $code = $this->resolveProvince($province);
        if ($code === null) {
            throw new BookingUnavailableException('Enter a valid Canadian province or territory.');
        }

        $config = self::PROVINCE_RATES[$code];
        $taxCents = (int) round($taxableSubtotalCents * ($config['rate'] / 100));

        return [
            'tax_cents' => $taxCents,
            'tax_label' => $config['label'],
            'tax_rate_percent' => $config['rate'],
            'tax_province' => $code,
        ];
    }
}

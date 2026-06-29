<?php

declare(strict_types=1);

namespace Starlink\Services;

use DateTimeImmutable;
use PDO;
use Starlink\Database\Connection;

final class PricingService
{
    private readonly PDO $db;
    private readonly PricingConfigService $config;

    public function __construct(?PDO $db = null, ?PricingConfigService $config = null)
    {
        $this->db = $db ?? Connection::get();
        $this->config = $config ?? new PricingConfigService($this->db);
    }

    /**
     * @param array<int, int> $addOnQuantities equipment_id (catalog) => quantity
     * @return array{
     *   days: int,
     *   daily_rate_cents: int,
     *   rental_total_cents: int,
     *   shipping_fee_cents: int,
     *   deposit_cents: int,
     *   add_ons_total_cents: int,
     *   add_ons: list<array<string, mixed>>,
     *   subtotal_before_tax_cents: int,
     *   tax_cents: int,
     *   tax_label: string,
     *   tax_rate_percent: ?float,
     *   tax_province: ?string,
     *   tax_determined: bool,
     *   total_due_cents: int,
     *   deposit_charged_at_checkout: bool,
     *   pricing_label: ?string
     * }
     */
    public function quote(
        int $locationId,
        string $fulfillmentType,
        string $startDate,
        string $endDate,
        array $addOnQuantities = [],
        bool $isAdminCreated = false,
        ?int $customDailyRateCents = null,
        ?int $customRentalTotalCents = null,
        ?string $taxProvince = null,
        ?bool $taxDetermined = null,
        ?string $paymentMethod = null,
    ): array {
        $start = parse_date($startDate);
        $end = parse_date($endDate);
        if ($start === null || $end === null || $start > $end) {
            throw new BookingUnavailableException('Invalid rental dates.');
        }

        $days = inclusive_day_count($start, $end);
        $minimumDays = (int) $this->config->getSetting('minimum_rental_days', (int) config('minimum_rental_days', 3));
        $periodMinimum = $this->resolvePeriodMinimum($start, $end);
        if ($periodMinimum !== null) {
            $minimumDays = max($minimumDays, $periodMinimum);
        }

        $maxSelfServeDays = (int) $this->config->getSetting('max_self_serve_days', (int) config('max_self_serve_days', 30));

        if (!$isAdminCreated && $days < $minimumDays) {
            throw new BookingUnavailableException("Minimum rental is {$minimumDays} days.");
        }

        if (!$isAdminCreated && $days > $maxSelfServeDays) {
            throw new BookingUnavailableException('Rentals over 30 days require admin booking.');
        }

        $pricingLabel = null;

        if ($isAdminCreated && $customRentalTotalCents !== null) {
            $rentalTotal = $customRentalTotalCents;
            $dailyRate = $days > 0 ? (int) round($rentalTotal / $days) : 0;
        } elseif ($isAdminCreated && $customDailyRateCents !== null) {
            $dailyRate = $customDailyRateCents;
            $rentalTotal = $dailyRate * $days;
        } else {
            $flatRate = $this->resolveWeekdayFlatRate($start, $end, $days);
            if ($flatRate !== null) {
                $rentalTotal = $flatRate['rental_total_cents'];
                $dailyRate = $flatRate['daily_rate_cents'];
                $pricingLabel = $flatRate['label'];
            } else {
                $tier = $this->resolveTier($days);
                if ($tier === null) {
                    throw new BookingUnavailableException('No pricing tier matches this duration.');
                }
                if (($tier['pricing_mode'] ?? 'daily') === 'custom_contact') {
                    throw new BookingUnavailableException(
                        (string) $this->config->getSetting(
                            'long_term_contact_note',
                            'Submit a quote for long-term commercial rates',
                        ),
                    );
                }
                $dailyRate = (int) $tier['rate_cents_per_day'];
                $rentalTotal = $dailyRate * $days;
                $pricingLabel = $tier['label'] ?? null;
            }
        }

        $shippingFee = match ($fulfillmentType) {
            'mail_ship' => (int) $this->config->getSetting('shipping_fee_cents', (int) config('shipping_fee_cents', 15000)),
            'city_delivery' => (int) $this->config->getSetting('city_delivery_fee_cents', 2500),
            default => 0,
        };
        $deposit = (int) $this->config->getSetting('deposit_cents', (int) config('deposit_cents', 35000));
        $addOns = $this->calculateAddOns($locationId, $addOnQuantities, $days, $startDate, $endDate);
        $addOnsTotal = array_sum(array_column($addOns, 'line_total_cents'));

        $subtotalBeforeTax = $rentalTotal + $shippingFee + $addOnsTotal;
        $taxContext = $this->resolveTaxContext($fulfillmentType, $locationId, $subtotalBeforeTax, $taxProvince, $taxDetermined);

        $checkoutTotals = $this->resolveCheckoutTotals(
            $days,
            $deposit,
            $subtotalBeforeTax + $taxContext['tax_cents'],
            $paymentMethod,
        );
        $depositChargedAtCheckout = $checkoutTotals['deposit_charged_at_checkout'];
        $totalDue = $checkoutTotals['total_due_cents'];

        return [
            'days' => $days,
            'daily_rate_cents' => $dailyRate,
            'rental_total_cents' => $rentalTotal,
            'shipping_fee_cents' => $shippingFee,
            'deposit_cents' => $deposit,
            'add_ons_total_cents' => $addOnsTotal,
            'add_ons' => $addOns,
            'subtotal_before_tax_cents' => $subtotalBeforeTax,
            'tax_cents' => $taxContext['tax_cents'],
            'tax_label' => $taxContext['tax_label'],
            'tax_rate_percent' => $taxContext['tax_rate_percent'],
            'tax_province' => $taxContext['tax_province'],
            'tax_determined' => $taxContext['tax_determined'],
            'total_due_cents' => $totalDue,
            'deposit_charged_at_checkout' => $depositChargedAtCheckout,
            'pricing_label' => $pricingLabel,
        ];
    }

    /**
     * @return array{deposit_charged_at_checkout: bool, total_due_cents: int}
     */
    private function resolveCheckoutTotals(
        int $days,
        int $deposit,
        int $amountBeforeDeposit,
        ?string $paymentMethod,
    ): array {
        unset($paymentMethod);

        // Short-term rentals defer deposit until T-24h; long-term collects deposit at checkout.
        $squareShortMaxDays = (int) config('payments.square_short_max_rental_days', 4);
        $depositChargedAtCheckout = $days > $squareShortMaxDays;

        return [
            'deposit_charged_at_checkout' => $depositChargedAtCheckout,
            'total_due_cents' => $amountBeforeDeposit + ($depositChargedAtCheckout ? $deposit : 0),
        ];
    }

    /**
     * Re-check add-on inventory inside a transaction (TOCTOU guard).
     *
     * @param array<int, int> $addOnQuantities
     * @param list<array<string, mixed>> $expectedLines
     */
    public function assertAddOnsStillAvailable(
        int $locationId,
        array $addOnQuantities,
        int $days,
        string $startDate,
        string $endDate,
        array $expectedLines,
    ): void {
        if ($expectedLines === []) {
            return;
        }

        $fresh = $this->calculateAddOns($locationId, $addOnQuantities, $days, $startDate, $endDate);
        if (count($fresh) !== count($expectedLines)) {
            throw new BookingUnavailableException('Insufficient add-on inventory.');
        }

        foreach ($expectedLines as $index => $line) {
            $current = $fresh[$index] ?? null;
            if (
                $current === null
                || (int) ($current['equipment_id'] ?? 0) !== (int) ($line['equipment_id'] ?? 0)
                || (int) ($current['quantity'] ?? 0) !== (int) ($line['quantity'] ?? 0)
            ) {
                throw new BookingUnavailableException('Insufficient add-on inventory.');
            }
        }
    }

    /** @return array<string, mixed> */
    public static function formatQuote(array $quote): array
    {
        $quote['formatted'] = [
            'rental_total' => self::formatMoney($quote['rental_total_cents']),
            'shipping_fee' => self::formatMoney($quote['shipping_fee_cents']),
            'deposit' => self::formatMoney($quote['deposit_cents']),
            'add_ons_total' => self::formatMoney($quote['add_ons_total_cents']),
            'tax' => ($quote['tax_determined'] ?? true)
                ? self::formatMoney($quote['tax_cents'])
                : 'To be determined',
            'total_due' => self::formatMoney($quote['total_due_cents']),
        ];

        return $quote;
    }

    /**
     * @return array{
     *   tax_cents: int,
     *   tax_label: string,
     *   tax_rate_percent: ?float,
     *   tax_province: ?string,
     *   tax_determined: bool
     * }
     */
    private function resolveTaxContext(
        string $fulfillmentType,
        int $locationId,
        int $taxableSubtotalCents,
        ?string $taxProvince,
        ?bool $taxDetermined,
    ): array {
        if ($taxDetermined === false) {
            return [
                'tax_cents' => 0,
                'tax_label' => 'Tax',
                'tax_rate_percent' => null,
                'tax_province' => null,
                'tax_determined' => false,
            ];
        }

        if (in_array($fulfillmentType, ['pickup', 'pickup_appointment', 'store_pickup', 'home_appointment'], true)) {
            $tax = (new TaxService())->calculatePickup($taxableSubtotalCents);

            return [
                'tax_cents' => $tax['tax_cents'],
                'tax_label' => $tax['tax_label'],
                'tax_rate_percent' => $tax['tax_rate_percent'],
                'tax_province' => $tax['tax_province'],
                'tax_determined' => true,
            ];
        }

        $province = trim((string) ($taxProvince ?? ''));
        if ($province === '' && in_array($fulfillmentType, ['mail_ship', 'city_delivery'], true)) {
            return [
                'tax_cents' => 0,
                'tax_label' => 'Tax',
                'tax_rate_percent' => null,
                'tax_province' => null,
                'tax_determined' => false,
            ];
        }

        if ($province === '') {
            return [
                'tax_cents' => 0,
                'tax_label' => 'Tax',
                'tax_rate_percent' => null,
                'tax_province' => null,
                'tax_determined' => false,
            ];
        }

        try {
            $tax = (new TaxService())->calculateForShippingProvince($taxableSubtotalCents, $province);

            return [
                'tax_cents' => $tax['tax_cents'],
                'tax_label' => $tax['tax_label'],
                'tax_rate_percent' => $tax['tax_rate_percent'],
                'tax_province' => $tax['tax_province'],
                'tax_determined' => true,
            ];
        } catch (BookingUnavailableException) {
            return [
                'tax_cents' => 0,
                'tax_label' => 'Tax',
                'tax_rate_percent' => null,
                'tax_province' => null,
                'tax_determined' => false,
            ];
        }
    }

    /** @return list<array<string, mixed>> */
    public function publicTiers(): array
    {
        return array_values(array_filter(
            $this->config->listTiers(),
            static fn (array $tier): bool => (int) ($tier['is_active'] ?? 0) === 1,
        ));
    }

    /** @return ?array<string, mixed> */
    private function resolveTier(int $days): ?array
    {
        $stmt = $this->db->query(
            'SELECT * FROM pricing_tiers
             WHERE is_active = 1 AND pricing_mode = \'daily\'
             ORDER BY sort_order ASC, min_days ASC'
        );

        foreach ($stmt->fetchAll() as $tier) {
            $minDays = (int) $tier['min_days'];
            $maxDays = $tier['max_days'] !== null ? (int) $tier['max_days'] : null;

            if ($days >= $minDays && ($maxDays === null || $days <= $maxDays)) {
                return $tier;
            }
        }

        return null;
    }

    private function resolvePeriodMinimum(DateTimeImmutable $start, DateTimeImmutable $end): ?int
    {
        $stmt = $this->db->query(
            "SELECT * FROM pricing_special_rules
             WHERE is_active = 1 AND rule_type = 'period_minimum'
             ORDER BY sort_order ASC, id ASC"
        );

        foreach ($stmt->fetchAll() as $rule) {
            $periodStart = parse_date((string) $rule['period_start']);
            $periodEnd = parse_date((string) $rule['period_end']);
            if ($periodStart === null || $periodEnd === null) {
                continue;
            }

            if ($start <= $periodEnd && $end >= $periodStart) {
                return (int) $rule['min_booking_days'];
            }
        }

        return null;
    }

    /** @return ?array{rental_total_cents:int, daily_rate_cents:int, label:string} */
    private function resolveWeekdayFlatRate(DateTimeImmutable $start, DateTimeImmutable $end, int $days): ?array
    {
        $stmt = $this->db->query(
            "SELECT * FROM pricing_special_rules
             WHERE is_active = 1 AND rule_type = 'weekday_flat'
             ORDER BY sort_order ASC, id ASC"
        );

        $startWeekday = (int) $start->format('w');
        $endWeekday = (int) $end->format('w');

        foreach ($stmt->fetchAll() as $rule) {
            if ($rule['start_weekday'] !== null && (int) $rule['start_weekday'] !== $startWeekday) {
                continue;
            }
            if ($rule['end_weekday'] !== null && (int) $rule['end_weekday'] !== $endWeekday) {
                continue;
            }
            if ($rule['exact_day_count'] !== null && (int) $rule['exact_day_count'] !== $days) {
                continue;
            }

            $flatRate = (int) ($rule['flat_rate_cents'] ?? 0);
            if ($flatRate <= 0) {
                continue;
            }

            return [
                'rental_total_cents' => $flatRate,
                'daily_rate_cents' => $days > 0 ? (int) round($flatRate / $days) : $flatRate,
                'label' => (string) $rule['label'],
            ];
        }

        return null;
    }

    /**
     * @param array<int, int> $addOnQuantities
     * @return list<array<string, mixed>>
     */
    private function calculateAddOns(
        int $locationId,
        array $addOnQuantities,
        int $days,
        string $startDate,
        string $endDate,
    ): array {
        if ($addOnQuantities === []) {
            return [];
        }

        $equipment = new EquipmentService($this->db);
        $lines = [];
        foreach ($addOnQuantities as $itemId => $quantity) {
            $quantity = (int) $quantity;
            if ($quantity <= 0) {
                continue;
            }

            $item = $equipment->findCatalogRepresentative((int) $itemId, $locationId);
            if (!$item) {
                throw new BookingUnavailableException('Selected add-on is unavailable at this location.');
            }

            $catalogKey = $equipment->catalogKey($item);
            $available = $equipment->availableAccessoryCount($locationId, $catalogKey, $startDate, $endDate);
            if ($available < $quantity) {
                throw new BookingUnavailableException('Insufficient add-on inventory.');
            }

            $unitPrice = (int) ($item['rental_price_cents_flat'] ?? 0);
            if ($unitPrice <= 0) {
                $unitPrice = (int) ($item['rental_price_cents_per_day'] ?? 0);
                $lineTotal = $unitPrice * $days * $quantity;
            } else {
                $lineTotal = $unitPrice * $quantity;
            }

            $lines[] = [
                'equipment_id' => (int) $item['id'],
                'name' => (string) $item['nickname'],
                'quantity' => $quantity,
                'unit_price_cents' => $unitPrice,
                'line_total_cents' => $lineTotal,
            ];
        }

        return $lines;
    }

    public static function formatMoney(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}

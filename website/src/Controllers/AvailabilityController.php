<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use Starlink\Services\AvailabilityService;

final class AvailabilityController
{
    public function __construct(
        private readonly AvailabilityService $availability = new AvailabilityService(),
    ) {
    }

    public function show(): void
    {
        $locationId = (int) ($_GET['location_id'] ?? 0);
        $fulfillmentType = \Starlink\Booking\BookingStatuses::normalizeFulfillmentType((string) ($_GET['fulfillment_type'] ?? 'pickup'));

        if ($locationId <= 0 && $fulfillmentType !== 'mail_ship') {
            json_response(['error' => 'location_id is required.'], 422);
        }

        if (!in_array($fulfillmentType, \Starlink\Booking\BookingStatuses::FULFILLMENT_TYPES, true)) {
            json_response(['error' => 'Invalid fulfillment_type.'], 422);
        }

        $rangeStart = (string) ($_GET['start'] ?? today_date()->format('Y-m-d'));
        $rangeEnd = (string) ($_GET['end'] ?? today_date()->modify('+60 days')->format('Y-m-d'));
        $startDate = isset($_GET['start_date']) ? (string) $_GET['start_date'] : null;
        $endDate = isset($_GET['end_date']) ? (string) $_GET['end_date'] : null;

        $availabilityLocationId = $fulfillmentType === 'mail_ship' ? 0 : $locationId;

        $days = $this->availability->calendarStartDays($availabilityLocationId, $fulfillmentType, $rangeStart, $rangeEnd);
        $fleetSize = $this->availability->fleetCapacity($fulfillmentType);

        $payload = [
            'location_id' => $locationId,
            'fulfillment_type' => $fulfillmentType,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'minimum_rental_days' => (int) pricing_config('minimum_rental_days', 3),
            'max_self_serve_days' => (int) pricing_config('max_self_serve_days', 30),
            'earliest_start' => $this->availability->globalEarliestStart($availabilityLocationId, $fulfillmentType),
            'shipping_arrival_lead_days' => (int) pricing_config('shipping_arrival_lead_days', 7),
            'fleet_size' => $fleetSize,
            'days' => $days,
        ];

        if ($startDate !== null && $endDate !== null) {
            $payload['range_available'] = $this->availability->isRangeAvailable(
                $availabilityLocationId,
                $fulfillmentType,
                $startDate,
                $endDate,
            );
            $payload['capacity_remaining'] = $this->availability->remainingCapacity(
                $startDate,
                $endDate,
                $fulfillmentType,
            );
            $payload['effective_location_id'] = $availabilityLocationId > 0
                ? $availabilityLocationId
                : $locationId;
        }

        json_response($payload);
    }
}

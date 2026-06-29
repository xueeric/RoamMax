<?php

declare(strict_types=1);

namespace Starlink\Services;

final class AssignmentService
{
    public function __construct(
        private readonly AvailabilityService $availability = new AvailabilityService(),
    ) {
    }

    /**
     * @param array<string, mixed> $booking
     * @return list<array<string, mixed>>
     */
    public function eligibleUnitsForBooking(array $booking): array
    {
        return $this->availability->eligibleUnitsForBooking($booking);
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Registration;

/**
 * The sole registration-clearance enforcement point for every check-in writer.
 * Event 7 fails closed when Portal cannot affirm the exact attendee binding.
 */
class GvsuRegistrationCheckInClearanceService
{
    public function __construct(private readonly GvsuRegistrationBridgeService $bridge) {}

    public function assertAttendeeCleared(int $eventId, string $attendeePublicId): void
    {
        $this->bridge->assertCheckInClearance($eventId, $attendeePublicId);
    }
}

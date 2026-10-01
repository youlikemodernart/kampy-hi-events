<?php

namespace Tests\Unit\Services\Domain\Message;

use HiEvents\Services\Domain\Message\EventReminderRecipientClaimService;
use Tests\TestCase;

class EventReminderRecipientClaimServiceTest extends TestCase
{
    public function testNormalizesMixedCaseRecipients(): void
    {
        $service = app(EventReminderRecipientClaimService::class);

        $this->assertSame('attendee@example.com', $service->normalize(' Attendee@Example.COM '));
    }

    public function testRejectsInvalidRecipient(): void
    {
        $this->assertNull(app(EventReminderRecipientClaimService::class)->normalize('not-an-email'));
    }
}

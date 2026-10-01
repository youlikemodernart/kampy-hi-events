<?php

namespace Tests\Unit\Jobs\Message;

use HiEvents\Jobs\Message\SendEventReminderRecipientJob;
use HiEvents\Services\Domain\Message\EventReminderDispatchService;
use Mockery as m;
use Tests\TestCase;

class SendEventReminderRecipientJobTest extends TestCase
{
    public function test_hands_a_durable_recipient_claim_to_the_provider_boundary(): void
    {
        $dispatch = m::mock(EventReminderDispatchService::class);
        $dispatch->shouldReceive('sendRecipient')->once()->with(42);

        (new SendEventReminderRecipientJob(42))->handle($dispatch);
    }

    public function test_has_one_automatic_attempt(): void
    {
        $this->assertSame(1, (new SendEventReminderRecipientJob(42))->tries);
    }
}

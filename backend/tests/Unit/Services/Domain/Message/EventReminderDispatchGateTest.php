<?php

namespace Tests\Unit\Services\Domain\Message;

use HiEvents\Services\Domain\Message\EventReminderDispatchGate;
use Tests\TestCase;

class EventReminderDispatchGateTest extends TestCase
{
    public function testDefaultConfigurationFailsClosed(): void
    {
        config()->set('event-reminders.enabled', false);
        $this->assertFalse(app(EventReminderDispatchGate::class)->mayClaimRecipients());
    }

    public function testMissingActivationBindingFailsClosed(): void
    {
        config()->set('event-reminders.enabled', true);
        config()->set('event-reminders.reply_to', null);
        config()->set('event-reminders.physical_address', 'Kamp Love');
        config()->set('event-reminders.preference_url', 'https://example.test/preferences');

        $this->assertFalse(app(EventReminderDispatchGate::class)->mayClaimRecipients());
    }
}

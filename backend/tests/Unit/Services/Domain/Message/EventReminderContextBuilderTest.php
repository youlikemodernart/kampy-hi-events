<?php

namespace Tests\Unit\Services\Domain\Message;

use HiEvents\Mail\Event\EventReminder;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Services\Domain\Message\EventReminderContextBuilder;
use Tests\TestCase;

class EventReminderContextBuilderTest extends TestCase
{
    public function test_operational_context_renders_without_invented_footer_or_promotional_copy(): void
    {
        $event = $this->event();
        $context = app(EventReminderContextBuilder::class)->build($event);

        $this->assertNotNull($context);
        $this->assertNull($context->physicalAddress);
        $this->assertNull($context->preferenceUrl);
        $mail = new EventReminder($context);
        $this->assertSame('Reminder: Grand Valley State University is coming up', $mail->envelope()->subject);
        $this->assertSame('tickets@kamplove.org', $mail->envelope()->from->address);
        $this->assertSame('tickets@kamplove.org', $mail->envelope()->replyTo[0]->address);
        $html = $mail->render();
        $text = view($mail->content()->text, $mail->content()->with)->render();
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Grand Valley State University', $body);
            $this->assertStringContainsString('America/Detroit', $body);
            $this->assertStringContainsString('View event details', $body);
            $this->assertStringContainsString('tickets@kamplove.org', $body);
            foreach (['Email preferences', 'Unsubscribe', 'Donate', 'Register now', 'Buy now'] as $absent) {
                $this->assertStringNotContainsStringIgnoringCase($absent, $body);
            }
        }
        $this->assertStringNotContainsString('border-top:1px solid #d8dbe2;', $html);
        $this->assertStringContainsString('#0032a0', $html);
    }

    public function test_real_optional_footer_bindings_are_preserved(): void
    {
        $event = $this->event();
        config()->set('event-reminders.physical_address', '1 Fixture Way');
        config()->set('event-reminders.preference_url', 'https://example.test/preferences');
        $context = app(EventReminderContextBuilder::class)->build($event);

        $this->assertSame('1 Fixture Way', $context->physicalAddress);
        $this->assertSame('https://example.test/preferences', $context->preferenceUrl);
    }

    public function test_missing_reply_to_or_support_still_prevents_context_creation(): void
    {
        $event = $this->event();
        config()->set('event-reminders.reply_to', null);
        $this->assertNull(app(EventReminderContextBuilder::class)->build($event));
        config()->set('event-reminders.reply_to', 'tickets@kamplove.org');
        $event->event_settings->support_email = null;
        $this->assertNull(app(EventReminderContextBuilder::class)->build($event));
    }

    private function event(): Event
    {
        config()->set('app.frontend_url', 'https://tickets.example.test');
        config()->set('event-reminders.reply_to', 'tickets@kamplove.org');
        config()->set('event-reminders.physical_address', null);
        config()->set('event-reminders.preference_url', null);
        $event = new Event;
        $event->forceFill([
            'id' => 900001,
            'title' => 'Grand Valley State University',
            'start_date' => '2026-10-12 12:00:00',
            'timezone' => 'America/Detroit',
        ]);
        $event->setRelation('event_settings', (new EventSetting)->forceFill(['support_email' => 'tickets@kamplove.org']));

        return $event;
    }
}

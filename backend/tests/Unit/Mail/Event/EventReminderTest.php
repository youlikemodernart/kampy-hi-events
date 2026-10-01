<?php

namespace Tests\Unit\Mail\Event;

use HiEvents\Mail\Event\EventReminder;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use HiEvents\Services\Domain\Message\DTO\EventReminderContext;
use Tests\TestCase;

class EventReminderTest extends TestCase
{
    public function testHtmlAndTextContainTheSameRequiredFacts(): void
    {
        $context = new EventReminderContext(
            'Kamp Love GVSU', 'https://tickets.kamplove.org/event/7/grand-valley-state-university',
            'October 8, 2026 7:00 PM', 'America/Detroit', 'Kirkhof Center', 'support@kamplove.org',
            'tickets@kamplove.org', 'support@kamplove.org', 'Kamp Love', 'https://tickets.kamplove.org/preferences',
            'Kamp Love GVSU — October 8, 2026 7:00 PM America/Detroit',
            new UniversityEmailThemeDTO('#0032a0', '#13155c', '#ffffff', '#ffffff', '#e7e7ed'),
        );
        $mail = new EventReminder($context);
        $html = $mail->render();
        $text = view('emails.event.reminder-text', ['context' => $context->payload(), 'theme' => $context->theme])->render();

        foreach (['https://tickets.kamplove.org/event/7/grand-valley-state-university', 'support@kamplove.org', 'Kamp Love GVSU'] as $fact) {
            $this->assertStringContainsString($fact, $html);
            $this->assertStringContainsString($fact, $text);
        }
        $this->assertStringContainsString('#0032a0', $html);
        $this->assertStringContainsString('#13155c', $html);
    }
}

<?php

namespace Tests\Unit\Mail\Event;

use HiEvents\Mail\Event\EventReminder;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use Tests\TestCase;

class EventReminderTest extends TestCase
{
    public function testHtmlAndTextContainTheSameEventDestination(): void
    {
        config()->set('event-reminders.reply_to', 'support@kamplove.org');
        $mail = new EventReminder([
            'event_title' => 'Kamp Love GVSU',
            'local_start' => 'October 8, 2026 7:00 PM',
            'timezone' => 'America/Detroit',
            'location' => 'Kirkhof Center',
            'event_url' => 'https://tickets.kamplove.org/event/7/grand-valley-state-university',
            'physical_address' => 'Kamp Love',
            'preference_url' => 'https://tickets.kamplove.org/preferences',
        ], new UniversityEmailThemeDTO('#0032a0', '#13155c', '#ffffff', '#ffffff', '#e7e7ed'));

        $html = $mail->render();
        $text = view('emails.event.reminder-text', $mail->content()->with)->render();

        $this->assertStringContainsString('https://tickets.kamplove.org/event/7/grand-valley-state-university', $html);
        $this->assertStringContainsString('https://tickets.kamplove.org/event/7/grand-valley-state-university', $text);
        $this->assertStringContainsString('#0032a0', $html);
        $this->assertStringContainsString('#13155c', $html);
    }
}

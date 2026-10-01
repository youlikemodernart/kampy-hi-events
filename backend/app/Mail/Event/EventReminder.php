<?php

namespace HiEvents\Mail\Event;

use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EventReminder extends BaseMail
{
    public function __construct(
        private readonly array $context,
        private readonly UniversityEmailThemeDTO $theme,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('tickets@kamplove.org', 'Kamp Love'),
            replyTo: (string) config('event-reminders.reply_to'),
            subject: __('Reminder: :event is coming up', ['event' => $this->context['event_title']]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event.reminder',
            text: 'emails.event.reminder-text',
            with: ['context' => $this->context, 'theme' => $this->theme],
        );
    }
}

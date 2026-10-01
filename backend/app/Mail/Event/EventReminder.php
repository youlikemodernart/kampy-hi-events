<?php

namespace HiEvents\Mail\Event;

use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Message\DTO\EventReminderContext;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EventReminder extends BaseMail
{
    public function __construct(private readonly EventReminderContext $context)
    {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->context->sender, 'Kamp Love'),
            replyTo: $this->context->replyTo,
            subject: __('Reminder: :event is coming up', ['event' => $this->context->eventTitle]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event.reminder',
            text: 'emails.event.reminder-text',
            with: ['context' => $this->context->payload(), 'theme' => $this->context->theme],
        );
    }
}

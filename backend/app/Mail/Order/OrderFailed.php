<?php

namespace HiEvents\Mail\Order;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/orders/order-failed.blade.php
 */
class OrderFailed extends BaseMail
{
    public function __construct(
        private readonly OrderDomainObject $order,
        private readonly EventDomainObject $event,
        private readonly OrganizerDomainObject $organizer,
        private readonly EventSettingDomainObject $eventSettings,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: __('Your order wasn\'t successful'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.orders.order-failed',
            with: [
                'event' => $this->event,
                'order' => $this->order,
                'organizer' => $this->organizer,
                'eventSettings' => $this->eventSettings,
                /**
                 * The view rendered `$supportEmail ?? 'hello@hi.events'`, but this key
                 * was never passed, so the upstream address always rendered: a buyer
                 * whose payment failed was told in writing to contact another company.
                 * Resolved most-specific-first, and null when neither is configured so
                 * the view can omit the sentence entirely.
                 */
                'supportEmail' => $this->eventSettings->getSupportEmail()
                    ?: $this->organizer->getEmail(),
                'eventUrl' => sprintf(
                    Url::getFrontEndUrlFromConfig(Url::EVENT_HOMEPAGE),
                    $this->event->getId(),
                    $this->event->getSlug(),
                ),
            ]
        );
    }
}

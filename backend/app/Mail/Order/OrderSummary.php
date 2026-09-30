<?php

namespace HiEvents\Mail\Order;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use HiEvents\Services\Domain\Email\EmailHtmlToTextConverter;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/orders/summary.blade.php
 */
class OrderSummary extends BaseMail
{
    private readonly ?RenderedEmailTemplateDTO $renderedTemplate;

    public function __construct(
        private readonly OrderDomainObject        $order,
        private readonly EventDomainObject        $event,
        private readonly OrganizerDomainObject    $organizer,
        private readonly EventSettingDomainObject $eventSettings,
        private readonly ?InvoiceDomainObject     $invoice,
        ?RenderedEmailTemplateDTO                 $renderedTemplate = null,
        private readonly ?UniversityEmailThemeDTO $universityTheme = null,
    )
    {
        $this->renderedTemplate = $renderedTemplate;

        parent::__construct();
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderedTemplate?->subject ?? __('Your Order is Confirmed!') . '  🎉';

        return new Envelope(
            replyTo: $this->eventSettings->getSupportEmail(),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        if ($this->universityTheme) {
            return new Content(
                view: 'emails.orders.university-summary',
                text: 'emails.orders.university-summary-text',
                with: $this->viewData(),
            );
        }

        if ($this->renderedTemplate) {
            return new Content(
                markdown: 'emails.custom-template',
                with: [
                    'renderedBody' => $this->renderedTemplate->body,
                    'renderedCta' => $this->renderedTemplate->cta,
                    'eventSettings' => $this->eventSettings,
                ]
            );
        }

        return new Content(
            markdown: 'emails.orders.summary',
            with: $this->viewData(),
        );
    }

    private function viewData(): array
    {
        $location = $this->eventSettings->getConfirmationVenueName() ?: $this->event->getLocation();

        return [
            'eventSettings' => $this->eventSettings,
            'event' => $this->event,
            'order' => $this->order,
            'organizer' => $this->organizer,
            'renderedTemplate' => $this->renderedTemplate,
            'universityTheme' => $this->universityTheme,
            'location' => $location,
            'plainRenderedBody' => $this->renderedTemplate ? EmailHtmlToTextConverter::convert($this->renderedTemplate->body) : null,
            'plainOfflinePaymentInstructions' => EmailHtmlToTextConverter::convert($this->eventSettings->getOfflinePaymentInstructions() ?? ''),
            'plainPostCheckoutMessage' => EmailHtmlToTextConverter::convert($this->eventSettings->getPostCheckoutMessage() ?? ''),
            'plainEmailFooter' => EmailHtmlToTextConverter::convert($this->eventSettings->getGetEmailFooterHtml() ?? ''),
            'orderUrl' => sprintf(
                Url::getFrontEndUrlFromConfig(Url::ORDER_SUMMARY),
                $this->event->getId(),
                $this->order->getShortId(),
            ),
        ];
    }


    public function attachments(): array
    {
        if ($this->invoice === null) {
            return [];
        }

        $invoice = Pdf::loadView('invoice', [
            'order' => $this->order,
            'event' => $this->event,
            'organizer' => $this->organizer,
            'eventSettings' => $this->eventSettings,
            'invoice' => $this->invoice,
        ]);

        return [
            Attachment::fromData(
                static fn() => $invoice->output(),
                'invoice.pdf',
            )->withMime('application/pdf'),
        ];
    }
}

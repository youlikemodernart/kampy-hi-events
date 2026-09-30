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
            'plainRenderedBody' => $this->renderedTemplate ? $this->htmlToText($this->renderedTemplate->body) : null,
            'plainOfflinePaymentInstructions' => $this->htmlToText($this->eventSettings->getOfflinePaymentInstructions() ?? ''),
            'plainPostCheckoutMessage' => $this->htmlToText($this->eventSettings->getPostCheckoutMessage() ?? ''),
            'plainEmailFooter' => $this->htmlToText($this->eventSettings->getGetEmailFooterHtml() ?? ''),
            'orderUrl' => sprintf(
                Url::getFrontEndUrlFromConfig(Url::ORDER_SUMMARY),
                $this->event->getId(),
                $this->order->getShortId(),
            ),
        ];
    }

    private function htmlToText(string $html): string
    {
        $withUrls = preg_replace_callback(
            '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            static function (array $matches): string {
                $label = trim(html_entity_decode(strip_tags($matches[3]), ENT_QUOTES | ENT_HTML5));
                $url = trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5));

                return $label === '' || $label === $url ? $url : sprintf('%s (%s)', $label, $url);
            },
            $html,
        );
        $withBreaks = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $withUrls ?? $html);
        $withBreaks = preg_replace('/<\/?(?:p|div|h[1-6]|li|tr|table|ul|ol)\b[^>]*>/i', "\n", $withBreaks ?? $html);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", preg_replace('/[ \t]+\n/', "\n", html_entity_decode(strip_tags($withBreaks ?? $html), ENT_QUOTES | ENT_HTML5))));
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

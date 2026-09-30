<?php

namespace Tests\Unit\Mail\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use HiEvents\Services\Domain\Email\EmailTemplateService;
use HiEvents\Services\Domain\Email\EmailTokenContextBuilder;
use HiEvents\Services\Domain\Email\MailBuilderService;
use HiEvents\Services\Domain\Email\UniversityThemeResolver;
use Mockery;
use Tests\TestCase;

class AttendeeTicketMailTest extends TestCase
{
    public function test_mail_builder_resolves_the_existing_theme_for_the_attendee_ticket_seam(): void
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getShortId')->andReturn('ATT123');
        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(1);
        $event->shouldReceive('getId')->andReturn(7);
        $event->shouldReceive('getSlug')->andReturn('grand-valley-state-university');
        $event->shouldReceive('getLocation')->andReturn(null);
        $settings = Mockery::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getConfirmationVenueName')->andReturn(null);
        $settings->shouldReceive('getGetEmailFooterHtml')->andReturn('');
        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getId')->andReturn(2);
        $templates = Mockery::mock(EmailTemplateService::class);
        $templates->shouldReceive('getTemplateByType')->once()->andReturn(null);

        $mail = (new MailBuilderService(
            $templates,
            Mockery::mock(EmailTokenContextBuilder::class),
            new UniversityThemeResolver(base_path('../frontend/src/styles/universityThemes.json')),
        ))->buildAttendeeTicketMail($attendee, $order, $event, $settings, $organizer);

        $this->assertSame('emails.orders.university-attendee-ticket', $mail->content()->view);
        $this->assertSame('emails.orders.university-attendee-ticket-text', $mail->content()->text);
    }

    public function test_themed_default_ticket_has_inline_roles_ticket_url_parity_and_no_announcement_copy(): void
    {
        $mail = $this->mailWithTheme();
        $html = $mail->render();
        $ticketUrl = 'https://tickets.example.test/product/7/ATT123';

        $this->assertStringContainsString('background-color:#0032a0;color:#ffffff', $html);
        $this->assertStringContainsString('bgcolor="#13155c"', $html);
        $this->assertStringContainsString('background-color:#e7e7ed', $html);
        $this->assertStringContainsString('You&#039;re going to Grand Valley State University at Allendale Campus', $html);
        $this->assertTicketUrlParity($html, $ticketUrl);
        $this->assertStringNotContainsString('Complete your registration', $html);
        $this->assertStringNotContainsString('tickets.kamplove.org/event/7/grand-valley-state-university', $html);
        $this->assertSame('emails.orders.university-attendee-ticket-text', $mail->content()->text);
    }

    public function test_themed_default_ticket_preserves_online_and_generic_headings(): void
    {
        $online = $this->mailWithTheme(online: true)->render();
        $generic = $this->mailWithTheme(venue: null)->render();

        $this->assertStringContainsString("You&#039;re all set for Grand Valley State University", $online);
        $this->assertStringContainsString("You&#039;re going to Grand Valley State University", $generic);
        $this->assertStringNotContainsString('at Allendale Campus', $generic);
    }

    public function test_themed_pending_default_ticket_preserves_default_intro_and_pending_state(): void
    {
        $mail = $this->mailWithTheme(pending: true);
        $html = $mail->render();
        $text = $this->renderText($mail);

        $this->assertStringContainsString('You&#039;re going to Grand Valley State University at Allendale Campus', $html);
        $this->assertStringContainsString('Your order is pending payment', $html);
        $this->assertStringContainsString("You're going to Grand Valley State University at Allendale Campus", $text);
        $this->assertStringContainsString('Your order is pending payment', $text);
    }

    public function test_themed_completed_custom_ticket_preserves_custom_precedence_without_pending_copy(): void
    {
        $template = new RenderedEmailTemplateDTO('Custom ticket', '<p>ONLY CUSTOM</p>');
        $mail = $this->mailWithTheme(template: $template);
        $html = $mail->render();
        $text = $this->renderText($mail);

        $this->assertStringContainsString('ONLY CUSTOM', $html);
        $this->assertStringContainsString('ONLY CUSTOM', $text);
        $this->assertStringNotContainsString("You're going to Grand Valley State University", $html);
        $this->assertStringNotContainsString('Your order is pending payment', $html);
        $this->assertStringNotContainsString('Your order is pending payment', $text);
    }

    public function test_themed_pending_custom_ticket_preserves_pending_state_and_custom_precedence(): void
    {
        $template = new RenderedEmailTemplateDTO('Custom ticket', '<p>ONLY CUSTOM<br><a href="https://tickets.example.test/custom">Custom ticket details</a></p>', [
            'label' => 'Custom ticket details',
            'url' => 'https://tickets.example.test/custom',
        ]);
        $mail = $this->mailWithTheme(pending: true, template: $template);
        $html = $mail->render();
        $text = $this->renderText($mail);

        $this->assertSame('Custom ticket', $mail->envelope()->subject);
        $this->assertStringContainsString('ONLY CUSTOM', $html);
        $this->assertStringNotContainsString("You're going to Grand Valley State University", $html);
        $this->assertStringContainsString('Your order is pending payment', $html);
        $this->assertStringContainsString('Your order is pending payment', $text);
        $this->assertStringContainsString('Custom ticket details (https://tickets.example.test/custom)', $text);
    }

    public function test_themed_ticket_plain_text_preserves_event_location_footer_ticket_url_and_links(): void
    {
        $mail = $this->mailWithTheme(footer: '<p>Footer<br><a href="https://kamplove.org">Kamp Love</a></p>');
        $text = $this->renderText($mail);

        $this->assertStringContainsString('Allendale Campus', $text);
        $this->assertStringContainsString('View Ticket', $text);
        $this->assertStringContainsString('https://tickets.example.test/product/7/ATT123', $text);
        $this->assertStringContainsString("Footer\nKamp Love (https://kamplove.org)", $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringNotContainsString('<br>', $text);
    }

    public function test_themed_ticket_has_fluid_narrow_safe_geometry_and_locale_derived_language(): void
    {
        app()->setLocale('fr_CA');
        $html = $this->mailWithTheme()->render();
        app()->setLocale('en');

        $this->assertStringContainsString('<html lang="fr-CA">', $html);
        $this->assertStringContainsString('max-width:600px;table-layout:fixed', $html);
        $this->assertStringContainsString('word-break:break-word;overflow-wrap:anywhere', $html);
        $this->assertStringContainsString('display:block;width:100%;box-sizing:border-box;padding:15px 12px', $html);
        $this->assertStringNotContainsString('width="600"', $html);
        $this->assertStringNotContainsString('min-width:190px', $html);
    }

    public function test_unknown_theme_uses_the_unchanged_legacy_markdown_branch(): void
    {
        $mail = $this->mailWithTheme(themed: false);

        $this->assertSame('emails.orders.attendee-ticket', $mail->content()->markdown);
    }

    public function test_ics_attachment_continues_for_themed_ticket(): void
    {
        $attachments = $this->mailWithTheme()->attachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('event.ics', $attachments[0]->as);
    }

    private function renderText(AttendeeTicketMail $mail): string
    {
        return view($mail->content()->text, $mail->content()->with)->render();
    }

    private function assertTicketUrlParity(string $html, string $ticketUrl): void
    {
        $document = new \DOMDocument();
        $document->loadHTML($html);
        $ticketLinks = [];
        foreach ((new \DOMXPath($document))->query('//a') as $link) {
            if ($link->getAttribute('href') === $ticketUrl) {
                $ticketLinks[] = trim($link->textContent);
            }
        }

        $this->assertContains('View Ticket', $ticketLinks);
        $this->assertContains($ticketUrl, $ticketLinks);
        $this->assertCount(2, $ticketLinks);
    }

    private function mailWithTheme(bool $pending = false, ?RenderedEmailTemplateDTO $template = null, bool $themed = true, string $footer = '', bool $online = false, ?string $venue = 'Allendale Campus'): AttendeeTicketMail
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('isOrderAwaitingOfflinePayment')->andReturn($pending);

        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getShortId')->andReturn('ATT123');
        $attendee->shouldReceive('getId')->andReturn(12);

        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getId')->andReturn(7);
        $event->shouldReceive('getTitle')->andReturn('Grand Valley State University');
        $event->shouldReceive('getStartDate')->andReturn('2026-10-10 19:00:00');
        $event->shouldReceive('getEndDate')->andReturn(null);
        $event->shouldReceive('getTimezone')->andReturn('America/Detroit');
        $event->shouldReceive('getLocation')->andReturn('Allendale Campus');
        $event->shouldReceive('getEventUrl')->andReturn('https://tickets.example.test/event/7');
        $event->shouldReceive('getDescription')->andReturn(null);

        $settings = Mockery::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getSupportEmail')->andReturn('support@kamplove.org');
        $settings->shouldReceive('getConfirmationVenueName')->andReturn($venue);
        $settings->shouldReceive('getIsOnlineEvent')->andReturn($online);
        $settings->shouldReceive('getGetEmailFooterHtml')->andReturn($footer);
        $settings->shouldReceive('getLocationDetails')->andReturn(null);

        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getEmail')->andReturn('support@kamplove.org');
        $organizer->shouldReceive('getName')->andReturn('Kamp Love');

        config(['app.frontend_url' => 'https://tickets.example.test']);

        return new AttendeeTicketMail(
            order: $order,
            attendee: $attendee,
            event: $event,
            eventSettings: $settings,
            organizer: $organizer,
            renderedTemplate: $template,
            universityTheme: $themed ? new UniversityEmailThemeDTO('#0032a0', '#13155c', '#ffffff', '#ffffff', '#e7e7ed') : null,
        );
    }
}

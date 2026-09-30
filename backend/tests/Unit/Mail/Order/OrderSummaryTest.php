<?php

namespace Tests\Unit\Mail\Order;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Services\Domain\Email\DTO\RenderedEmailTemplateDTO;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use HiEvents\Services\Domain\Email\UniversityThemeResolver;
use Mockery;
use Tests\TestCase;

class OrderSummaryTest extends TestCase
{
    public function test_themed_completed_order_has_inline_roles_and_the_same_order_url_for_cta_and_fallback(): void
    {
        $mail = $this->mailWithTheme();
        $html = $mail->render();

        $this->assertStringContainsString('background-color:#0032a0;color:#ffffff', $html);
        $this->assertStringContainsString('background-color:#13155c', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'color:#ffffff'));
        $this->assertStringContainsString('background-color:#e7e7ed', $html);
        $this->assertStringContainsString('Your order is confirmed', $html);
        $orderUrl = 'https://tickets.example.test/checkout/7/ABC123/summary';
        $this->assertOrderUrlParity($html, $orderUrl);
        $this->assertStringNotContainsString('Complete your registration', $html);
        $this->assertStringNotContainsString('tickets.kamplove.org/event/7/grand-valley-state-university', $html);
        $this->assertSame('emails.orders.university-summary-text', $mail->content()->text);
    }

    public function test_themed_pending_order_keeps_pending_payment_language(): void
    {
        $mail = $this->mailWithTheme(pending: true);

        $this->assertStringContainsString('Your order is pending payment', $mail->render());
    }

    public function test_themed_pending_custom_template_keeps_operational_payment_state_and_instructions(): void
    {
        $template = new RenderedEmailTemplateDTO('Payment pending', '<p>Custom pending details</p>', null);
        $mail = $this->mailWithTheme(
            pending: true,
            template: $template,
            offlineInstructions: 'Bank<br><a href="https://payments.example.test/invoice">Pay invoice</a>',
        );

        $html = $mail->render();
        $text = $this->renderText($mail);
        $this->assertStringContainsString('Custom pending details', $html);
        $this->assertStringContainsString('Your order is pending payment', $html);
        $this->assertStringContainsString('Pay invoice', $html);
        $this->assertStringContainsString('Your order is pending payment', $text);
        $this->assertStringContainsString('Pay invoice (https://payments.example.test/invoice)', $text);
    }

    public function test_themed_custom_template_replaces_the_builtin_intro_and_keeps_subject_precedence(): void
    {
        $template = new RenderedEmailTemplateDTO('Receipt for GVSU', '<p>ONLY CUSTOM<br><a href="https://tickets.example.test/custom-receipt">Custom receipt link</a></p>', [
            'label' => 'Custom receipt link',
            'url' => 'https://tickets.example.test/custom-receipt',
        ]);
        $mail = $this->mailWithTheme(template: $template);
        $html = $mail->render();

        $this->assertSame('Receipt for GVSU', $mail->envelope()->subject);
        $this->assertStringContainsString('ONLY CUSTOM', $html);
        $this->assertStringNotContainsString('Your order is confirmed', $html);
        $this->assertStringContainsString('Custom receipt link', $html);
        $this->assertStringContainsString('Custom receipt link (https://tickets.example.test/custom-receipt)', $this->renderText($mail));
    }

    public function test_plain_text_preserves_location_post_checkout_html_breaks_links_footer_and_order_url(): void
    {
        $mail = $this->mailWithTheme(
            offlineInstructions: 'Bank<br>Reference <a href="https://payments.example.test/invoice">invoice</a>',
            postCheckout: 'Bring ID<br><a href="https://tickets.example.test/info">Event information</a>',
            footer: '<p>Footer<br><a href="https://kamplove.org">Kamp Love</a></p>',
        );
        $text = $this->renderText($mail);

        $this->assertStringContainsString('Allendale Campus', $text);
        $this->assertStringContainsString("Bring ID\nEvent information (https://tickets.example.test/info)", $text);
        $this->assertStringContainsString("Footer\nKamp Love (https://kamplove.org)", $text);
        $this->assertStringContainsString('View Order Summary & Tickets', $text);
        $this->assertStringContainsString('https://tickets.example.test/checkout/7/ABC123/summary', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringNotContainsString('<br>', $text);
    }

    public function test_themed_pending_plain_text_preserves_payment_instruction_links(): void
    {
        $mail = $this->mailWithTheme(pending: true, offlineInstructions: 'Bank<br><a href="https://payments.example.test/invoice">Pay invoice</a>');

        $this->assertStringContainsString("Bank\nPay invoice (https://payments.example.test/invoice)", $this->renderText($mail));
    }

    public function test_temporary_manifest_secondary_reaches_the_rendered_email_cta(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'university-theme-render-');
        file_put_contents($path, json_encode([
            'grand-valley-state-university' => [
                'primary' => '#0032a0', 'secondary' => '#123456', 'onPrimary' => '#ffffff',
                'onSecondary' => '#ffffff', 'secondarySoft' => '#e7e7ed',
            ],
        ]));
        $theme = (new UniversityThemeResolver($path))->resolveForSlug('grand-valley-state-university');
        $html = $this->mailWithTheme(theme: $theme)->render();
        unlink($path);

        $this->assertStringContainsString('bgcolor="#123456"', $html);
        $this->assertStringContainsString('background-color:#123456', $html);
        $this->assertStringContainsString('color:#ffffff', $html);
    }

    public function test_themed_html_uses_a_fluid_card_and_narrow_safe_cta_geometry(): void
    {
        $html = $this->mailWithTheme()->render();

        $this->assertStringContainsString('width="100%"', $html);
        $this->assertStringContainsString('max-width:600px;table-layout:fixed', $html);
        $this->assertStringContainsString('word-break:break-word;overflow-wrap:anywhere', $html);
        $this->assertStringContainsString('display:block;max-width:100%', $html);
        $this->assertStringNotContainsString('width="600"', $html);
        $this->assertStringNotContainsString('style="width:600px', $html);
        $this->assertStringNotContainsString('min-width:190px', $html);
        $this->assertStringContainsString('display:block;width:100%;box-sizing:border-box;padding:15px 12px', $html);
    }

    public function test_themed_html_derives_lang_from_active_locale(): void
    {
        app()->setLocale('fr_CA');
        $html = $this->mailWithTheme()->render();
        app()->setLocale('en');

        $this->assertStringContainsString('<html lang="fr-CA">', $html);
    }

    public function test_unknown_theme_uses_the_unchanged_legacy_markdown_branch(): void
    {
        $mail = $this->mailWithTheme(themed: false);

        $this->assertSame('emails.orders.summary', $mail->content()->markdown);
    }

    private function renderText(OrderSummary $mail): string
    {
        return view($mail->content()->text, $mail->content()->with)->render();
    }

    private function assertOrderUrlParity(string $html, string $orderUrl): void
    {
        $document = new \DOMDocument();
        $document->loadHTML($html);

        $orderLinks = [];
        foreach ((new \DOMXPath($document))->query('//a') as $link) {
            if ($link->getAttribute('href') === $orderUrl) {
                $orderLinks[] = trim($link->textContent);
            }
        }

        $this->assertContains('View Order Summary & Tickets', $orderLinks);
        $this->assertContains($orderUrl, $orderLinks);
        $this->assertCount(2, $orderLinks);
    }

    private function mailWithTheme(bool $pending = false, ?RenderedEmailTemplateDTO $template = null, bool $themed = true, string $offlineInstructions = 'Pay your invoice.', ?string $postCheckout = null, string $footer = '', ?UniversityEmailThemeDTO $theme = null): OrderSummary
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('isOrderAwaitingOfflinePayment')->andReturn($pending);
        $order->shouldReceive('isOrderCompleted')->andReturn(!$pending);
        $order->shouldReceive('getPublicId')->andReturn('ORDER-1');
        $order->shouldReceive('getTotalGross')->andReturn(36.00);
        $order->shouldReceive('getShortId')->andReturn('ABC123');

        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getId')->andReturn(7);
        $event->shouldReceive('getTitle')->andReturn('Grand Valley State University');
        $event->shouldReceive('getStartDate')->andReturn('2026-10-10 19:00:00');
        $event->shouldReceive('getTimezone')->andReturn('America/Detroit');
        $event->shouldReceive('getCurrency')->andReturn('USD');
        $event->shouldReceive('getLocation')->andReturn('Allendale Campus');

        $settings = Mockery::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getSupportEmail')->andReturn('support@kamplove.org');
        $settings->shouldReceive('getConfirmationVenueName')->andReturn(null);
        $settings->shouldReceive('getOfflinePaymentInstructions')->andReturn($offlineInstructions);
        $settings->shouldReceive('getPostCheckoutMessage')->andReturn($postCheckout);
        $settings->shouldReceive('getGetEmailFooterHtml')->andReturn($footer);

        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getEmail')->andReturn('support@kamplove.org');
        $organizer->shouldReceive('getName')->andReturn('Kamp Love');

        config(['app.frontend_url' => 'https://tickets.example.test']);

        return new OrderSummary(
            order: $order,
            event: $event,
            organizer: $organizer,
            eventSettings: $settings,
            invoice: null,
            renderedTemplate: $template,
            universityTheme: $themed ? ($theme ?? new UniversityEmailThemeDTO('#0032a0', '#13155c', '#ffffff', '#ffffff', '#e7e7ed')) : null,
        );
    }
}

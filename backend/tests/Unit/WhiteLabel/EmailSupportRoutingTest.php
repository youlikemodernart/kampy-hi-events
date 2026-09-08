<?php

declare(strict_types=1);

namespace Tests\Unit\WhiteLabel;

use HiEvents\DomainObjects\EventSettingDomainObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The failed-order email previously rendered `$supportEmail ?? 'hello@hi.events'`
 * while OrderFailed::content() never passed `supportEmail`, so the upstream
 * address always rendered to a buyer whose payment had just failed.
 *
 * These tests pin the data contract and the venue rule without booting Laravel.
 */
class EmailSupportRoutingTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/'.ltrim($relative, '/');
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_order_failed_mailable_passes_a_support_email_to_its_view(): void
    {
        $mailable = $this->read('app/Mail/Order/OrderFailed.php');

        // The key the view reads must actually be supplied.
        $this->assertMatchesRegularExpression(
            "/'supportEmail'\s*=>/",
            $mailable,
            'OrderFailed must pass supportEmail to the view'
        );

        // Resolved most-specific-first: event setting, then organiser.
        $this->assertStringContainsString('getSupportEmail()', $mailable);
        $this->assertStringContainsString('getEmail()', $mailable);
    }

    public function test_order_failed_view_has_no_hardcoded_fallback_address(): void
    {
        $view = $this->read('resources/views/emails/orders/order-failed.blade.php');

        $this->assertStringNotContainsString('hi.events', $view);
        // When neither source is configured the sentence is omitted rather than
        // naming somebody else's support desk.
        $this->assertStringContainsString('@if(!empty($supportEmail))', $view);
    }

    public function test_buyer_facing_order_emails_route_support_through_the_event(): void
    {
        foreach (['summary', 'attendee-ticket'] as $name) {
            $view = $this->read("resources/views/emails/orders/{$name}.blade.php");

            $this->assertStringContainsString(
                'getSupportEmail() ?: $organizer->getEmail()',
                $view,
                "{$name} must resolve support most-specific-first"
            );
        }
    }

    public function test_the_two_emails_every_buyer_receives_render_the_per_event_footer(): void
    {
        // getGetEmailFooterHtml() was called in nine templates but not in the order
        // confirmation or the ticket delivery, i.e. the only two a buyer always gets.
        foreach (['summary', 'attendee-ticket'] as $name) {
            $view = $this->read("resources/views/emails/orders/{$name}.blade.php");

            $this->assertStringContainsString(
                'getGetEmailFooterHtml()',
                $view,
                "{$name} must render the per-event email footer"
            );
        }
    }

    public function test_confirmation_copy_is_one_message_per_state_not_a_concatenation(): void
    {
        $view = $this->read('resources/views/emails/orders/attendee-ticket.blade.php');

        // Whole messages with named placeholders, so translators can reorder.
        $this->assertStringContainsString(':eventTitle at :venueName', $view);
        $this->assertStringContainsString("'You\\'re all set for :eventTitle'", $view);

        // The old fragment-plus-concatenation form must not return.
        $this->assertStringNotContainsString(
            "__('You\\'re going to') }} {{ \$event->getTitle()",
            $view
        );
    }

    #[DataProvider('venueNameCases')]
    public function test_confirmation_venue_name_rule(mixed $locationDetails, ?string $expected): void
    {
        $settings = new EventSettingDomainObject;
        $settings->setLocationDetails($locationDetails);

        $this->assertSame($expected, $settings->getConfirmationVenueName());
    }

    public static function venueNameCases(): array
    {
        return [
            'named venue' => [
                ['venue_name' => 'Grand Valley State University'],
                'Grand Valley State University',
            ],
            'padded venue name is trimmed' => [['venue_name' => '  Camp Roger  '], 'Camp Roger'],
            'blank venue name is absent' => [['venue_name' => '   '], null],
            'empty venue name is absent' => [['venue_name' => ''], null],
            'missing venue key' => [['city' => 'Allendale'], null],
            'null location details' => [null, null],
            'json encoded location details' => [
                '{"venue_name":"Camp Roger"}',
                'Camp Roger',
            ],
            'unparseable string location details' => ['not json', null],
            'non-string venue name' => [['venue_name' => 42], null],
        ];
    }
}

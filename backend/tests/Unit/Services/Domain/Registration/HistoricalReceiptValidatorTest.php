<?php

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use HiEvents\Services\Domain\Registration\HistoricalReceiptValidator as V;
use Illuminate\Mail\Markdown;
use Mockery;
use Tests\Support\HistoricalReceiptFixture;
use Tests\TestCase;

class HistoricalReceiptValidatorTest extends TestCase
{
    public function test_exact_structural_receipt_and_eastern_window_accepts_only_minimal_derived_evidence(): void
    {
        [$manifest, $rows] = HistoricalReceiptFixture::bundle();
        $row = $rows[0];
        $e = (new V)->validate($manifest['scope'], $row['order'], $row['evidence']);
        self::assertSame('historical_receipt_v1', $e['authority_type']);
        self::assertSame('buyer@example.test', $e['recipient']);
        self::assertArrayNotHasKey('TextBody', $e);
        self::assertArrayNotHasKey('HtmlBody', $e);
        self::assertArrayNotHasKey('summary_url', $e);
        self::assertArrayNotHasKey('billing_details', $e);
    }

    public function test_identity_source_payment_recipient_bounds_and_ambiguity_fail_closed(): void
    {
        $mutations = [
            'wrong order metadata' => fn (&$e) => $e['pi']['metadata']['kamp_source_order_id'] = 'hi_order_order_111',
            'wrong account' => fn (&$e) => $e['stripe_account'] = 'acct_other',
            'test mode' => fn (&$e) => $e['charge']['livemode'] = false,
            'wrong PI' => fn (&$e) => $e['charge']['payment_intent'] = 'pi_other',
            'refunded' => fn (&$e) => $e['charge']['amount_refunded'] = 1,
            'duplicate payment' => fn (&$e) => $e['native_payments'][] = $e['native_payments'][0],
            'missing outbox' => fn (&$e) => $e['native_outbox'] = [],
            'resend' => fn (&$e) => $e['resend_signals'] = 1,
            'wrong event' => fn (&$e) => $e['original_events'][0]['data']['object']['id'] = 'ch_other',
            'missing original event' => fn (&$e) => $e['original_events'] = [],
            'incomplete search' => fn (&$e) => $e['search']['complete'] = false,
            'cap' => fn (&$e) => $e['search']['total'] = 21,
            'wrong eastern offset' => fn (&$e) => $e['search']['from'] = '2026-10-01T07:59:30-05:00',
            'wrong server' => fn (&$e) => $e['messages'][0]['server'] = 123,
            'wrong stream' => fn (&$e) => $e['messages'][0]['MessageStream'] = 'broadcast',
            'wrong ID' => fn (&$e) => $e['messages'][0]['MessageID'] = '33333333-3333-4333-8333-333333333333',
            'wrong sender' => fn (&$e) => $e['messages'][0]['From'] = 'tickets@example.test.evil',
            'wrong destination' => fn (&$e) => $e['messages'][0]['To'][0]['Email'] = 'other@example.test',
            'Cc' => fn (&$e) => $e['messages'][0]['Cc'] = [['Email' => 'other@example.test']],
            'Bcc' => fn (&$e) => $e['messages'][0]['Bcc'] = [['Email' => 'other@example.test']],
            'unknown delivery' => fn (&$e) => $e['messages'][0]['MessageEvents'] = [],
            'bad timestamp' => fn (&$e) => $e['messages'][0]['ReceivedAt'] = 'yesterday',
            'expired window' => fn (&$e) => $e['messages'][0]['ReceivedAt'] = '2026-10-01T12:10:01Z',
            'substring public ID' => fn (&$e) => $e['messages'][0]['TextBody'] = str_replace('PUBLIC-SYNTHETIC-11', 'PUBLIC-SYNTHETIC-111', $e['messages'][0]['TextBody']),
            'substring URL' => fn (&$e) => $e['messages'][0]['HtmlBody'] = str_replace('order_11', 'order_111', $e['messages'][0]['HtmlBody']),
            'same-contact duplicate' => function (&$e) {
                $e['messages'][] = $e['messages'][0];
                $e['search']['total'] = 2;
            },
        ];
        foreach ($mutations as $label => $mutate) {
            [$manifest, $rows] = HistoricalReceiptFixture::bundle();
            $row = $rows[0];
            $mutate($row['evidence']);
            try {
                (new V)->validate($manifest['scope'], $row['order'], $row['evidence']);
                self::fail($label);
            } catch (ResourceConflictException) {
                self::assertTrue(true, $label);
            }
        }
    }

    public function test_actual_native_summary_and_attendee_ticket_pair_qualifies_in_either_order(): void
    {
        [$scope, $row] = $this->nativePair();
        foreach ([false, true] as $reverse) {
            if ($reverse) {
                $row['evidence']['messages'] = array_reverse($row['evidence']['messages']);
                $row['evidence']['search']['message_ids'] = array_reverse($row['evidence']['search']['message_ids']);
            }
            $accepted = (new V)->validate($scope, $row['order'], $row['evidence']);
            self::assertSame('11111111-1111-4111-8111-111111111111', $accepted['message_id']);
            self::assertSame(2, $accepted['search_count']);
        }
    }

    public function test_native_markdown_pair_and_display_name_envelope_preserve_exact_authority(): void
    {
        [$scope, $row] = $this->nativePair();
        foreach ([false, true] as $ticket) {
            $row['evidence']['messages'][(int) $ticket] = array_replace($row['evidence']['messages'][(int) $ticket], $this->nativeBodies($row['order'], $ticket, true), ['From' => 'Kamp Love - Synthetic University <tickets@example.test>']);
        }
        foreach ([false, true] as $reverse) {
            if ($reverse) {
                $row['evidence']['messages'] = array_reverse($row['evidence']['messages']);
                $row['evidence']['search']['message_ids'] = array_reverse($row['evidence']['search']['message_ids']);
            }
            $accepted = (new V)->validate($scope, $row['order'], $row['evidence']);
            self::assertSame('11111111-1111-4111-8111-111111111111', $accepted['message_id']);
            self::assertSame('tickets@example.test', $accepted['sender']);
            self::assertSame(2, $accepted['search_count']);
        }
    }

    public function test_postmark_seven_digit_timestamp_precision_keeps_time_bounds(): void
    {
        [$scope, $row] = $this->nativePair();
        foreach ($row['evidence']['messages'] as &$message) {
            $message['ReceivedAt'] = '2026-10-01T08:00:30.1234567-04:00';
            $message['MessageEvents'][0]['ReceivedAt'] = '2026-10-01T12:00:31.1234567Z';
        }
        unset($message);
        self::assertSame(2, (new V)->validate($scope, $row['order'], $row['evidence'])['search_count']);
        foreach (['2026-10-01T12:00:30.12345678Z', '2026-02-30T12:00:30.1234567Z', '2026-10-01T12:10:01.1234567Z', '2026-10-01T12:00:30.1234567', '2026-10-01T12:00:30.1234567Z trailing'] as $invalid) {
            $row['evidence']['messages'][0]['ReceivedAt'] = $invalid;
            $this->assertRejected($scope, $row, 'invalid shape, calendar or purchase window');
        }
    }

    public function test_display_name_never_changes_the_single_allowlisted_sender(): void
    {
        [$scope, $row] = $this->nativePair();
        foreach (['tickets@example.test <attacker@example.test>', 'Native <tickets@example.test>, Other <attacker@example.test>', 'Native <tickets@example.test> trailing', "Native\r\nBcc: other@example.test <tickets@example.test>"] as $from) {
            $row['evidence']['messages'][0]['From'] = $from;
            $this->assertRejected($scope, $row, 'sender mailbox or header conflict');
        }
        $row['evidence']['messages'][0]['From'] = '"Kamp Love, Synthetic" <tickets@example.test>';
        self::assertSame('tickets@example.test', (new V)->validate($scope, $row['order'], $row['evidence'])['sender']);
    }

    public function test_native_markdown_duplicate_or_mixed_receipt_fields_still_reject(): void
    {
        [$scope, $row] = $this->nativePair();
        $row['evidence']['messages'][0] = array_replace($row['evidence']['messages'][0], $this->nativeBodies($row['order'], false, true));
        $duplicate = $row['evidence']['messages'][0];
        $duplicate['MessageID'] = '33333333-3333-4333-8333-333333333333';
        $this->appendMessage($row, $duplicate);
        $this->assertRejected($scope, $row, 'distinct native Markdown receipt');
        array_pop($row['evidence']['messages']);
        array_pop($row['evidence']['search']['message_ids']);
        $row['evidence']['search']['total']--;
        $row['evidence']['messages'][0]['TextBody'] .= "\nOrder Number: PUBLIC-OTHER-13\n";
        $this->assertRejected($scope, $row, 'mixed native Markdown and plain identities');
    }

    public function test_attendee_ticket_alone_never_supplies_receipt_authority(): void
    {
        [$scope, $row] = $this->nativePair();
        $row['evidence']['messages'] = [$row['evidence']['messages'][1]];
        $row['evidence']['search']['message_ids'] = [$row['evidence']['messages'][0]['MessageID']];
        $row['evidence']['search']['total'] = 1;
        $this->expectException(ResourceConflictException::class);
        (new V)->validate($scope, $row['order'], $row['evidence']);
    }

    public function test_distinct_same_mailbox_duplicate_native_receipt_rejects_without_earliest_choice(): void
    {
        [$scope, $row] = $this->nativePair();
        $duplicate = $row['evidence']['messages'][0];
        $duplicate['MessageID'] = '33333333-3333-4333-8333-333333333333';
        $duplicate['ReceivedAt'] = '2026-10-01T12:00:29Z';
        $this->appendMessage($row, $duplicate);
        $this->expectException(ResourceConflictException::class);
        (new V)->validate($scope, $row['order'], $row['evidence']);
    }

    public function test_multiple_native_purchases_in_same_mailbox_are_classified_per_exact_order(): void
    {
        [$scope, $row] = $this->nativePair();
        $otherOrder = array_replace($row['order'], ['short_id' => 'other_13', 'public_id' => 'PUBLIC-OTHER-13']);
        $other = array_replace($row['evidence']['messages'][0], $this->nativeBodies($otherOrder, false), ['MessageID' => '33333333-3333-4333-8333-333333333333']);
        $this->appendMessage($row, $other);
        $accepted = (new V)->validate($scope, $row['order'], $row['evidence']);
        self::assertSame($row['evidence']['messages'][0]['MessageID'], $accepted['message_id']);
        self::assertSame(3, $accepted['search_count']);
        $selected = (new V)->selectReceipt($scope, $otherOrder, $row['evidence']['search'], $row['evidence']['messages'], $row['evidence']['charge']['created']);
        self::assertSame($other['MessageID'], $selected['MessageID']);
    }

    public function test_unknown_mixed_spoofed_malformed_and_incomplete_extra_candidates_fail_closed(): void
    {
        $mutations = [
            'unknown body' => fn (&$r) => $r['evidence']['messages'][1]['TextBody'] = 'Not a known native message',
            'missing detail' => fn (&$r) => array_pop($r['evidence']['messages']),
            'missing search ID' => fn (&$r) => array_pop($r['evidence']['search']['message_ids']),
            'repeated ID' => fn (&$r) => $r['evidence']['search']['message_ids'][1] = $r['evidence']['search']['message_ids'][0],
            'truncated results' => fn (&$r) => $r['evidence']['search']['total'] = 3,
            'incomplete pagination' => fn (&$r) => $r['evidence']['search']['complete'] = false,
            'nonzero offset' => fn (&$r) => $r['evidence']['search']['offset'] = 1,
            'truncated HTML' => fn (&$r) => $r['evidence']['messages'][1]['HtmlBody'] = '<div>ticket',
            'malformed HTML' => fn (&$r) => $r['evidence']['messages'][1]['HtmlBody'] .= '<a href="',
            'extra wrong source' => fn (&$r) => $r['evidence']['messages'][1]['From'] = 'attacker@example.test',
            'extra wrong server' => fn (&$r) => $r['evidence']['messages'][1]['server'] = 1,
            'extra wrong stream' => fn (&$r) => $r['evidence']['messages'][1]['MessageStream'] = 'broadcast',
            'extra Cc' => fn (&$r) => $r['evidence']['messages'][1]['Cc'] = [['Email' => 'other@example.test']],
            'extra Bcc' => fn (&$r) => $r['evidence']['messages'][1]['Bcc'] = [['Email' => 'other@example.test']],
            'extra wrong To' => fn (&$r) => $r['evidence']['messages'][1]['To'][0]['Email'] = 'other@example.test',
            'extra missing Delivered' => fn (&$r) => $r['evidence']['messages'][1]['MessageEvents'] = [],
            'extra late delivery' => fn (&$r) => $r['evidence']['messages'][1]['MessageEvents'][0]['ReceivedAt'] = '2026-10-07T00:00:00Z',
            'ticket URL spoof' => fn (&$r) => $r['evidence']['messages'][1]['HtmlBody'] = str_replace('/product/7/', '/product/77/', $r['evidence']['messages'][1]['HtmlBody']),
            'ticket URL query' => fn (&$r) => $r['evidence']['messages'][1]['HtmlBody'] = str_replace('/ATT123', '/ATT123?order=other', $r['evidence']['messages'][1]['HtmlBody']),
            'ticket mixed receipt' => fn (&$r) => $r['evidence']['messages'][1]['TextBody'] .= "\nOrder Summary\n",
            'ticket inline other receipt URL' => fn (&$r) => $r['evidence']['messages'][1]['TextBody'] .= "\nReference: https://tickets.example.test/checkout/7/other_13/summary\n",
            'ticket hidden target' => fn (&$r) => $r['evidence']['messages'][1]['HtmlBody'] .= '<span data-order="order_11"></span>',
        ];
        foreach ($mutations as $label => $mutate) {
            [$scope, $row] = $this->nativePair();
            $mutate($row);
            $this->assertRejected($scope, $row, $label);
        }
    }

    public function test_different_order_receipt_with_any_target_or_conflicting_identity_is_not_ignored(): void
    {
        $mutations = [
            'target public ID only' => fn (&$m) => $m['TextBody'] = str_replace('PUBLIC-OTHER-13', 'PUBLIC-SYNTHETIC-11', $m['TextBody']),
            'target HTML URL' => fn (&$m) => $m['HtmlBody'] = str_replace('other_13', 'order_11', $m['HtmlBody']),
            'target hidden attribute' => fn (&$m) => $m['HtmlBody'] .= '<span data-order="PUBLIC-SYNTHETIC-11"></span>',
            'target unrelated text field' => fn (&$m) => $m['TextBody'] .= "\nReference: order_11\n",
            'third-order HTML identity' => fn (&$m) => $m['HtmlBody'] = str_replace('PUBLIC-OTHER-13', 'PUBLIC-OTHER-14', $m['HtmlBody']),
            'third-order inline URL' => fn (&$m) => $m['TextBody'] .= "\nReference: https://tickets.example.test/checkout/7/other_14/summary\n",
            'spoof URL origin' => fn (&$m) => $m['HtmlBody'] = str_replace('tickets.example.test/', 'tickets.example.test.evil/', $m['HtmlBody']),
            'wrong event' => fn (&$m) => $m['HtmlBody'] = str_replace('/checkout/7/', '/checkout/8/', $m['HtmlBody']),
            'unknown truncated receipt' => fn (&$m) => $m['TextBody'] = 'Order Summary',
        ];
        foreach ($mutations as $label => $mutate) {
            [$scope, $row] = $this->nativePair();
            $otherOrder = array_replace($row['order'], ['short_id' => 'other_13', 'public_id' => 'PUBLIC-OTHER-13']);
            $other = array_replace($row['evidence']['messages'][0], $this->nativeBodies($otherOrder, false), ['MessageID' => '33333333-3333-4333-8333-333333333333']);
            $mutate($other);
            $this->appendMessage($row, $other);
            $this->assertRejected($scope, $row, $label);
        }
    }

    private function assertRejected(array $scope, array $row, string $label): void
    {
        try {
            (new V)->validate($scope, $row['order'], $row['evidence']);
            self::fail($label);
        } catch (ResourceConflictException) {
            self::assertTrue(true, $label);
        }
    }

    private function appendMessage(array &$row, array $message): void
    {
        $row['evidence']['messages'][] = $message;
        $row['evidence']['search']['message_ids'][] = $message['MessageID'];
        $row['evidence']['search']['total']++;
    }

    private function nativePair(): array
    {
        [$manifest, $rows] = HistoricalReceiptFixture::bundle();
        $scope = $manifest['scope'];
        $scope['summary_url_template'] = 'https://tickets.example.test/checkout/{event}/{order}/summary';
        $row = $rows[0];
        $row['evidence']['messages'][0] = array_replace($row['evidence']['messages'][0], $this->nativeBodies($row['order'], false));
        $ticket = array_replace($row['evidence']['messages'][0], $this->nativeBodies($row['order'], true), ['MessageID' => '22222222-2222-4222-8222-222222222222']);
        $this->appendMessage($row, $ticket);

        return [$scope, $row];
    }

    private function nativeBodies(array $identity, bool $ticket, bool $markdown = false): array
    {
        config(['app.frontend_url' => 'https://tickets.example.test']);
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('isOrderAwaitingOfflinePayment')->andReturn(false);
        $order->shouldReceive('isOrderCompleted')->andReturn(true);
        $order->shouldReceive('getPublicId')->andReturn($identity['public_id']);
        $order->shouldReceive('getShortId')->andReturn($identity['short_id']);
        $order->shouldReceive('getTotalGross')->andReturn(36.00);
        $event = Mockery::mock(EventDomainObject::class);
        foreach (['Id' => 7, 'Title' => 'Synthetic University', 'StartDate' => '2026-10-10 19:00:00', 'Timezone' => 'America/Detroit', 'Currency' => 'USD', 'Location' => 'Synthetic Campus'] as $key => $value) {
            $event->shouldReceive('get'.$key)->andReturn($value);
        }
        $settings = Mockery::mock(EventSettingDomainObject::class);
        foreach (['SupportEmail' => 'support@example.test', 'ConfirmationVenueName' => null, 'OfflinePaymentInstructions' => '', 'PostCheckoutMessage' => '', 'GetEmailFooterHtml' => '', 'IsOnlineEvent' => false] as $key => $value) {
            $settings->shouldReceive('get'.$key)->andReturn($value);
        }
        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $organizer->shouldReceive('getEmail')->andReturn('support@example.test');
        $organizer->shouldReceive('getName')->andReturn('Kamp Love');
        $theme = $markdown ? null : new UniversityEmailThemeDTO('#0032a0', '#13155c', '#ffffff', '#ffffff', '#e7e7ed');
        $attendee = Mockery::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getShortId')->andReturn('ATT123');
        $mail = $ticket
            ? new AttendeeTicketMail($order, $attendee, $event, $settings, $organizer, universityTheme: $theme)
            : new OrderSummary($order, $event, $organizer, $settings, null, universityTheme: $theme);
        $content = $mail->content();

        if ($markdown) {
            return ['TextBody' => (string) app(Markdown::class)->renderText($content->markdown, $content->with), 'HtmlBody' => (string) app(Markdown::class)->render($content->markdown, $content->with)];
        }

        return ['TextBody' => view($content->text, $content->with)->render(), 'HtmlBody' => view($content->view, $content->with)->render()];
    }
}

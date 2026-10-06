<?php

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Services\Domain\Registration\HistoricalReceiptValidator as V;
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
}

<?php

namespace Tests\Support;

use HiEvents\Services\Domain\Registration\HistoricalReceiptValidator as V;

final class HistoricalReceiptFixture
{
    public static function bundle(): array
    {
        $scope = ['event_id' => 7, 'account_id' => 1, 'organizer_id' => 2, 'stripe_platform' => null, 'stripe_platform_account' => 'acct_syntheticPlatform', 'stripe_account' => 'acct_syntheticConnected', 'postmark_server' => 999,
            'sender' => 'tickets@example.test', 'summary_origin' => 'https://tickets.example.test', 'summary_url_template' => 'https://tickets.example.test/event/{event}/order/{order}', 'source_revision' => 'synthetic-5610429',
            'created_from' => '2026-09-15T19:38:44Z', 'created_until' => '2026-10-05T21:19:13Z', 'selected_at' => '2026-10-06T01:00:00Z', 'valid_until' => '2026-10-17T19:00:00Z', 'purge_after' => '2026-10-18T20:00:00Z', 'approved_effect_reference' => 'synthetic-local-only'];
        $order = ['id' => 11, 'event_id' => 7, 'account_id' => 1, 'organizer_id' => 2, 'short_id' => 'order_11', 'public_id' => 'PUBLIC-SYNTHETIC-11', 'email' => 'buyer@example.test', 'formatted_total' => '$36.00', 'created_at' => '2026-10-01T12:00:00Z'];
        $time = V::time($order['created_at']);
        $message = '11111111-1111-4111-8111-111111111111';
        $row = ['order_id' => 11, 'order' => $order, 'evidence' => [
            'native_payments' => [['id' => 51, 'order_id' => 11, 'connected_account_id' => $scope['stripe_account'], 'stripe_platform' => null, 'payment_intent_id' => 'pi_synthetic11', 'charge_id' => 'ch_synthetic11']],
            'native_outbox' => [['id' => 71, 'order_id' => 11, 'transition_key' => 'STRIPE_COMPLETED', 'email_kind' => 'DETAILS_AND_TICKETS', 'status' => 'DELIVERED']], 'resend_signals' => 0,
            'stripe_platform_account' => $scope['stripe_platform_account'], 'stripe_account' => $scope['stripe_account'],
            'pi' => ['id' => 'pi_synthetic11', 'latest_charge' => 'ch_synthetic11', 'livemode' => true, 'status' => 'succeeded', 'metadata' => ['kamp_source_record_id' => 'hi_order_record_11', 'kamp_source_order_id' => 'hi_order_order_11', 'kamp_source' => 'hi_events', 'kamp_environment' => 'live']],
            'charge' => ['id' => 'ch_synthetic11', 'payment_intent' => 'pi_synthetic11', 'livemode' => true, 'paid' => true, 'refunded' => false, 'amount_refunded' => 0, 'created' => $time],
            'original_events_complete' => true, 'original_events' => [['id' => 'evt_synthetic11', 'account' => $scope['stripe_account'], 'livemode' => true, 'type' => 'charge.succeeded', 'created' => $time, 'data' => ['object' => ['id' => 'ch_synthetic11', 'payment_intent' => 'pi_synthetic11']]]],
            'search' => ['recipient' => $order['email'], 'server' => 999, 'stream' => 'outbound', 'complete' => true, 'offset' => 0, 'cap' => 20, 'total' => 1, 'message_ids' => [$message], 'from' => '2026-10-01T07:59:30-04:00', 'to' => '2026-10-01T08:10:00-04:00'],
            'messages' => [['server' => 999, 'MessageStream' => 'outbound', 'MessageID' => $message, 'From' => 'tickets@example.test', 'To' => [['Email' => $order['email']]], 'Cc' => [], 'Bcc' => [], 'ReceivedAt' => '2026-10-01T12:00:30Z', 'MessageEvents' => [['Type' => 'Delivered', 'ReceivedAt' => '2026-10-01T12:00:31Z']],
                'TextBody' => "Order Summary\nOrder Number: PUBLIC-SYNTHETIC-11\nTotal Amount: $36.00\nView Order Summary & Tickets:\nhttps://tickets.example.test/event/7/order/order_11\n",
                'HtmlBody' => '<a href="https://tickets.example.test/event/7/order/order_11">View Order Summary &amp; Tickets</a>']],
        ]];
        $quarantine = ['order_id' => 12, 'pi' => 'pi_synthetic12', 'charge' => 'ch_synthetic12', 'message_id' => '22222222-2222-4222-8222-222222222222'];
        $manifest = ['scope' => $scope, 'order_ids' => [11], 'reconstructed_order_ids' => [11], 'quarantined_order_ids' => [12], 'quarantined_association' => $quarantine,
            'quarantine_commitment' => hash('sha256', implode('|', $quarantine)),
            'original_aggregate_commitment' => hash('sha256', hash('sha256', '11|pi_synthetic11|ch_synthetic11|'.$message)),
            'evidence_commitments' => ['11' => hash_hmac('sha256', V::canonical((new V)->validate($scope, $order, $row['evidence'])), str_repeat('i', 32))]];

        return [$manifest, [$row]];
    }
}

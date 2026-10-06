<?php

namespace HiEvents\Services\Domain\Registration;

use DateTimeImmutable;
use HiEvents\Exceptions\ResourceConflictException;

/** Purchase-window dispatch evidence, never checkout ownership or waiver completion. No I/O. */
final class HistoricalReceiptValidator
{
    public const VERSION = 'historical_receipt_v1';

    public static function canonical(array $value): string
    {
        $sort = static function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function require(bool $ok): void
    {
        if (! $ok) {
            throw new ResourceConflictException('Historical receipt evidence rejected.');
        }
    }

    public static function time(mixed $value): int
    {
        self::require(is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $value) === 1);
        $date = new DateTimeImmutable($value);
        self::require(! DateTimeImmutable::getLastErrors());

        return $date->getTimestamp();
    }

    /** All transport envelopes must be collected under separately approved exact reads. */
    public function validate(array $scope, array $order, array $input): array
    {
        $allowedScope = ['event_id', 'account_id', 'organizer_id', 'stripe_platform', 'stripe_platform_account', 'stripe_account', 'postmark_server', 'sender', 'summary_origin', 'summary_url_template', 'source_revision', 'created_from', 'created_until', 'selected_at', 'valid_until', 'purge_after', 'approved_effect_reference'];
        self::require(count($scope) === count($allowedScope) && ! array_diff(array_keys($scope), $allowedScope));
        self::require(preg_match('/\A[A-Za-z0-9:._-]{1,192}\z/', $scope['approved_effect_reference']) === 1 && preg_match('/\A[A-Za-z0-9:._-]{1,64}\z/', $scope['source_revision']) === 1);
        self::require(($scope['event_id'] ?? null) === 7 && ($scope['account_id'] ?? null) === 1 && ($scope['organizer_id'] ?? null) === 2);
        foreach (['event_id', 'account_id', 'organizer_id'] as $field) {
            self::require(($order[$field] ?? null) === $scope[$field]);
        }
        self::require(self::time($order['created_at']) >= self::time($scope['created_from']) && self::time($order['created_at']) <= self::time($scope['created_until']) && self::time($scope['created_until']) <= self::time($scope['selected_at']));
        self::require(substr_count($scope['summary_url_template'], '{event}') === 1 && substr_count($scope['summary_url_template'], '{order}') === 1);
        $payment = $input['native_payments'] ?? [];
        $outbox = $input['native_outbox'] ?? [];
        self::require(count($payment) === 1 && count($outbox) === 1 && ($input['resend_signals'] ?? null) === 0);
        $payment = $payment[0];
        $outbox = $outbox[0];
        self::require(($payment['order_id'] ?? null) === $order['id'] && ($payment['connected_account_id'] ?? null) === $scope['stripe_account'] && ($payment['stripe_platform'] ?? null) === $scope['stripe_platform']);
        self::require(($outbox['order_id'] ?? null) === $order['id'] && ($outbox['transition_key'] ?? null) === 'STRIPE_COMPLETED' && ($outbox['email_kind'] ?? null) === 'DETAILS_AND_TICKETS' && ($outbox['status'] ?? null) === 'DELIVERED');
        self::require(($input['stripe_platform_account'] ?? null) === $scope['stripe_platform_account'] && ($input['stripe_account'] ?? null) === $scope['stripe_account']);
        $pi = $input['pi'] ?? [];
        $charge = $input['charge'] ?? [];
        self::require(($pi['id'] ?? null) === $payment['payment_intent_id'] && ($charge['id'] ?? null) === $payment['charge_id'] && ($pi['latest_charge'] ?? null) === $charge['id'] && ($charge['payment_intent'] ?? null) === $pi['id']);
        self::require(($pi['livemode'] ?? null) === true && ($charge['livemode'] ?? null) === true && ($pi['status'] ?? null) === 'succeeded' && ($charge['paid'] ?? null) === true && ($charge['refunded'] ?? null) === false && ($charge['amount_refunded'] ?? null) === 0);
        $metadata = ['kamp_source_record_id' => 'hi_order_record_'.$order['id'], 'kamp_source_order_id' => 'hi_order_'.$order['short_id'], 'kamp_source' => 'hi_events', 'kamp_environment' => 'live'];
        foreach ($metadata as $k => $v) {
            self::require(($pi['metadata'][$k] ?? null) === $v);
        }
        $events = $input['original_events'] ?? [];
        self::require(($input['original_events_complete'] ?? null) === true && count($events) === 1);
        $event = $events[0];
        self::require(($event['account'] ?? null) === $scope['stripe_account'] && ($event['livemode'] ?? null) === true && ($event['type'] ?? null) === 'charge.succeeded' && ($event['data']['object']['id'] ?? null) === $charge['id'] && ($event['data']['object']['payment_intent'] ?? null) === $pi['id']);
        self::require(is_int($charge['created'] ?? null) && is_int($event['created'] ?? null) && abs($event['created'] - $charge['created']) <= 120 && is_string($event['id'] ?? null));
        $search = $input['search'] ?? [];
        $messages = $input['messages'] ?? [];
        $locator = strtolower(trim($order['email']));
        self::require(filter_var($locator, FILTER_VALIDATE_EMAIL) !== false && ($search['recipient'] ?? null) === $locator && ($search['server'] ?? null) === $scope['postmark_server'] && ($search['stream'] ?? null) === 'outbound');
        self::require(($search['complete'] ?? null) === true && ($search['offset'] ?? null) === 0 && ($search['cap'] ?? null) === 20 && ($search['total'] ?? null) === count($messages) && count($messages) > 0 && count($messages) <= 20);
        self::require(self::time($search['from']) === $charge['created'] - 30 && self::time($search['to']) === $charge['created'] + 600);
        // Strict admission deliberately rejects multiple candidates, including same-mailbox resends.
        self::require(count($messages) === 1);
        $m = $messages[0];
        self::require(($m['server'] ?? null) === $scope['postmark_server'] && ($m['MessageStream'] ?? null) === 'outbound' && ($m['MessageID'] ?? null) === ($search['message_ids'][0] ?? null) && count($search['message_ids'] ?? []) === 1 && preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $m['MessageID'] ?? '') === 1);
        self::require(($m['From'] ?? null) === $scope['sender'] && ($m['Cc'] ?? null) === [] && ($m['Bcc'] ?? null) === [] && count($m['To'] ?? []) === 1 && strtolower(trim($m['To'][0]['Email'] ?? '')) === $locator);
        $received = self::time($m['ReceivedAt'] ?? null);
        self::require($received >= $charge['created'] - 30 && $received <= $charge['created'] + 600);
        $delivered = array_values(array_filter($m['MessageEvents'] ?? [], fn ($e) => ($e['Type'] ?? null) === 'Delivered'));
        self::require(count($delivered) === 1 && self::time($delivered[0]['ReceivedAt'] ?? null) >= $received && self::time($delivered[0]['ReceivedAt']) <= self::time($scope['selected_at']));
        $text = str_replace("\r\n", "\n", $m['TextBody'] ?? '');
        self::require(strlen($text) <= 65536 && strlen($m['HtmlBody'] ?? '') <= 262144);
        $lines = array_map('trim', explode("\n", $text));
        // Parse the configured exact URL, not a substring, including event identity in its path.
        $summary = str_replace(['{event}', '{order}'], ['7', $order['short_id']], $scope['summary_url_template']);
        $url = parse_url($summary);
        self::require(($url['scheme'] ?? null) === 'https' && ! isset($url['query']) && ! isset($url['fragment']) && ! isset($url['user']) && ! isset($url['pass']) && str_starts_with($summary, $scope['summary_origin'].'/'));
        self::require(count(array_keys($lines, 'Order Summary', true)) === 1 && count(array_keys($lines, 'Order Number: '.$order['public_id'], true)) === 1 && count(array_keys($lines, 'Total Amount: '.$order['formatted_total'], true)) === 1 && count(array_keys($lines, $summary, true)) === 1);
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            self::require($dom->loadHTML($m['HtmlBody'] ?? '', LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            if (trim($a->textContent) === 'View Order Summary & Tickets') {
                $links[] = $a->getAttribute('href');
            }
        }
        self::require($links === [$summary]);

        return [
            'authority_type' => self::VERSION, 'predicate_version' => self::VERSION, 'source_revision' => $scope['source_revision'],
            'order_id' => $order['id'], 'event_id' => 7, 'account_id' => 1, 'organizer_id' => 2,
            'created_at' => $order['created_at'], 'short_id' => $order['short_id'], 'public_id' => $order['public_id'], 'recipient' => $locator,
            'native_payment_id' => $payment['id'], 'native_outbox_id' => $outbox['id'],
            'stripe_platform' => $scope['stripe_platform'], 'stripe_platform_account' => $scope['stripe_platform_account'], 'stripe_account' => $scope['stripe_account'],
            'pi' => $pi['id'], 'charge' => $charge['id'], 'charge_created' => $charge['created'], 'original_event' => $event['id'], 'original_event_created' => $event['created'],
            'postmark_server' => $scope['postmark_server'], 'stream' => 'outbound', 'message_id' => $m['MessageID'], 'received_at' => $m['ReceivedAt'], 'delivered_at' => $delivered[0]['ReceivedAt'],
            'sender' => $scope['sender'], 'template' => 'order-summary-en-v1', 'search_from' => $search['from'], 'search_to' => $search['to'], 'search_count' => 1,
        ];
    }
}

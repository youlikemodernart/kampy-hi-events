<?php

namespace HiEvents\Services\Domain\Registration;

use DateTimeImmutable;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Services\Domain\Email\EmailHtmlToTextConverter;

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
        $m = $this->selectReceipt($scope, $order, $search, $input['messages'] ?? [], $charge['created']);
        $locator = strtolower(trim($order['email']));
        $delivered = array_values(array_filter($m['MessageEvents'], fn ($e) => is_array($e) && ($e['Type'] ?? null) === 'Delivered'));

        return [
            'authority_type' => self::VERSION, 'predicate_version' => self::VERSION, 'source_revision' => $scope['source_revision'],
            'order_id' => $order['id'], 'event_id' => 7, 'account_id' => 1, 'organizer_id' => 2,
            'created_at' => $order['created_at'], 'short_id' => $order['short_id'], 'public_id' => $order['public_id'], 'recipient' => $locator,
            'native_payment_id' => $payment['id'], 'native_outbox_id' => $outbox['id'],
            'stripe_platform' => $scope['stripe_platform'], 'stripe_platform_account' => $scope['stripe_platform_account'], 'stripe_account' => $scope['stripe_account'],
            'pi' => $pi['id'], 'charge' => $charge['id'], 'charge_created' => $charge['created'], 'original_event' => $event['id'], 'original_event_created' => $event['created'],
            'postmark_server' => $scope['postmark_server'], 'stream' => 'outbound', 'message_id' => $m['MessageID'], 'received_at' => $m['ReceivedAt'], 'delivered_at' => $delivered[0]['ReceivedAt'],
            'sender' => $scope['sender'], 'template' => 'order-summary-en-v1', 'search_from' => $search['from'], 'search_to' => $search['to'], 'search_count' => $search['total'],
        ];
    }

    /** Classify every complete result; a ticket is not a competing purchase receipt. */
    public function selectReceipt(array $scope, array $order, array $search, array $messages, int $chargeCreated): array
    {
        $locator = strtolower(trim($order['email']));
        self::require(filter_var($locator, FILTER_VALIDATE_EMAIL) !== false && ($search['recipient'] ?? null) === $locator && ($search['server'] ?? null) === $scope['postmark_server'] && ($search['stream'] ?? null) === 'outbound');
        self::require(array_is_list($messages) && ($search['complete'] ?? null) === true && ($search['offset'] ?? null) === 0 && ($search['cap'] ?? null) === 20 && ($search['total'] ?? null) === count($messages) && count($messages) > 0 && count($messages) <= 20);
        $ids = $search['message_ids'] ?? [];
        self::require(is_array($ids) && array_is_list($ids) && count($ids) === count($messages));
        self::require(self::time($search['from']) === $chargeCreated - 30 && self::time($search['to']) === $chargeCreated + 600);
        $receipts = [];
        $seen = [];
        foreach ($messages as $i => $m) {
            self::require(is_array($m) && is_string($ids[$i]) && preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $ids[$i]) === 1);
            self::require(! isset($seen[strtolower($ids[$i])]) && ($m['server'] ?? null) === $scope['postmark_server'] && ($m['MessageStream'] ?? null) === 'outbound' && ($m['MessageID'] ?? null) === $ids[$i]);
            $seen[strtolower($ids[$i])] = true;
            self::require($this->senderMatches($m['From'] ?? null, $scope['sender']) && ($m['Cc'] ?? null) === [] && ($m['Bcc'] ?? null) === [] && is_array($m['To'] ?? null) && count($m['To']) === 1 && is_string($m['To'][0]['Email'] ?? null) && strtolower(trim($m['To'][0]['Email'])) === $locator);
            $received = self::time($m['ReceivedAt'] ?? null);
            self::require($received >= $chargeCreated - 30 && $received <= $chargeCreated + 600);
            self::require(is_array($m['MessageEvents'] ?? null));
            $delivered = array_values(array_filter($m['MessageEvents'], fn ($e) => is_array($e) && ($e['Type'] ?? null) === 'Delivered'));
            self::require(count($delivered) === 1 && self::time($delivered[0]['ReceivedAt'] ?? null) >= $received && self::time($delivered[0]['ReceivedAt']) <= self::time($scope['selected_at']));
            $category = $this->classifyBody($scope, $order, $m);
            self::require($category !== 'unknown');
            if ($category === 'order_receipt') {
                $receipts[] = $m;
            }
        }
        // Never choose earliest or collapse same-address duplicate receipts.
        self::require(count($receipts) === 1);

        return $receipts[0];
    }

    private function classifyBody(array $scope, array $order, array $m): string
    {
        self::require(is_string($m['TextBody'] ?? null) && $m['TextBody'] !== '' && strlen($m['TextBody']) <= 65536 && is_string($m['HtmlBody'] ?? null) && $m['HtmlBody'] !== '' && strlen($m['HtmlBody']) <= 262144);
        $text = str_replace("\r\n", "\n", $m['TextBody']);
        $lines = $this->nativeTextLines($text);
        $htmlLines = array_map('trim', explode("\n", EmailHtmlToTextConverter::convert($m['HtmlBody'])));
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            self::require($dom->loadHTML($m['HtmlBody'], LIBXML_NONET));
            foreach (libxml_get_errors() as $error) {
                self::require($error->level < LIBXML_ERR_ERROR);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $links[trim($a->textContent)][] = $a->getAttribute('href');
        }
        $summary = str_replace(['{event}', '{order}'], ['7', $order['short_id']], $scope['summary_url_template']);
        $url = parse_url($summary);
        self::require(($url['scheme'] ?? null) === 'https' && ! isset($url['query']) && ! isset($url['fragment']) && ! isset($url['user']) && ! isset($url['pass']) && str_starts_with($summary, $scope['summary_origin'].'/'));
        $summaryPattern = '~\A'.str_replace(['\{event\}', '\{order\}'], ['7', '[A-Za-z0-9_-]+'], preg_quote($scope['summary_url_template'], '~')).'\z~';
        $receiptLinks = $links['View Order Summary & Tickets'] ?? [];
        $ticketLinks = $links['View Ticket'] ?? [];
        $body = html_entity_decode($text."\n".$m['HtmlBody'], ENT_QUOTES | ENT_HTML5);
        $hasTarget = str_contains($body, $order['public_id']) || str_contains($body, $order['short_id']);
        preg_match_all('~https?://[^\\s<>"\']+~', $body, $bodyUrls);
        $identityUrls = array_merge($bodyUrls[0], ...array_values($links));
        if (count($receiptLinks) === 1 && $ticketLinks === [] && preg_match($summaryPattern, $receiptLinks[0]) === 1) {
            $receiptUrl = $receiptLinks[0];
            $plain = $this->receiptFields($lines);
            $html = $this->receiptFields($htmlLines);
            if ($plain === null || $plain !== $html || count(array_keys($lines, 'View Order Summary & Tickets:', true)) !== 1 || count(array_keys($lines, $receiptUrl, true)) !== 1) {
                return 'unknown';
            }
            // Every structural summary URL, including the native fallback link, must agree.
            foreach ($identityUrls as $value) {
                if (preg_match($summaryPattern, $value) === 1 && $value !== $receiptUrl) {
                    return 'unknown';
                }
            }
            if ($receiptUrl === $summary && $plain['public_id'] === $order['public_id'] && $plain['total'] === $order['formatted_total']) {
                return 'order_receipt';
            }
            if ($receiptUrl !== $summary && $plain['public_id'] !== $order['public_id'] && ! $hasTarget) {
                return 'other_order_receipt';
            }

            return 'unknown';
        }
        // The native attendee template has a distinct product URL and no receipt fields.
        if (! $hasTarget && $receiptLinks === [] && count($ticketLinks) === 1 && ! preg_match('/Order Summary|Order Number:|Total Amount:/i', $body)) {
            $ticketUrl = $ticketLinks[0];
            $ticketPattern = '~\A'.preg_quote($scope['summary_origin'], '~').'/product/7/[A-Za-z0-9_-]+\z~';
            if (preg_match($ticketPattern, $ticketUrl) !== 1 || count(array_keys($lines, 'Please find your ticket details below.', true)) !== 1 || count(array_keys($htmlLines, 'Please find your ticket details below.', true)) !== 1 || count(array_keys($lines, 'View Ticket:', true)) !== 1 || count(array_keys($lines, $ticketUrl, true)) !== 1) {
                return 'unknown';
            }
            foreach ($identityUrls as $value) {
                if (preg_match($summaryPattern, $value) === 1 || (preg_match($ticketPattern, $value) === 1 && $value !== $ticketUrl)) {
                    return 'unknown';
                }
            }

            return 'attendee_ticket';
        }

        return 'unknown';
    }

    private function senderMatches(mixed $from, string $sender): bool
    {
        // Postmark retains the native display name; authority is the exact single mailbox.
        return is_string($from) && ($from === $sender || preg_match('/\A(?:"[^"\r\n<>]+"|[^"\r\n<>,]+) <'.preg_quote($sender, '/').'>\z/u', $from) === 1);
    }

    private function nativeTextLines(string $text): array
    {
        // Laravel's native Markdown mail and the university template encode these same fields differently.
        $text = preg_replace('/^# Order Summary$/m', 'Order Summary', $text);
        $text = preg_replace('/^- \*\*(Order Number:|Total Amount:)\*\* (.+)$/m', '$1 $2', $text);
        $text = preg_replace('/^(View Order Summary & Tickets|View Ticket): (https:\/\/[^\s]+)$/m', "$1:\n$2", $text);

        return array_map('trim', explode("\n", $text));
    }

    private function receiptFields(array $lines): ?array
    {
        $numbers = array_values(array_filter($lines, fn ($line) => str_starts_with($line, 'Order Number:')));
        $totals = array_values(array_filter($lines, fn ($line) => str_starts_with($line, 'Total Amount:')));
        if (count(array_keys($lines, 'Order Summary', true)) !== 1 || count($numbers) !== 1 || count($totals) !== 1 || preg_match('/\AOrder Number: ([A-Za-z0-9_-]+)\z/', $numbers[0], $number) !== 1 || preg_match('/\ATotal Amount: (.{1,64})\z/u', $totals[0], $total) !== 1) {
            return null;
        }

        return ['public_id' => $number[1], 'total' => $total[1]];
    }
}

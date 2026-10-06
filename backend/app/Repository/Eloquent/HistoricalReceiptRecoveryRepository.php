<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\Services\Domain\Registration\HistoricalReceiptValidator as V;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/** One importer; no provider client, scheduler, mail, assignment, or checkout-anchor writes. */
final class HistoricalReceiptRecoveryRepository
{
    private function integrityKey(string $version): string
    {
        $key = config('historical-receipt-recovery.integrity_keys.'.$version);
        V::require(is_string($key) && strlen($key) === 32);

        return $key;
    }

    private function cipher(string $version): Encrypter
    {
        $key = config('historical-receipt-recovery.encryption_keys.'.$version);
        V::require(is_string($key) && strlen($key) === 32);

        return new Encrypter($key, 'AES-256-GCM');
    }

    public function import(array $manifest, array $rows, callable $absencePreflight, bool $commit = false): array
    {
        $digest = hash('sha256', V::canonical($manifest));
        V::require(hash_equals((string) config('historical-receipt-recovery.approved_manifest_digest'), $digest));
        $scope = $manifest['scope'];
        $ids = $manifest['order_ids'];
        V::require(count($ids) > 0 && count($ids) <= 149 && count(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) === count($ids) && count(array_unique($ids)) === count($ids) && array_is_list($ids));
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        V::require($ids === $sorted);
        V::require(V::time($scope['selected_at']) <= now()->timestamp && V::time($scope['selected_at']) < V::time($scope['valid_until']) && V::time($scope['valid_until']) <= V::time($scope['purge_after']) && V::time($scope['valid_until']) > now()->timestamp && V::time($scope['valid_until']) <= V::time('2026-10-17T20:00:00Z'));
        V::require(is_string($scope['approved_effect_reference']) && $scope['approved_effect_reference'] !== '' && $scope['source_revision'] !== '');
        $quarantine = $manifest['quarantined_association'];
        V::require($manifest['quarantined_order_ids'] === [$quarantine['order_id']] && hash_equals($manifest['quarantine_commitment'], hash('sha256', implode('|', [$quarantine['order_id'], $quarantine['pi'], $quarantine['charge'], $quarantine['message_id']]))));
        $integrityVersion = (string) config('historical-receipt-recovery.integrity_key_version');
        $evidenceKey = $this->integrityKey($integrityVersion);
        $reconstructed = [];
        $evidence = [];
        $receiptIds = [];
        // Reproduce the full original ordered commitment before selecting the exact admitted subset.
        V::require(array_column($rows, 'order_id') === $manifest['reconstructed_order_ids'] && count($rows) <= 149 && count(array_unique($manifest['reconstructed_order_ids'])) === count($rows));
        V::require(count($manifest['quarantined_order_ids']) === 1 && ! array_intersect($manifest['quarantined_order_ids'], $manifest['reconstructed_order_ids']) && ! array_diff($ids, $manifest['reconstructed_order_ids']));
        foreach ($rows as $row) {
            $accepted = (new V)->validate($scope, $row['order'], $row['evidence']);
            V::require($row['order_id'] === $accepted['order_id']);
            $identity = $accepted['postmark_server'].'|'.$accepted['message_id'];
            V::require(! isset($receiptIds[$identity]));
            $receiptIds[$identity] = true;
            $reconstructed[] = hash('sha256', implode('|', [$row['order_id'], $accepted['pi'], $accepted['charge'], $accepted['message_id']]));
            if (in_array($row['order_id'], $ids, true)) {
                V::require(hash_equals($manifest['evidence_commitments'][(string) $row['order_id']] ?? '', hash_hmac('sha256', V::canonical($accepted), $evidenceKey)));
                $evidence[$row['order_id']] = $accepted;
            }
        }
        V::require(count($evidence) === count($ids) && count($manifest['evidence_commitments']) === count($ids));
        V::require(hash_equals($manifest['original_aggregate_commitment'], hash('sha256', implode("\n", $reconstructed))));
        if (! $commit) {
            return ['dry_run' => true, 'count' => count($evidence), 'manifest_digest' => $digest];
        }
        V::require(config('historical-receipt-recovery.import_enabled') === true);
        $preflights = [];
        foreach ($evidence as $id => $_) {
            $preflights[$id] = $absencePreflight($id);
            V::require(is_array($preflights[$id]) && count($preflights[$id]) > 0);
        }

        return DB::transaction(function () use ($scope, $ids, $digest, $evidence, $preflights) {
            $enc = (string) config('historical-receipt-recovery.encryption_key_version');
            $integrity = (string) config('historical-receipt-recovery.integrity_key_version');
            $cipher = $this->cipher($enc);
            $key = $this->integrityKey($integrity);
            $cohort = DB::table('historical_receipt_recovery_cohorts')->insertGetId([
                'event_id' => 7, 'account_id' => 1, 'organizer_id' => 2, 'manifest_digest' => $digest,
                'scope_json' => V::canonical($scope), 'exact_order_count' => count($ids), 'predicate_version' => V::VERSION,
                'source_revision' => $scope['source_revision'], 'selected_at' => $scope['selected_at'], 'approved_effect_reference' => $scope['approved_effect_reference'],
                'valid_until' => $scope['valid_until'], 'purge_after' => $scope['purge_after'],
            ]);
            foreach ($evidence as $id => $value) {
                $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
                V::require($order && (int) $order->event_id === 7 && $order->status === 'COMPLETED' && $order->payment_status === 'PAYMENT_RECEIVED' && $order->refund_status === null && (float) $order->total_refunded === 0.0 && $order->deleted_at === null && $order->short_id === $value['short_id'] && $order->public_id === $value['public_id'] && strtolower(trim($order->email)) === $value['recipient']);
                V::require(! DB::table('gvsu_registration_assignments')->where('order_id', $id)->exists() && ! DB::table('order_purchase_contacts')->where('order_id', $id)->exists());
                V::require(DB::table('stripe_payments')->where('order_id', $id)->whereNull('deleted_at')->count() === 1 && DB::table('stripe_payments')->where('id', $value['native_payment_id'])->where('order_id', $id)->where('payment_intent_id', $value['pi'])->where('charge_id', $value['charge'])->where('connected_account_id', $value['stripe_account'])->where('stripe_platform', $value['stripe_platform'])->exists());
                V::require(DB::table('order_effect_outbox')->where('id', $value['native_outbox_id'])->where('order_id', $id)->where('transition_key', 'STRIPE_COMPLETED')->where('email_kind', 'DETAILS_AND_TICKETS')->where('status', 'DELIVERED')->exists());
                V::require(DB::table('events')->where('id', 7)->where('account_id', 1)->where('organizer_id', 2)->where('status', 'LIVE')->whereNull('deleted_at')->where('end_date', '>', now())->exists());
                $siblings = DB::table('attendees')->where('order_id', $id)->where('event_id', 7)->where('status', 'ACTIVE')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get(['id', 'public_id'])->map(fn ($a) => ['attendee_id' => (string) $a->id, 'public_ticket_id' => $a->public_id])->all();
                V::require(count($siblings) > 0 && $siblings === $preflights[$id]);
                $value['cohort_id'] = $cohort;
                $value['manifest_digest'] = $digest;
                $canonical = V::canonical($value);
                DB::table('order_receipt_recovery_evidence')->insert([
                    'cohort_id' => $cohort, 'order_id' => $id, 'event_id' => 7, 'authority_type' => V::VERSION,
                    'evidence_encrypted' => $cipher->encryptString($canonical), 'evidence_commitment' => hash_hmac('sha256', $canonical, $key),
                    'encryption_key_version' => $enc, 'integrity_key_version' => $integrity,
                    'receipt_identity_digest' => hash('sha256', $value['postmark_server'].'|'.$value['message_id']), 'observed_at' => $scope['selected_at'],
                ]);
            }
            DB::table('historical_receipt_recovery_cohorts')->where('id', $cohort)->update(['sealed_at' => now()]);

            return ['dry_run' => false, 'count' => count($ids), 'manifest_digest' => $digest];
        });
    }

    public function preflight(int $orderId): ?array
    {
        $siblings = DB::table('attendees')->where('order_id', $orderId)->where('event_id', 7)->where('status', 'ACTIVE')->whereNull('deleted_at')->orderBy('id')->get(['id', 'public_id'])->map(fn ($a) => ['attendee_id' => (string) $a->id, 'public_ticket_id' => $a->public_id])->all();
        if (count($siblings) === 0) {
            return null;
        }
        $identity = ['operation' => 'historical-receipt-preflight-v1', 'event_id' => '7', 'order_id' => (string) $orderId, 'siblings' => $siblings];

        return app(\HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient::class)->historicalAbsence($identity) ? $siblings : null;
    }

    public function authority(int $orderId): ?array
    {
        if (config('historical-receipt-recovery.enabled') !== true) {
            return null;
        }
        $row = DB::table('order_receipt_recovery_evidence as e')->join('historical_receipt_recovery_cohorts as c', 'c.id', '=', 'e.cohort_id')
            ->where('e.order_id', $orderId)->whereNotNull('c.sealed_at')->whereNull('c.revoked_at')->whereNull('e.purged_at')->where('c.valid_until', '>', now())
            ->where('c.manifest_digest', config('historical-receipt-recovery.approved_manifest_digest'))
            ->first(['e.*', 'c.manifest_digest', 'c.valid_until']);
        if (! $row) {
            return null;
        }
        $plain = $this->cipher($row->encryption_key_version)->decryptString($row->evidence_encrypted);
        V::require(hash_equals($row->evidence_commitment, hash_hmac('sha256', $plain, $this->integrityKey($row->integrity_key_version))));
        $value = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        V::require($value['order_id'] === $orderId && $value['cohort_id'] === (int) $row->cohort_id && $value['authority_type'] === V::VERSION && $value['manifest_digest'] === $row->manifest_digest && $value['event_id'] === 7 && $value['account_id'] === 1 && $value['organizer_id'] === 2);
        if (! DB::table('orders')->where('id', $orderId)->where('event_id', 7)->where('short_id', $value['short_id'])->where('public_id', $value['public_id'])->whereNull('deleted_at')->exists()
            || ! DB::table('events')->where('id', 7)->where('account_id', 1)->where('organizer_id', 2)->whereNull('deleted_at')->exists()) {
            return null;
        }

        return ['type' => V::VERSION, 'id' => (int) $row->id, 'commitment' => $row->evidence_commitment, 'email' => $value['recipient'], 'cohort_id' => (int) $row->cohort_id, 'valid_until' => $row->valid_until];
    }

    public function purge(): int
    {
        return DB::transaction(function () {
            $ids = DB::table('order_receipt_recovery_evidence as e')->join('historical_receipt_recovery_cohorts as c', 'c.id', '=', 'e.cohort_id')->where('c.purge_after', '<=', now())->whereNull('e.purged_at')->pluck('e.id');
            DB::table('respondent_confirmation_challenges')->where('authority_type', V::VERSION)->whereIn('authority_id', $ids)->update(['expires_at' => now()]);

            return DB::table('order_receipt_recovery_evidence')->whereIn('id', $ids)->update(['evidence_encrypted' => null, 'purged_at' => now()]);
        });
    }
}

<?php

namespace HiEvents\Repository\Eloquent;

use Carbon\CarbonImmutable;
use HiEvents\Models\Attendee;
use HiEvents\Models\GvsuRegistrationAssignment;
use HiEvents\Models\Order;
use HiEvents\Services\Domain\Registration\DTO\RespondentChallengeDelivery;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeConfig;
use Illuminate\Support\Facades\DB;

final class RespondentConfirmationRepository
{
    private function paidOrder(string $shortId): ?Order
    {
        $order = Order::query()->where('short_id', $shortId)->where('event_id', 7)->lockForUpdate()->first();
        if (! $order || $order->status !== 'COMPLETED' || $order->payment_status !== 'PAYMENT_RECEIVED'
            || $order->refund_status !== null || (float) $order->total_refunded !== 0.0
            || ! GvsuRegistrationBridgeConfig::allowsCohort(7, (int) $order->id)
            || ! DB::table('events')->where('id', 7)->whereNull('deleted_at')->where('status', 'LIVE')->where('end_date', '>', now())->sharedLock()->first()) {
            return null;
        }

        return $order;
    }

    private function destinationDigest(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
    }

    public function issue(string $shortId): ?RespondentChallengeDelivery
    {
        return DB::transaction(function () use ($shortId) {
            $order = $this->paidOrder($shortId);
            $email = $order ? (new OrderPurchaseContactRepository)->email((int) $order->id) : null;
            if (! $order || ! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                || ! Attendee::query()->where('order_id', $order->id)->where('event_id', 7)->where('status', 'ACTIVE')->exists()
                || GvsuRegistrationAssignment::query()->where('order_id', $order->id)->exists()) {
                return null;
            }
            $digest = $this->destinationDigest($email);
            DB::table('respondent_confirmation_destinations')->insertOrIgnore(['destination_digest' => $digest]);
            $bucket = DB::table('respondent_confirmation_destinations')->where('destination_digest', $digest)->lockForUpdate()->first();
            $windowOpen = $bucket->window_started_at && CarbonImmutable::parse($bucket->window_started_at)->gt(now()->subHour());
            $count = $windowOpen ? (int) $bucket->request_count : 0;
            if (($bucket->last_requested_at && CarbonImmutable::parse($bucket->last_requested_at)->gt(now()->subSeconds(config('respondent-confirmation.cooldown_seconds'))))
                || $count >= config('respondent-confirmation.requests_per_hour')
                || DB::table('respondent_confirmation_challenges')->where('order_id', $order->id)->where('created_at', '>', now()->subHour())->count() >= config('respondent-confirmation.requests_per_hour')) {
                return null;
            }
            DB::table('respondent_confirmation_destinations')->where('id', $bucket->id)->update([
                'last_requested_at' => now(), 'window_started_at' => $windowOpen ? $bucket->window_started_at : now(), 'request_count' => $count + 1,
            ]);
            // A new request invalidates the previous code without storing or logging its plaintext.
            DB::table('respondent_confirmation_challenges')->where('order_id', $order->id)->whereNull('consumed_at')->update(['expires_at' => now()]);
            $token = bin2hex(random_bytes(32));
            DB::table('respondent_confirmation_challenges')->insert([
                'order_id' => $order->id, 'destination_digest' => $digest, 'token_digest' => hash('sha256', $token),
                'created_at' => now(), 'expires_at' => now()->addMinutes(config('respondent-confirmation.ttl_minutes')),
            ]);

            return new RespondentChallengeDelivery($email, $token);
        });
    }

    /** Callback binds every sibling and records only a bridge outbox effect in this transaction. */
    public function confirm(string $shortId, string $token, array $respondents, callable $bind): bool
    {
        return DB::transaction(function () use ($shortId, $token, $respondents, $bind) {
            $order = $this->paidOrder($shortId);
            $email = $order ? (new OrderPurchaseContactRepository)->email((int) $order->id) : null;
            if (! $order || ! $email) {
                return false;
            }
            $challenge = DB::table('respondent_confirmation_challenges')->where('order_id', $order->id)->orderByDesc('id')->lockForUpdate()->first();
            if (! $challenge || CarbonImmutable::parse($challenge->expires_at)->lte(now())
                || $challenge->attempts >= config('respondent-confirmation.attempts')
                || ! hash_equals($challenge->destination_digest, $this->destinationDigest($email))) {
                return false;
            }
            if (! hash_equals($challenge->token_digest, hash('sha256', $token))) {
                DB::table('respondent_confirmation_challenges')->where('id', $challenge->id)->increment('attempts');

                return false;
            }
            $attendees = Attendee::query()->where('order_id', $order->id)->where('event_id', 7)->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get();
            if ($attendees->isEmpty() || $attendees->pluck('id')->map(fn ($id) => (int) $id)->all() !== array_column($respondents, 'attendee_id')) {
                return false;
            }
            $payloadDigest = hash('sha256', json_encode($respondents, JSON_THROW_ON_ERROR));
            if ($challenge->consumed_at !== null) {
                return hash_equals($challenge->confirmation_digest, $payloadDigest);
            }
            if (GvsuRegistrationAssignment::query()->where('order_id', $order->id)->exists()) {
                return false;
            }
            $bind((int) $order->id, $attendees, $respondents);
            DB::table('respondent_confirmation_challenges')->where('id', $challenge->id)->update(['consumed_at' => now(), 'confirmation_digest' => $payloadDigest]);

            return true;
        });
    }
}

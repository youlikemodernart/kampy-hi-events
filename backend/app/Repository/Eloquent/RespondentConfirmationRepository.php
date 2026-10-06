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

    /** External absence read occurs before acquiring native locks. The exact sibling snapshot is rechecked under lock. */
    private function preflight(string $shortId): ?array
    {
        $order = Order::query()->where('short_id', $shortId)->where('event_id', 7)->first();
        if (! $order || ! GvsuRegistrationBridgeConfig::allowsCohort(7, (int) $order->id)) {
            return null;
        }
        $authority = (new RespondentContactAuthorityRepository)->resolve((int) $order->id);
        if (! $authority) {
            return null;
        }
        if ($authority['type'] !== 'historical_receipt_v1') {
            return [];
        }
        $identity = $this->siblingIdentity((int) $order->id);
        try {
            if (count($identity['siblings']) === 0 || ! app(\HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient::class)->historicalAbsence($identity)) {
                return null;
            }
        } catch (\HiEvents\Exceptions\GvsuRegistrationBridgeUnknownException) {
            return null;
        }

        return $identity;
    }

    private function siblingIdentity(int $orderId): array
    {
        return ['operation' => 'historical-receipt-preflight-v1', 'event_id' => '7', 'order_id' => (string) $orderId,
            'siblings' => Attendee::query()->where('order_id', $orderId)->where('event_id', 7)->where('status', 'ACTIVE')->orderBy('id')->get()->map(fn ($a) => ['attendee_id' => (string) $a->id, 'public_ticket_id' => $a->public_id])->all()];
    }

    private function contextDigest($siblings): string
    {
        return hash('sha256', json_encode($siblings->map(fn ($a) => [(int) $a->id, $a->public_id, $a->first_name, $a->last_name])->all(), JSON_THROW_ON_ERROR));
    }

    private function matchesAuthority(object $challenge, array $authority): bool
    {
        return $challenge->authority_type === $authority['type'] && (int) $challenge->authority_id === $authority['id']
            && hash_equals((string) $challenge->authority_commitment, $authority['commitment'])
            && hash_equals($challenge->destination_digest, $this->destinationDigest($authority['email']));
    }

    public function issue(string $shortId, bool $invitation = false): ?RespondentChallengeDelivery
    {
        if (config('respondent-confirmation.enabled') !== true || ($invitation && config('respondent-confirmation.invitation_enabled') !== true)) {
            return null;
        }
        $preflight = $this->preflight($shortId);
        if ($preflight === null) {
            return null;
        }

        return DB::transaction(function () use ($shortId, $preflight, $invitation) {
            $order = $this->paidOrder($shortId);
            $authority = $order ? (new RespondentContactAuthorityRepository)->resolve((int) $order->id) : null;
            $email = $authority['email'] ?? null;
            if ($authority && $authority['type'] === 'historical_receipt_v1' && $preflight !== $this->siblingIdentity((int) $order->id)) {
                return null;
            }
            if (! $order || ! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                || ! Attendee::query()->where('order_id', $order->id)->where('event_id', 7)->where('status', 'ACTIVE')->exists()
                || GvsuRegistrationAssignment::query()->where('order_id', $order->id)->exists()) {
                return null;
            }
            // The committed challenge reserves the canary's only synchronous attempt, even if delivery is unknown.
            if (GvsuRegistrationBridgeConfig::mode() === 'canary'
                && (! $invitation || CarbonImmutable::parse(config('respondent-confirmation.invitation_deadline'))->lte(now())
                    || DB::table('respondent_confirmation_challenges')->where('order_id', $order->id)->exists())) {
                return null;
            }
            // Email invitations last through the existing event window, not the interactive code TTL.
            $expiresAt = now()->addMinutes(config('respondent-confirmation.ttl_minutes'));
            if ($invitation) {
                $expiresAt = CarbonImmutable::parse(config('respondent-confirmation.invitation_deadline'))
                    ->min(CarbonImmutable::parse(DB::table('events')->where('id', 7)->value('end_date')));
                if ($authority['type'] === 'historical_receipt_v1') {
                    $expiresAt = $expiresAt->min(CarbonImmutable::parse($authority['valid_until']));
                }
                if ($expiresAt->lte(now())) {
                    return null;
                }
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
                'invitation' => $invitation,
                'verified_at' => null,
                'verified_context_digest' => $invitation ? $this->contextDigest(Attendee::query()->where('order_id', $order->id)->where('event_id', 7)->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get()) : null,
                'authority_type' => $authority['type'], 'authority_id' => $authority['id'], 'authority_commitment' => $authority['commitment'],
                'order_id' => $order->id, 'destination_digest' => $digest, 'token_digest' => hash('sha256', $token),
                'created_at' => now(), 'expires_at' => $expiresAt,
            ]);

            return new RespondentChallengeDelivery($email, $token);
        });
    }

    /** Read-only capability resolution: opening/scanning a link never spends it. */
    public function invitationContext(string $shortId, string $token): ?array
    {
        if (config('respondent-confirmation.enabled') !== true || config('respondent-confirmation.invitation_enabled') !== true || ! preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            return null;
        }

        return DB::transaction(function () use ($shortId, $token) {
            $order = $this->paidOrder($shortId);
            $authority = $order ? (new RespondentContactAuthorityRepository)->resolve((int) $order->id) : null;
            $challenge = $order ? DB::table('respondent_confirmation_challenges')->where('order_id', $order->id)->orderByDesc('id')->first() : null;
            if (! $authority || ! $challenge || ! $challenge->invitation || CarbonImmutable::parse($challenge->expires_at)->lte(now())
                || ! hash_equals($challenge->token_digest, hash('sha256', $token)) || ! $this->matchesAuthority($challenge, $authority)) {
                return null;
            }
            $siblings = Attendee::query()->where('order_id', $order->id)->where('event_id', 7)->where('status', 'ACTIVE')->orderBy('id')->get();
            if ($siblings->isEmpty() || ! hash_equals((string) $challenge->verified_context_digest, $this->contextDigest($siblings))) {
                return null;
            }
            if ($challenge->consumed_at === null && GvsuRegistrationAssignment::query()->where('order_id', $order->id)->exists()) {
                return null;
            }
            $assignments = GvsuRegistrationAssignment::query()->where('order_id', $order->id)->orderBy('attendee_id')->get();
            if ($challenge->consumed_at !== null) {
                $rows = $assignments->map(fn ($a) => ['attendee_id' => (int) $a->attendee_id, 'route' => $a->respondent_route,
                    'respondent_name' => $a->respondent_route === 'adult' ? '' : $a->respondent_display_name, 'email' => $a->delivery_destination_ciphertext])->all();
                if ($assignments->count() !== $siblings->count() || ! hash_equals((string) $challenge->confirmation_digest, hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)))) {
                    return null;
                }
            }

            return ['order_id' => (int) $order->id, 'confirmed' => $challenge->consumed_at !== null,
                'assignment_ids' => $challenge->consumed_at !== null ? $assignments->pluck('assignment_id')->all() : [],
                'siblings' => $siblings->map(fn ($a) => ['id' => (int) $a->id, 'first_name' => $a->first_name, 'last_name' => $a->last_name])->all()];
        });
    }

    public function verify(string $shortId, string $token): ?array
    {
        if (config('respondent-confirmation.enabled') !== true) {
            return null;
        }
        $preflight = $this->preflight($shortId);
        if ($preflight === null) {
            return null;
        }

        return DB::transaction(function () use ($shortId, $token, $preflight) {
            $order = $this->paidOrder($shortId);
            $authority = $order ? (new RespondentContactAuthorityRepository)->resolve((int) $order->id) : null;
            if (! $authority || GvsuRegistrationAssignment::query()->where('order_id', $order->id)->exists()) {
                return null;
            }
            $challenge = DB::table('respondent_confirmation_challenges')->where('order_id', $order->id)->orderByDesc('id')->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at !== null || CarbonImmutable::parse($challenge->expires_at)->lte(now()) || (! $challenge->invitation && $challenge->attempts >= config('respondent-confirmation.attempts')) || ! $this->matchesAuthority($challenge, $authority)) {
                return null;
            }
            if (! hash_equals($challenge->token_digest, hash('sha256', $token))) {
                if (! $challenge->invitation) {
                    DB::table('respondent_confirmation_challenges')->where('id', $challenge->id)->increment('attempts');
                }

                return null;
            }
            if ($authority['type'] === 'historical_receipt_v1' && $preflight !== $this->siblingIdentity((int) $order->id)) {
                return null;
            }
            $siblings = Attendee::query()->where('order_id', $order->id)->where('event_id', 7)->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get(['id', 'first_name', 'last_name', 'public_id']);
            if ($siblings->isEmpty()) {
                return null;
            }
            $context = $this->contextDigest($siblings);
            if ($challenge->verified_at !== null && ! hash_equals($challenge->verified_context_digest, $context)) {
                return null;
            }
            if ($challenge->verified_at === null) {
                DB::table('respondent_confirmation_challenges')->where('id', $challenge->id)->update(['verified_at' => now(), 'verified_context_digest' => $context]);
            }

            return $siblings->map(fn ($a) => ['id' => (int) $a->id, 'first_name' => $a->first_name, 'last_name' => $a->last_name])->all();
        });
    }

    /** Callback binds every sibling and records only a bridge outbox effect in this transaction. */
    public function confirm(string $shortId, string $token, array $respondents, callable $bind): bool
    {
        if (config('respondent-confirmation.enabled') !== true) {
            return false;
        }
        $preflight = $this->preflight($shortId);

        // Consumed exact replays need no fresh Portal absence, which is no longer true after handoff.
        return DB::transaction(function () use ($shortId, $token, $respondents, $bind, $preflight) {
            $order = $this->paidOrder($shortId);
            $authority = $order ? (new RespondentContactAuthorityRepository)->resolve((int) $order->id) : null;
            $email = $authority['email'] ?? null;
            if (! $order || ! $email) {
                return false;
            }
            $challenge = DB::table('respondent_confirmation_challenges')->where('order_id', $order->id)->orderByDesc('id')->lockForUpdate()->first();
            if (! $challenge || CarbonImmutable::parse($challenge->expires_at)->lte(now())
                || (! $challenge->invitation && $challenge->attempts >= config('respondent-confirmation.attempts'))
                || ! $this->matchesAuthority($challenge, $authority)) {
                return false;
            }
            if (! hash_equals($challenge->token_digest, hash('sha256', $token))) {
                if (! $challenge->invitation) {
                    DB::table('respondent_confirmation_challenges')->where('id', $challenge->id)->increment('attempts');
                }

                return false;
            }
            if (($challenge->invitation && config('respondent-confirmation.invitation_enabled') !== true) || ($challenge->verified_at === null && ! $challenge->invitation)) {
                return false;
            }
            $attendees = Attendee::query()->where('order_id', $order->id)->where('event_id', 7)->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get();
            if ($attendees->isEmpty() || $attendees->pluck('id')->map(fn ($id) => (int) $id)->all() !== array_column($respondents, 'attendee_id')) {
                return false;
            }
            if (! hash_equals((string) $challenge->verified_context_digest, $this->contextDigest($attendees))) {
                return false;
            }
            $payloadDigest = hash('sha256', json_encode($respondents, JSON_THROW_ON_ERROR));
            if ($challenge->consumed_at !== null) {
                return hash_equals($challenge->confirmation_digest, $payloadDigest);
            }
            if (GvsuRegistrationAssignment::query()->where('order_id', $order->id)->exists()) {
                return false;
            }
            if ($authority['type'] === 'historical_receipt_v1' && ($preflight === null || $preflight !== $this->siblingIdentity((int) $order->id))) {
                return false;
            }
            $bind((int) $order->id, $attendees, $respondents, $authority, (bool) $challenge->invitation);
            DB::table('respondent_confirmation_challenges')->where('id', $challenge->id)->update(['consumed_at' => now(), 'confirmation_digest' => $payloadDigest]);

            return true;
        });
    }
}

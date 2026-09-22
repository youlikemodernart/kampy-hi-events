<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Registration;

use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Exceptions\GvsuRegistrationBridgeUnknownException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Models\Attendee;
use HiEvents\Models\GvsuRegistrationAssignment;
use HiEvents\Models\Order;
use Illuminate\Support\Facades\DB;

class GvsuRegistrationBridgeService
{
    public function __construct(private readonly GvsuRegistrationBridgePortalClient $portal) {}

    /**
     * Binds one exact event-seven attendee context and an operator-confirmed respondent.
     * Attendee context never determines respondent identity, route, or capacity.
     *
     * @return array{status: 'bound'|'corrected'|'unchanged', assignment_id: string}
     */
    public function bindRespondent(
        int $orderId,
        int $attendeeId,
        string $attendeeDisplayName,
        string $respondentDisplayName,
        string $respondentRoute,
        string $deliveryDestination,
        ?string $guardianRelationshipReference,
    ): array {
        $attendeeName = $this->normalizedAttendeeDisplayName($attendeeDisplayName);
        $name = $this->normalizedRespondentDisplayName($respondentDisplayName);
        $route = $this->normalizedRoute($respondentRoute);
        $destination = $this->normalizedEmail($deliveryDestination);
        $relationship = $this->normalizedGuardianRelationshipReference($guardianRelationshipReference, $route);
        $identityDigest = $this->identityDigest($attendeeName, $name, $route, $relationship, $destination);

        $result = DB::transaction(function () use ($orderId, $attendeeId, $attendeeName, $name, $route, $destination, $relationship, $identityDigest): array {
            $order = Order::withTrashed()->select(['id', 'event_id'])->lockForUpdate()->find($orderId);
            $attendee = Attendee::withTrashed()
                ->select(['id', 'event_id', 'order_id', 'public_id', 'status', 'deleted_at'])
                ->where('id', $attendeeId)
                ->where('event_id', GvsuRegistrationBridgeConfig::EVENT_ID)
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();
            if ($order === null || (int) $order->event_id !== GvsuRegistrationBridgeConfig::EVENT_ID || $attendee === null
                || $attendee->deleted_at !== null || $attendee->status !== 'ACTIVE') {
                throw new ResourceConflictException(__('GVSU registration assignment attendee is unavailable.'));
            }

            $existing = GvsuRegistrationAssignment::query()
                ->where('event_id', GvsuRegistrationBridgeConfig::EVENT_ID)
                ->where('attendee_id', $attendeeId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null && $this->matchesIdentityDigest($existing->respondent_identity_digest_sha256, $attendeeName, $name, $route, $relationship, $destination)) {
                return ['status' => 'unchanged', 'assignment_id' => $existing->assignment_id];
            }

            $revision = $existing === null ? 1 : $existing->assignment_revision + 1;
            $assignmentId = $this->stableId('gra', [
                (string) GvsuRegistrationBridgeConfig::EVENT_ID,
                (string) $attendeeId,
                (string) $revision,
                $identityDigest,
            ]);
            $respondentId = $this->stableId('grr', [
                (string) GvsuRegistrationBridgeConfig::EVENT_ID,
                (string) $attendeeId,
                (string) $revision,
                $identityDigest,
            ]);
            $attributes = [
                'provision_batch_id' => null,
                'event_id' => GvsuRegistrationBridgeConfig::EVENT_ID,
                'order_id' => $orderId,
                'attendee_id' => $attendeeId,
                'attendee_public_id' => $attendee->public_id,
                'respondent_id' => $respondentId,
                'assignment_id' => $assignmentId,
                'assignment_revision' => $revision,
                'attendee_display_name' => $attendeeName,
                'respondent_display_name' => $name,
                'respondent_route' => $route,
                'guardian_relationship_reference' => $relationship,
                'respondent_identity_digest_sha256' => $identityDigest,
                'delivery_destination_ciphertext' => $destination,
                'payload_digest_sha256' => null,
                'delivery_email_hmac_sha256' => $this->emailHmac($destination),
                'status' => 'bound',
                'attempted_at' => null,
                'delivered_at' => null,
                'unknown_at' => null,
            ];
            if ($existing === null) {
                $assignment = GvsuRegistrationAssignment::query()->create($attributes + [
                    'replaced_assignment_id' => null,
                    'bound_at' => now(),
                    'corrected_at' => null,
                    'link_replacement_requested_at' => null,
                    'link_replacement_delivered_at' => null,
                ]);

                return ['status' => 'bound', 'assignment_id' => $assignment->assignment_id];
            }

            $linkReplacementRequired = $existing->status !== 'bound';
            $existing->fill($attributes + [
                'replaced_assignment_id' => $existing->assignment_id,
                'corrected_at' => now(),
                'link_replacement_requested_at' => $linkReplacementRequired ? now() : null,
                'link_replacement_delivered_at' => null,
            ]);
            $existing->save();

            return ['status' => 'corrected', 'assignment_id' => $assignmentId];
        });

        // This is a trigger only; the normal exact-order path still refuses any unassigned attendee.
        $this->provisionCompletedOrder($orderId);

        return $result;
    }

    /** Returns false only while the exact paid order is awaiting an explicit respondent assignment. */
    public function provisionCompletedOrder(int $orderId): bool
    {
        if (! GvsuRegistrationBridgeConfig::enabled()) {
            return false;
        }

        $batch = DB::transaction(function () use ($orderId): ?array {
            $order = Order::withTrashed()->lockForUpdate()->find($orderId);
            if ($order === null || ! $this->isCurrentPaidOrder($order) || (int) $order->event_id !== GvsuRegistrationBridgeConfig::EVENT_ID
                || ! GvsuRegistrationBridgeConfig::allowsCohort((int) $order->event_id, (int) $order->id)) {
                return ['terminal' => true];
            }

            // Do not read attendee records until the exact order has passed the canary boundary.
            $attendees = $order->attendees()->withTrashed()->get()
                ->filter(static fn (Attendee $attendee): bool => $attendee->deleted_at === null && $attendee->status === 'ACTIVE')
                ->sortBy('id')
                ->values();
            if ($attendees->isEmpty()) {
                return ['terminal' => true];
            }

            $assignments = GvsuRegistrationAssignment::query()
                ->where('event_id', $order->event_id)
                ->where('order_id', $order->id)
                ->whereIn('attendee_id', $attendees->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('attendee_id');
            if ($assignments->count() !== $attendees->count()) {
                return null;
            }

            $records = [];
            foreach ($attendees as $attendee) {
                $assignment = $assignments->get($attendee->id);
                if ($assignment === null) {
                    return null;
                }
                $destination = $assignment->delivery_destination_ciphertext;
                if (! is_string($assignment->attendee_display_name) || $assignment->attendee_display_name === ''
                    || ! is_string($assignment->respondent_display_name) || $assignment->respondent_display_name === ''
                    || ! in_array($assignment->respondent_route, ['adult', 'guardian'], true)
                    || ! is_string($assignment->respondent_identity_digest_sha256) || preg_match('/\\A[0-9a-f]{64}\\z/', $assignment->respondent_identity_digest_sha256) !== 1
                    || ! is_string($destination) || filter_var($destination, FILTER_VALIDATE_EMAIL) === false
                    || $assignment->attendee_public_id !== $attendee->public_id
                    || ! $this->matchesEmailHmac($assignment->delivery_email_hmac_sha256, $destination)) {
                    throw new ResourceConflictException(__('GVSU registration bridge respondent assignment conflict.'));
                }
                $records[] = [
                    'assignment_id' => $assignment->assignment_id,
                    'respondent_id' => $assignment->respondent_id,
                    'attendee_id' => (string) $attendee->id,
                    'public_ticket_id' => $attendee->public_id,
                    // The attendee context and signer identity are independently source-confirmed.
                    'attendee_display_name' => $assignment->attendee_display_name,
                    'respondent_display_name' => $assignment->respondent_display_name,
                    'respondent_route' => $assignment->respondent_route,
                    'guardian_relationship_reference' => $assignment->guardian_relationship_reference,
                    'respondent_identity_digest_sha256' => $assignment->respondent_identity_digest_sha256,
                    'replaces_assignment_id' => $assignment->replaced_assignment_id,
                    'replace_existing_link' => $assignment->link_replacement_requested_at !== null && $assignment->link_replacement_delivered_at === null,
                    'designated_delivery_email' => $this->normalizedEmail($destination),
                ];
            }

            $batchId = $this->stableId('grb', [
                (string) $order->event_id,
                (string) $order->id,
                implode(',', array_column($records, 'assignment_id')),
            ]);
            $body = [
                'operation' => 'gvsu-registration-provision-v1',
                'event_id' => (string) $order->event_id,
                'order_id' => (string) $order->id,
                'provision_batch_id' => $batchId,
                'assignments' => $records,
            ];
            $payloadDigest = hash('sha256', $this->canonicalJson($body));
            $allDelivered = true;
            foreach ($assignments as $assignment) {
                if ($assignment->status !== 'delivered') {
                    $allDelivered = false;
                }
                $assignment->fill([
                    'provision_batch_id' => $batchId,
                    'payload_digest_sha256' => $payloadDigest,
                ]);
                $assignment->save();
            }
            if ($allDelivered) {
                return ['terminal' => true];
            }
            GvsuRegistrationAssignment::query()->where('provision_batch_id', $batchId)->where('status', '!=', 'delivered')->update([
                'status' => 'attempted',
                'attempted_at' => now(),
                'updated_at' => now(),
            ]);

            return $body + ['payload_digest_sha256' => $payloadDigest];
        });

        if ($batch === null) {
            return false;
        }
        if (($batch['terminal'] ?? false) === true) {
            return true;
        }

        try {
            $this->portal->provision($batch);
        } catch (GvsuRegistrationBridgeUnknownException $exception) {
            GvsuRegistrationAssignment::query()->where('provision_batch_id', $batch['provision_batch_id'])->update([
                'status' => 'unknown',
                'unknown_at' => now(),
                'updated_at' => now(),
            ]);
            throw $exception;
        }
        $deliveredAt = now();
        GvsuRegistrationAssignment::query()->where('provision_batch_id', $batch['provision_batch_id'])->update([
            'status' => 'delivered',
            'delivered_at' => $deliveredAt,
            'unknown_at' => null,
            'updated_at' => $deliveredAt,
        ]);
        GvsuRegistrationAssignment::query()->where('provision_batch_id', $batch['provision_batch_id'])
            ->whereNotNull('link_replacement_requested_at')
            ->whereNull('link_replacement_delivered_at')
            ->update(['link_replacement_delivered_at' => $deliveredAt, 'updated_at' => $deliveredAt]);

        return true;
    }

    /** Reconcile exactly one already-allowlisted paid canary order; this never scans orders. */
    public function reconcileCanaryOrder(int $orderId): void
    {
        if (GvsuRegistrationBridgeConfig::mode() !== 'canary'
            || ! GvsuRegistrationBridgeConfig::allowsCohort(GvsuRegistrationBridgeConfig::EVENT_ID, $orderId)) {
            throw new ResourceConflictException(__('GVSU registration bridge canary order is not allowlisted.'));
        }
        if (! $this->provisionCompletedOrder($orderId)) {
            throw new ResourceConflictException(__('GVSU registration bridge is awaiting an explicit respondent assignment.'));
        }
    }

    public function assertCheckInClearance(int $eventId, string $attendeePublicId): void
    {
        if ($eventId !== GvsuRegistrationBridgeConfig::EVENT_ID) {
            return;
        }
        if (! GvsuRegistrationBridgeConfig::enabled()) {
            throw new CannotCheckInException(__('Registration verification is unavailable.'));
        }

        $attendee = Attendee::withTrashed()->where('event_id', $eventId)->where('public_id', $attendeePublicId)->first();
        if ($attendee === null || $attendee->deleted_at !== null || $attendee->status !== 'ACTIVE') {
            throw new CannotCheckInException(__('Registration verification is unavailable.'));
        }
        $order = Order::withTrashed()->find($attendee->order_id);
        $assignment = GvsuRegistrationAssignment::query()->where('event_id', $eventId)->where('attendee_id', $attendee->id)->first();
        if ($order === null || $assignment === null || ! $this->isCurrentPaidOrder($order)
            || $assignment->status !== 'delivered' || $assignment->attendee_public_id !== $attendee->public_id) {
            throw new CannotCheckInException(__('Registration verification is unavailable.'));
        }
        if (! $this->portal->clearance([
            'operation' => 'gvsu-registration-clearance-v1',
            'event_id' => (string) $eventId,
            'order_id' => (string) $order->id,
            'attendee_id' => (string) $attendee->id,
            'public_ticket_id' => $attendee->public_id,
            'respondent_id' => $assignment->respondent_id,
            'assignment_id' => $assignment->assignment_id,
        ])) {
            throw new CannotCheckInException(__('Registration verification is unavailable.'));
        }
    }

    public function currentState(array $candidate): array
    {
        $required = ['event_id', 'order_id', 'attendee_id', 'public_ticket_id', 'respondent_id', 'assignment_id', 'attendee_display_name', 'respondent_identity_digest_sha256', 'designated_delivery_email'];
        foreach ($required as $field) {
            if (! isset($candidate[$field]) || ! is_string($candidate[$field]) || $candidate[$field] === '') {
                return $this->state('blocked', []);
            }
        }
        if ($candidate['event_id'] !== (string) GvsuRegistrationBridgeConfig::EVENT_ID) {
            return $this->state('blocked', []);
        }
        $assignment = GvsuRegistrationAssignment::query()->where('assignment_id', $candidate['assignment_id'])->first();
        $order = Order::withTrashed()->find($candidate['order_id']);
        $attendee = Attendee::withTrashed()->find($candidate['attendee_id']);
        if ($assignment === null || $order === null || $attendee === null
            || $assignment->status !== 'delivered' || ! $this->isCurrentPaidOrder($order) || $attendee->deleted_at !== null || $attendee->status !== 'ACTIVE'
            || (string) $order->event_id !== $candidate['event_id'] || (string) $attendee->event_id !== $candidate['event_id']
            || (string) $attendee->order_id !== $candidate['order_id'] || $attendee->public_id !== $candidate['public_ticket_id']
            || (string) $assignment->event_id !== $candidate['event_id'] || (string) $assignment->order_id !== $candidate['order_id']
            || (string) $assignment->attendee_id !== $candidate['attendee_id'] || $assignment->attendee_public_id !== $candidate['public_ticket_id']
            || $assignment->attendee_display_name !== $candidate['attendee_display_name']
            || $assignment->respondent_id !== $candidate['respondent_id'] || ! hash_equals($assignment->respondent_identity_digest_sha256, $candidate['respondent_identity_digest_sha256'])
            || ! $this->matchesEmailHmac($assignment->delivery_email_hmac_sha256, $candidate['designated_delivery_email'])) {
            return $this->state('blocked', []);
        }

        return $this->state('current', [
            'event_id' => $candidate['event_id'],
            'order_id' => $candidate['order_id'],
            'attendee_id' => $candidate['attendee_id'],
            'public_ticket_id' => $candidate['public_ticket_id'],
            'respondent_id' => $candidate['respondent_id'],
            'assignment_id' => $candidate['assignment_id'],
            'respondent_identity_digest_sha256' => $candidate['respondent_identity_digest_sha256'],
            'payment' => $order->payment_status,
            'order_status' => $order->status,
            'attendee_status' => $attendee->status,
        ]);
    }

    private function isCurrentPaidOrder(Order $order): bool
    {
        return $order->deleted_at === null
            && $order->status === 'COMPLETED'
            && $order->payment_status === 'PAYMENT_RECEIVED'
            && $order->refund_status === null
            && (float) $order->total_refunded === 0.0;
    }

    private function normalizedAttendeeDisplayName(string $value): string
    {
        return $this->normalizedDisplayName($value, __('GVSU registration attendee display name is invalid.'));
    }

    private function normalizedRespondentDisplayName(string $value): string
    {
        return $this->normalizedDisplayName($value, __('GVSU registration respondent display name is invalid.'));
    }

    private function normalizedDisplayName(string $value, string $error): string
    {
        $name = trim($value);
        if ($name === '' || strlen($name) > 200 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new ResourceConflictException($error);
        }

        return $name;
    }

    private function normalizedRoute(string $value): string
    {
        if (! in_array($value, ['adult', 'guardian'], true)) {
            throw new ResourceConflictException(__('GVSU registration respondent route is invalid.'));
        }

        return $value;
    }

    private function normalizedGuardianRelationshipReference(?string $value, string $route): ?string
    {
        $reference = $value === null ? null : trim($value);
        if ($route === 'adult' && $reference !== null && $reference !== '') {
            throw new ResourceConflictException(__('GVSU registration adult respondents cannot carry a guardian relationship reference.'));
        }
        if ($reference === '') {
            $reference = null;
        }
        if ($reference !== null && (strlen($reference) > 80 || preg_match('/\A[A-Za-z0-9:._-]+\z/', $reference) !== 1)) {
            throw new ResourceConflictException(__('GVSU registration guardian relationship reference is invalid.'));
        }

        return $reference;
    }

    private function normalizedEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function emailHmac(string $email): string
    {
        return hash_hmac('sha256', $this->normalizedEmail($email), GvsuRegistrationBridgeConfig::emailHmacCurrentKey());
    }

    private function matchesEmailHmac(string $stored, string $email): bool
    {
        $current = hash_equals($stored, $this->emailHmac($email));
        $priorKey = GvsuRegistrationBridgeConfig::emailHmacPriorKey();
        $prior = $priorKey !== null && hash_equals($stored, hash_hmac('sha256', $this->normalizedEmail($email), $priorKey));

        return $current || $prior;
    }

    private function identityDigest(string $attendeeName, string $name, string $route, ?string $relationship, string $destination): string
    {
        return hash_hmac('sha256', $this->canonicalJson([
            'attendee_display_name' => $attendeeName,
            'respondent_display_name' => $name,
            'respondent_route' => $route,
            'guardian_relationship_reference' => $relationship,
            'designated_delivery_email' => $this->normalizedEmail($destination),
        ]), GvsuRegistrationBridgeConfig::emailHmacCurrentKey());
    }

    private function matchesIdentityDigest(string $stored, string $attendeeName, string $name, string $route, ?string $relationship, string $destination): bool
    {
        if (hash_equals($stored, $this->identityDigest($attendeeName, $name, $route, $relationship, $destination))) {
            return true;
        }
        $priorKey = GvsuRegistrationBridgeConfig::emailHmacPriorKey();
        if ($priorKey === null) {
            return false;
        }

        return hash_equals($stored, hash_hmac('sha256', $this->canonicalJson([
            'attendee_display_name' => $attendeeName,
            'respondent_display_name' => $name,
            'respondent_route' => $route,
            'guardian_relationship_reference' => $relationship,
            'designated_delivery_email' => $this->normalizedEmail($destination),
        ]), $priorKey));
    }

    private function stableId(string $prefix, array $parts): string
    {
        return $prefix.'_'.substr(hash('sha256', implode("\0", $parts)), 0, 48);
    }

    private function state(string $status, array $snapshot): array
    {
        return [
            'status' => $status,
            'observed_at' => now()->utc()->toIso8601String(),
            'respondent_identity_digest_sha256' => $snapshot['respondent_identity_digest_sha256'] ?? null,
            'snapshot_digest_sha256' => hash('sha256', $this->canonicalJson($snapshot)),
        ];
    }

    private function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (is_array($item)) {
                if (array_is_list($item)) {
                    return array_map($normalize, $item);
                }
                ksort($item, SORT_STRING);
                foreach ($item as $key => $child) {
                    $item[$key] = $normalize($child);
                }
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

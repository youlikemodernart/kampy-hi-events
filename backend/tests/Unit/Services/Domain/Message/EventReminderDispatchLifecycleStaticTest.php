<?php

namespace Tests\Unit\Services\Domain\Message;

use Tests\TestCase;

class EventReminderDispatchLifecycleStaticTest extends TestCase
{
    public function test_audience_completion_precedes_dispatching_and_aggregate_requires_expected_rows(): void
    {
        $source = file_get_contents(app_path('Services/Domain/Message/EventReminderDispatchService.php'));

        self::assertStringContainsString("'audience_claimed_at' => now()", $source);
        self::assertStringContainsString("'status' => EventReminderOccurrenceStatus::DISPATCHING->value", $source);
        self::assertStringContainsString('count($statuses) !== $occurrence->expected_recipient_count', $source);
        self::assertStringContainsString('EventReminderOccurrenceStatus::DISPATCHING->value, EventReminderOccurrenceStatus::UNKNOWN->value', $source);
        self::assertStringContainsString("'no_valid_recipients'", $source);
        self::assertStringContainsString("'payload_changed_during_audience_claim'", $source);
    }

    public function test_cancellation_fails_closed_after_provider_handoff_or_uncertainty(): void
    {
        $source = file_get_contents(app_path('Services/Domain/Message/EventReminderCancellationService.php'));

        self::assertStringContainsString('lockForUpdate()', $source);
        self::assertStringContainsString('provider_handoff_or_unknown', $source);
        self::assertStringContainsString('OutgoingMessageStatus::SUBMITTING', $source);
        self::assertStringContainsString('OutgoingMessageStatus::SENT', $source);
        self::assertStringContainsString('OutgoingMessageStatus::UNKNOWN', $source);
    }

    public function test_final_handoff_rechecks_current_bindings_and_duplicate_address_eligibility(): void
    {
        $source = file_get_contents(app_path('Services/Domain/Message/EventReminderDispatchService.php'));

        self::assertStringContainsString('markSuppressedBeforeHandoff', $source);
        self::assertStringContainsString('$occurrence->status !== EventReminderOccurrenceStatus::DISPATCHING->value', $source);
        self::assertStringContainsString('$occurrence->audience_claimed_at === null', $source);
        self::assertStringContainsString("where('event_id', \$claim->event_id)->where('status', AttendeeStatus::ACTIVE->name)", $source);
        self::assertStringContainsString('checkTierLimits', $source);
        self::assertStringContainsString('digestMatches', $source);
    }

    public function test_reconciliation_only_resumes_claims_for_completed_audiences(): void
    {
        $source = file_get_contents(app_path('Services/Domain/Message/EventReminderReconciliationService.php'));

        self::assertStringContainsString("->where('status', EventReminderOccurrenceStatus::DISPATCHING->value)", $source);
        self::assertStringContainsString("->whereNotNull('audience_claimed_at')", $source);
        self::assertStringContainsString("->whereIn('message_id', \$dispatchableMessageIds)", $source);
    }
}

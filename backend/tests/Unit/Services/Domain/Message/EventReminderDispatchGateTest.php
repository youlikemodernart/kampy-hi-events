<?php

namespace Tests\Unit\Services\Domain\Message;

use Carbon\CarbonImmutable;
use HiEvents\Services\Domain\Message\EventReminderDispatchGate;
use Tests\TestCase;

class EventReminderDispatchGateTest extends TestCase
{
    public function test_default_configuration_fails_closed(): void
    {
        config()->set('event-reminders.enabled', false);
        $this->assertFalse(app(EventReminderDispatchGate::class)->mayClaimRecipients());
    }

    public function test_missing_activation_binding_fails_closed(): void
    {
        config()->set('event-reminders.enabled', true);
        config()->set('event-reminders.reply_to', null);
        config()->set('event-reminders.physical_address', 'Kamp Love');
        config()->set('event-reminders.preference_url', 'https://example.test/preferences');

        $this->assertFalse(app(EventReminderDispatchGate::class)->mayClaimRecipients());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lateGraceBoundaryCases')]
    public function test_six_hour_late_grace_uses_the_reviewed_inclusive_boundary(int $lateMinutes, bool $expected): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
        config()->set('event-reminders.late_grace_minutes', 360);

        try {
            $now = CarbonImmutable::now('UTC');
            $dueAt = $now->subMinutes($lateMinutes);

            $this->assertSame(
                $expected,
                app(EventReminderDispatchGate::class)->isWithinLateGrace($dueAt, $now),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public static function lateGraceBoundaryCases(): array
    {
        return [
            'five-hours-fifty-nine-minutes' => [359, true],
            'exactly-six-hours' => [360, true],
            'six-hours-one-minute' => [361, false],
        ];
    }
}

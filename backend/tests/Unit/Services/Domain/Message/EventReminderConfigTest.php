<?php

namespace Tests\Unit\Services\Domain\Message;

use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventReminderConfigTest extends TestCase
{
    private const KEYS = [
        'KAMP_EVENT_REMINDERS_ENABLED',
        'KAMP_EVENT_REMINDERS_EVENT_ALLOWLIST',
        'KAMP_EVENT_REMINDERS_REPLY_TO',
        'KAMP_EVENT_REMINDERS_PHYSICAL_ADDRESS',
        'KAMP_EVENT_REMINDERS_PREFERENCE_URL',
    ];

    public function test_defaults_select_no_events_and_disable_dispatch(): void
    {
        $policy = $this->policy([]);
        $this->assertFalse($policy['enabled']);
        $this->assertSame([], $policy['event_allowlist']);
        $this->assertNull($policy['reply_to']);
        $this->assertNull($policy['physical_address']);
        $this->assertNull($policy['preference_url']);
        $this->assertSame('tickets@kamplove.org', $policy['sender']);
        $this->assertSame(['7-days' => -10080, '24-hours' => -1440], $policy['offsets']);
        $this->assertSame(360, $policy['late_grace_minutes']);
    }

    public function test_explicit_runtime_activation_and_disable_use_the_same_policy(): void
    {
        $env = [
            'KAMP_EVENT_REMINDERS_ENABLED' => 'true',
            'KAMP_EVENT_REMINDERS_EVENT_ALLOWLIST' => '[900001]',
            'KAMP_EVENT_REMINDERS_REPLY_TO' => 'tickets@example.test',
        ];
        $enabled = $this->policy($env);
        $this->assertTrue($enabled['enabled']);
        $this->assertSame([900001], $enabled['event_allowlist']);
        $this->assertNotContains(7, $enabled['event_allowlist']);
        $this->assertSame('tickets@example.test', $enabled['reply_to']);
        $this->assertNull($enabled['physical_address']);
        $this->assertNull($enabled['preference_url']);
        $env['KAMP_EVENT_REMINDERS_ENABLED'] = 'false';
        $disabled = $this->policy($env);
        $this->assertFalse($disabled['enabled']);
        unset($enabled['enabled'], $disabled['enabled']);
        $this->assertSame($enabled, $disabled);
    }

    #[DataProvider('invalidAllowlists')]
    public function test_invalid_allowlist_fails_closed(string $input): void
    {
        $this->assertSame([], $this->policy(['KAMP_EVENT_REMINDERS_EVENT_ALLOWLIST' => $input])['event_allowlist']);
    }

    public static function invalidAllowlists(): array
    {
        return array_map(static fn (string $input): array => [$input], [
            '', '7', '7,8', 'null', '{}', '{"event":7}', '["7"]', '[0]', '[-7]', '[7.1]', '[true]', '[7,"oops"]', '[7,',
        ]);
    }

    public function test_positive_integer_ids_are_deduplicated_without_widening_scope(): void
    {
        $this->assertSame([900001, 900002], $this->policy([
            'KAMP_EVENT_REMINDERS_EVENT_ALLOWLIST' => '[900001,900002,900001]',
        ])['event_allowlist']);
    }

    #[DataProvider('nonBooleanEnableValues')]
    public function test_activation_requires_literal_true(string $input): void
    {
        $this->assertFalse($this->policy(['KAMP_EVENT_REMINDERS_ENABLED' => $input])['enabled']);
    }

    public static function nonBooleanEnableValues(): array
    {
        return [['false'], ['1'], ['yes'], ['enabled'], ['']];
    }

    private function policy(array $values): array
    {
        $repository = Env::getRepository();
        $before = [];
        foreach (self::KEYS as $key) {
            $before[$key] = $repository->get($key);
            $repository->clear($key);
            if (array_key_exists($key, $values)) {
                $repository->set($key, $values[$key]);
            }
        }
        try {
            return require config_path('event-reminders.php');
        } finally {
            foreach ($before as $key => $value) {
                $repository->clear($key);
                if ($value !== null) {
                    $repository->set($key, $value);
                }
            }
        }
    }
}

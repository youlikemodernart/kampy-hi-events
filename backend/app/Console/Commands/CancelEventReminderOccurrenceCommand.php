<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Message\EventReminderCancellationService;
use Illuminate\Console\Command;

class CancelEventReminderOccurrenceCommand extends Command
{
    protected $signature = 'event-reminder:cancel-occurrence
        {occurrenceId : Exact reminder occurrence ID}
        {--reason=operator_cancelled : Auditable cancellation reason}
        {--apply : Cancel only when this flag is present}';

    protected $description = 'Cancel unsent reminder work; this never recalls a provider handoff.';

    public function handle(EventReminderCancellationService $cancellations): int
    {
        $occurrenceId = filter_var($this->argument('occurrenceId'), FILTER_VALIDATE_INT);
        if ($occurrenceId === false || $occurrenceId < 1 || !$this->option('apply')) {
            $this->error('An exact occurrence ID and --apply are required. This command cannot recall provider handoff.');
            return self::INVALID;
        }
        $result = $cancellations->cancelUnsent($occurrenceId, (string) $this->option('reason'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        return $result['cancelled'] ? self::SUCCESS : self::FAILURE;
    }
}

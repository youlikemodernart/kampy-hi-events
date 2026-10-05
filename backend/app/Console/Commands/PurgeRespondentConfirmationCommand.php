<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Domain\Registration\RespondentConfirmationRetention;
use Illuminate\Console\Command;

class PurgeRespondentConfirmationCommand extends Command
{
    protected $signature = 'respondent-confirmation:purge {--batch=500}';

    protected $description = 'Purge expired verification material and dead rate buckets; never waiver evidence';

    public function handle(RespondentConfirmationRetention $retention): int
    {
        $this->line(json_encode($retention->purge((int) $this->option('batch')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

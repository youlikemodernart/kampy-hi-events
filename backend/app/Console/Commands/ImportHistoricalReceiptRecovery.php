<?php

namespace HiEvents\Console\Commands;

use HiEvents\Repository\Eloquent\HistoricalReceiptRecoveryRepository;
use Illuminate\Console\Command;
use Throwable;

/** Offline evidence input only. This command never reads Stripe or Postmark. */
final class ImportHistoricalReceiptRecovery extends Command
{
    protected $signature = 'registration:import-historical-receipts {bundle : Private approved JSON bundle} {--commit : Separately approved encrypted evidence import}';

    protected $description = 'Validate an exact frozen receipt cohort; dry-run by default, never enroll or send';

    public function handle(HistoricalReceiptRecoveryRepository $repository): int
    {
        try {
            $path = $this->argument('bundle');
            if (! is_string($path) || ! is_file($path) || is_link($path) || filesize($path) > 50331648) {
                return self::FAILURE;
            }
            $input = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $commit = $this->option('commit') === true;
            // Offline dry-run has no external effects. Commit performs exact Portal absence reads before native locks.
            $result = $repository->import($input['manifest'], $input['rows'], $commit ? fn (int $orderId) => $repository->preflight($orderId) : fn () => true, $commit);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Historical receipt admission rejected. No evidence details are logged.');

            return self::FAILURE;
        }
    }
}

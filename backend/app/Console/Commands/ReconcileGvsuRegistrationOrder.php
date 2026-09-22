<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService;
use Illuminate\Console\Command;

/** Local operator seam: reconcile one exact paid, allowlisted canary order without scanning. */
class ReconcileGvsuRegistrationOrder extends Command
{
    protected $signature = 'gvsu-registration:reconcile-order {orderId : Exact internal order ID already allowlisted for the canary}';

    protected $description = 'Reconcile exactly one allowlisted GVSU registration canary order.';

    public function handle(GvsuRegistrationBridgeService $bridge): int
    {
        $orderId = filter_var($this->argument('orderId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            $this->error('A positive exact order ID is required.');

            return self::INVALID;
        }

        try {
            $bridge->reconcileCanaryOrder($orderId);
        } catch (ResourceConflictException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('GVSU registration canary order reconciled.');

        return self::SUCCESS;
    }
}

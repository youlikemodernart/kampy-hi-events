<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Order;

use HiEvents\Services\Domain\Order\OrderEffectRelayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOrderEffectOutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(OrderEffectRelayService $relay): void
    {
        $batchSize = min(100, max(1, (int) config('services.order_effect_outbox.batch_size', 25)));
        $relay->processBatch($batchSize);
    }
}

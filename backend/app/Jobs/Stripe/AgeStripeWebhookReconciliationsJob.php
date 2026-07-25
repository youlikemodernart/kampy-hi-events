<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Stripe;

use HiEvents\Repository\Interfaces\StripeWebhookReconciliationRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AgeStripeWebhookReconciliationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(StripeWebhookReconciliationRepositoryInterface $repository): void
    {
        $graceHours = max(1, (int) config('services.stripe.webhook_reconciliation_grace_hours', 72));
        $batchSize = min(1000, max(1, (int) config('services.stripe.webhook_reconciliation_batch_size', 100)));

        $repository->agePendingBefore(now()->subHours($graceHours), $batchSize);
    }
}

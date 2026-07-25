<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Stripe;

use Carbon\Carbon;
use HiEvents\Jobs\Stripe\AgeStripeWebhookReconciliationsJob;
use HiEvents\Repository\Interfaces\StripeWebhookReconciliationRepositoryInterface;
use Mockery;
use Tests\TestCase;

class AgeStripeWebhookReconciliationsJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ages_a_bounded_batch_after_the_configured_retry_window(): void
    {
        Carbon::setTestNow('2026-07-25 12:00:00');
        config()->set('services.stripe.webhook_reconciliation_grace_hours', 72);
        config()->set('services.stripe.webhook_reconciliation_batch_size', 25);
        $repository = Mockery::mock(StripeWebhookReconciliationRepositoryInterface::class);
        $repository->shouldReceive('agePendingBefore')
            ->once()
            ->withArgs(static fn ($cutoff, int $limit): bool => $cutoff->equalTo(now()->subHours(72))
                && $limit === 25)
            ->andReturn(3);

        (new AgeStripeWebhookReconciliationsJob)->handle($repository);
    }

    public function test_clamps_invalid_configuration_to_safe_bounds(): void
    {
        config()->set('services.stripe.webhook_reconciliation_grace_hours', 0);
        config()->set('services.stripe.webhook_reconciliation_batch_size', 5000);
        $repository = Mockery::mock(StripeWebhookReconciliationRepositoryInterface::class);
        $repository->shouldReceive('agePendingBefore')
            ->once()
            ->withArgs(static fn ($cutoff, int $limit): bool => $limit === 1000)
            ->andReturn(0);

        (new AgeStripeWebhookReconciliationsJob)->handle($repository);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners\Event;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Jobs\Event\UpdateEventStatisticsJob;
use HiEvents\Listeners\Event\UpdateEventStatsListener;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class UpdateEventStatsListenerTest extends TestCase
{
    public function test_outboxed_completion_suppresses_only_statistics_dispatch(): void
    {
        Bus::fake();
        $order = (new OrderDomainObject)->setId(10)->setStatus(OrderStatus::COMPLETED->name);

        (new UpdateEventStatsListener)->handle(new OrderStatusChangedEvent(
            order: $order,
            sendEmails: false,
            createInvoice: true,
            updateStatistics: false,
        ));

        Bus::assertNotDispatched(UpdateEventStatisticsJob::class);
    }

    public function test_legacy_completed_event_still_dispatches_statistics(): void
    {
        Bus::fake();
        $order = (new OrderDomainObject)->setId(10)->setStatus(OrderStatus::COMPLETED->name);

        (new UpdateEventStatsListener)->handle(new OrderStatusChangedEvent($order));

        Bus::assertDispatched(UpdateEventStatisticsJob::class);
    }
}

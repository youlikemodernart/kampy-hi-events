<?php

namespace HiEvents\Jobs\Message;

use HiEvents\Services\Domain\Message\EventReminderDispatchService;
use HiEvents\Services\Domain\Message\EventReminderReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileEventRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        EventReminderReconciliationService $reconciliation,
        EventReminderDispatchService $dispatch,
    ): void {
        $reconciliation->reconcile();
        $dispatch->dispatchDue();
    }
}

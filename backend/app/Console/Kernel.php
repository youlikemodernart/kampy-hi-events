<?php

namespace HiEvents\Console;

use HiEvents\Jobs\Message\SendScheduledMessagesJob;
use HiEvents\Jobs\Order\ProcessOrderEffectOutboxJob;
use HiEvents\Jobs\Stripe\AgeStripeWebhookReconciliationsJob;
use HiEvents\Jobs\Waitlist\ProcessExpiredWaitlistOffersJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new SendScheduledMessagesJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessExpiredWaitlistOffersJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new AgeStripeWebhookReconciliationsJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessOrderEffectOutboxJob)->everyMinute()->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        include base_path('routes/console.php');
    }
}

<?php

namespace HiEvents\Jobs\Message;

use HiEvents\Services\Domain\Message\EventReminderDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEventReminderRecipientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $outgoingMessageId)
    {
    }

    public function handle(EventReminderDispatchService $dispatch): void
    {
        $dispatch->sendRecipient($this->outgoingMessageId);
    }
}

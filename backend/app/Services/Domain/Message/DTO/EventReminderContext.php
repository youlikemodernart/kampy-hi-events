<?php

namespace HiEvents\Services\Domain\Message\DTO;

use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;

final readonly class EventReminderContext
{
    public function __construct(
        public string $eventTitle,
        public string $eventUrl,
        public string $localStart,
        public string $timezone,
        public ?string $location,
        public string $supportEmail,
        public string $sender,
        public string $replyTo,
        public string $physicalAddress,
        public string $preferenceUrl,
        public string $preheader,
        public UniversityEmailThemeDTO $theme,
    ) {
    }

    public function payload(): array
    {
        return [
            'event_title' => $this->eventTitle,
            'event_url' => $this->eventUrl,
            'local_start' => $this->localStart,
            'timezone' => $this->timezone,
            'location' => $this->location,
            'support_email' => $this->supportEmail,
            'sender' => $this->sender,
            'reply_to' => $this->replyTo,
            'physical_address' => $this->physicalAddress,
            'preference_url' => $this->preferenceUrl,
            'preheader' => $this->preheader,
        ];
    }
}

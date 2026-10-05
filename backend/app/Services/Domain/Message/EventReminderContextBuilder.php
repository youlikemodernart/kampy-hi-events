<?php

namespace HiEvents\Services\Domain\Message;

use Carbon\CarbonImmutable;
use HiEvents\Models\Event;
use HiEvents\Services\Domain\Email\UniversityThemeResolver;
use HiEvents\Services\Domain\Message\DTO\EventReminderContext;
use Illuminate\Support\Str;

class EventReminderContextBuilder
{
    public function __construct(private readonly UniversityThemeResolver $themes) {}

    public function build(Event $event): ?EventReminderContext
    {
        $policy = config('event-reminders');
        $settings = $event->event_settings;
        $required = [
            $event->title,
            $event->start_date,
            $event->timezone,
            $settings?->support_email,
            $policy['sender'] ?? null,
            $policy['reply_to'] ?? null,
        ];
        foreach ($required as $value) {
            if (! is_string($value) && ! $value instanceof \DateTimeInterface || blank($value)) {
                return null;
            }
        }

        try {
            $start = CarbonImmutable::instance($event->start_date)->setTimezone($event->timezone);
        } catch (\Throwable) {
            return null;
        }
        $eventUrl = rtrim((string) config('app.frontend_url'), '/').'/event/'.$event->id.'/'.Str::slug($event->title);
        if (! filter_var($eventUrl, FILTER_VALIDATE_URL)) {
            return null;
        }
        $theme = $this->themes->resolveForSlug(Str::slug($event->title)) ?? $this->themes->resolveFallback();
        if ($theme === null) {
            return null;
        }

        return new EventReminderContext(
            $event->title,
            $eventUrl,
            $start->isoFormat('MMMM D, YYYY h:mm A'),
            $event->timezone,
            $event->location ?: null,
            $settings->support_email,
            $policy['sender'],
            $policy['reply_to'],
            $policy['physical_address'] ?? null,
            $policy['preference_url'] ?? null,
            $event->title.' — '.$start->isoFormat('MMMM D, YYYY h:mm A').' '.$event->timezone,
            $theme,
        );
    }
}

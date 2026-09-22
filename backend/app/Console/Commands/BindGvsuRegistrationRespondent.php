<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService;
use Illuminate\Console\Command;

class BindGvsuRegistrationRespondent extends Command
{
    protected $signature = 'gvsu-registration:bind-respondent
        {orderId : Exact internal event-seven order ID}
        {attendeeId : Exact attendee ID belonging to that order}
        {--attendee-display-name= : Confirmed attendee display name for the token holder}
        {--respondent-name= : Confirmed respondent display name}
        {--route= : adult or guardian}
        {--destination= : Confirmed registration delivery destination}
        {--guardian-relationship-reference= : Optional opaque guardian relationship reference}';

    protected $description = 'Bind or correct one exact GVSU registration respondent without inferring attendee identity fields.';

    public function handle(GvsuRegistrationBridgeService $bridge): int
    {
        $orderId = $this->positiveInteger($this->argument('orderId'));
        $attendeeId = $this->positiveInteger($this->argument('attendeeId'));
        $attendeeName = $this->option('attendee-display-name');
        $name = $this->option('respondent-name');
        $route = $this->option('route');
        $destination = $this->option('destination');
        $relationship = $this->option('guardian-relationship-reference');
        if ($orderId === null || $attendeeId === null || ! is_string($attendeeName) || ! is_string($name) || ! is_string($route) || ! is_string($destination)
            || ($relationship !== null && ! is_string($relationship))) {
            $this->error('Exact IDs, confirmed attendee and respondent names, route, and destination are required.');

            return self::INVALID;
        }

        try {
            $result = $bridge->bindRespondent($orderId, $attendeeId, $attendeeName, $name, $route, $destination, $relationship);
        } catch (ResourceConflictException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // Intentionally emits no attendee or respondent name, email, guardian reference, or opaque link.
        $this->info('GVSU registration assignment '.$result['status'].'.');

        return self::SUCCESS;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $result === false ? null : $result;
    }
}

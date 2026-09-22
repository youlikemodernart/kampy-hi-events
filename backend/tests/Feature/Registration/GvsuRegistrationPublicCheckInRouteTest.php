<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use HiEvents\DataTransferObjects\ErrorBagDTO;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Services\Application\Handlers\CheckInList\Public\CreateAttendeeCheckInPublicHandler;
use HiEvents\Services\Domain\CheckInList\DTO\CreateAttendeeCheckInsResponseDTO;
use Mockery;
use Tests\TestCase;

class GvsuRegistrationPublicCheckInRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('a', 32));
    }

    /** @dataProvider deniedClearanceStates */
    public function test_public_checkin_endpoint_returns_conflict_when_shared_clearance_denies(string $state): void
    {
        $handler = Mockery::mock(CreateAttendeeCheckInPublicHandler::class);
        $handler->shouldReceive('handle')->once()->andThrow(new CannotCheckInException('Registration verification is unavailable.'));
        $this->app->instance(CreateAttendeeCheckInPublicHandler::class, $handler);

        $this->postJson('/public/check-in-lists/cil_exact/check-ins', [
            'attendees' => [['public_id' => 'ticket_exact', 'action' => 'check-in']],
        ])->assertConflict()->assertJsonPath('message', 'Registration verification is unavailable.');
    }

    public static function deniedClearanceStates(): array
    {
        return [
            'incomplete' => ['incomplete'],
            'revoked' => ['revoked'],
            'refunded' => ['refunded'],
            'inactive' => ['inactive'],
            'source unavailable' => ['source-unavailable'],
        ];
    }

    public function test_public_checkin_endpoint_accepts_the_shared_clearance_success_result(): void
    {
        $handler = Mockery::mock(CreateAttendeeCheckInPublicHandler::class);
        $handler->shouldReceive('handle')->once()->andReturn(new CreateAttendeeCheckInsResponseDTO(
            attendeeCheckIns: collect(),
            errors: new ErrorBagDTO,
        ));
        $this->app->instance(CreateAttendeeCheckInPublicHandler::class, $handler);

        $this->postJson('/public/check-in-lists/cil_exact/check-ins', [
            'attendees' => [['public_id' => 'ticket_exact', 'action' => 'check-in']],
        ])->assertOk()->assertJsonPath('data', []);
    }
}

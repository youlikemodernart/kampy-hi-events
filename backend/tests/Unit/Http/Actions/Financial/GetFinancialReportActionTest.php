<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Actions\Financial;

use DateTimeImmutable;
use HiEvents\DomainObjects\Interfaces\DomainObjectInterface;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\FinancialReadModelValidationException;
use HiEvents\Exceptions\FinancialReportConfigurationException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Http\Actions\Financial\GetFinancialReportAction;
use HiEvents\Http\Request\Financial\GetFinancialReportRequest;
use HiEvents\Services\Application\Handlers\Financial\DTO\GetFinancialReportDTO;
use HiEvents\Services\Application\Handlers\Financial\GetFinancialReportHandler;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReadModelDTO;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class GetFinancialReportActionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private GetFinancialReportHandler $handler;

    private UserDomainObject $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = Mockery::mock(GetFinancialReportHandler::class);
        $this->user = new UserDomainObject;
    }

    public function test_container_resolves_action(): void
    {
        $this->assertInstanceOf(
            GetFinancialReportAction::class,
            $this->app->make(GetFinancialReportAction::class),
        );
    }

    public function test_action_authorizes_then_returns_allowlisted_no_store_response(): void
    {
        $action = new TestGetFinancialReportAction($this->handler, $this->user, 7);
        $request = new TestGetFinancialReportRequest([
            'university_id' => 'gcu',
            'cycle_id' => '2026-fall',
            'cutoff_at' => '2026-07-25T23:59:59-07:00',
            'include_reconciliation' => false,
        ]);

        $this->handler->shouldReceive('handle')
            ->once()
            ->withArgs(function (
                GetFinancialReportDTO $data,
                UserDomainObject $user,
                int $accountId,
            ): bool {
                return $data->eventId === 31
                    && $data->universityId === 'gcu'
                    && $data->cycleId === '2026-fall'
                    && $data->cutoffAt->format(DATE_ATOM) === '2026-07-25T23:59:59-07:00'
                    && $data->generatedAt->getTimezone()->getName() === 'UTC'
                    && $data->includeReconciliation === false
                    && $user === $this->user
                    && $accountId === 7;
            })
            ->andReturn($this->report());

        $response = $action($request, 31);
        $payload = $response->getData(true)['data'];

        $this->assertSame([31], $action->authorizedEventIds);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('reconciliation', $payload);
    }

    public function test_action_authorization_denial_stops_before_handler(): void
    {
        $action = new TestGetFinancialReportAction($this->handler, $this->user, 7);
        $action->authorizationException = new UnauthorizedException('denied');
        $this->handler->shouldNotReceive('handle');

        $this->expectException(UnauthorizedException::class);
        $action(new TestGetFinancialReportRequest($this->validInput()), 31);
    }

    public function test_resource_not_found_maps_to_sanitized_no_store_404(): void
    {
        $action = new TestGetFinancialReportAction($this->handler, $this->user, 7);
        $this->handler->shouldReceive('handle')
            ->once()
            ->andThrow(new ResourceNotFoundException('internal scope detail'));

        $response = $action(new TestGetFinancialReportRequest($this->validInput()), 31);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Financial report is unavailable.', $response->getData(true)['message']);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('scope', json_encode($response->getData(true)));
    }

    public function test_read_model_error_is_sanitized_as_service_unavailable(): void
    {
        $action = new TestGetFinancialReportAction($this->handler, $this->user, 7);
        $this->handler->shouldReceive('handle')
            ->once()
            ->andThrow(new FinancialReadModelValidationException('internal snapshot detail'));

        $response = $action(new TestGetFinancialReportRequest($this->validInput()), 31);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Financial report data is unavailable.', $response->getData(true)['message']);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('snapshot', json_encode($response->getData(true)));
    }

    public function test_malformed_server_configuration_is_sanitized_as_no_store_service_unavailable(): void
    {
        $action = new TestGetFinancialReportAction($this->handler, $this->user, 7);
        $this->handler->shouldReceive('handle')
            ->once()
            ->andThrow(new FinancialReportConfigurationException('malformed binding JSON'));

        $response = $action(new TestGetFinancialReportRequest($this->validInput()), 31);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('binding', json_encode($response->getData(true)));
    }

    public function test_request_contract_excludes_caller_source_namespaces_and_rejects_non_exact_inputs(): void
    {
        $request = new GetFinancialReportRequest;
        $validator = Validator::make([
            'university_id' => 'gcu',
            'cycle_id' => '2026 fall',
            'cutoff_at' => '2026-07-25',
            'include_reconciliation' => 'yes',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('cycle_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('cutoff_at', $validator->errors()->toArray());
        $this->assertArrayHasKey('include_reconciliation', $validator->errors()->toArray());
        $this->assertArrayNotHasKey('source_namespace', $request->rules());
        $this->assertArrayNotHasKey('plan_source_namespace', $request->rules());
        $this->assertArrayNotHasKey('ticket_source_namespace', $request->rules());
        $this->assertArrayNotHasKey('settlement_source_namespace', $request->rules());
        $this->assertArrayNotHasKey('donation_source_namespace', $request->rules());
    }

    private function validInput(): array
    {
        return [
            'university_id' => 'gcu',
            'cycle_id' => '2026-fall',
            'cutoff_at' => '2026-07-25T23:59:59-07:00',
            'include_reconciliation' => false,
        ];
    }

    private function report(): FinancialReadModelDTO
    {
        return new FinancialReadModelDTO(
            scope: [
                'scopeKey' => str_repeat('a', 64),
                'accountId' => 7,
                'organizerId' => 19,
                'eventId' => 31,
                'universityId' => 'gcu',
                'cycleId' => '2026-fall',
            ],
            cutoffAt: new DateTimeImmutable('2026-07-25T23:59:59-07:00'),
            generatedAt: new DateTimeImmutable('2026-07-26T00:05:00-07:00'),
            reportingTimezone: 'America/Phoenix',
            financialPolicy: [],
            plan: [],
            tickets: ['settlement' => []],
            donations: [],
            variances: [],
            currentPosition: [],
            sourceEvidence: [
                'plan' => [],
                'tickets' => [],
                'settlement' => [],
                'donations' => [],
            ],
            publishable: false,
            reconciliation: null,
        );
    }
}

class TestGetFinancialReportAction extends GetFinancialReportAction
{
    public array $authorizedEventIds = [];

    public ?UnauthorizedException $authorizationException = null;

    public function __construct(
        GetFinancialReportHandler $handler,
        private readonly UserDomainObject $user,
        private readonly int $accountId,
    ) {
        parent::__construct($handler);
    }

    protected function isActionAuthorized(
        int $entityId,
        string $entityType,
        \HiEvents\DomainObjects\Enums\Role $minimumRole = \HiEvents\DomainObjects\Enums\Role::ORGANIZER,
    ): void {
        $this->authorizedEventIds[] = $entityId;
        if ($this->authorizationException !== null) {
            throw $this->authorizationException;
        }
    }

    protected function getAuthenticatedUser(): UserDomainObject|DomainObjectInterface
    {
        return $this->user;
    }

    protected function getAuthenticatedAccountId(): int
    {
        return $this->accountId;
    }
}

class TestGetFinancialReportRequest extends GetFinancialReportRequest
{
    public function __construct(private readonly array $values)
    {
        parent::__construct();
    }

    public function validated($key = null, $default = null)
    {
        if ($key === null) {
            return $this->values;
        }

        return $this->values[$key] ?? $default;
    }
}

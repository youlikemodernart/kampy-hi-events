<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Financial;

use DateTimeImmutable;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\FinancialPersistenceRepositoryInterface;
use HiEvents\Services\Application\Handlers\Financial\DTO\GetFinancialReportDTO;
use HiEvents\Services\Application\Handlers\Financial\GetFinancialReportHandler;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReadModelDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotQueryDTO;
use HiEvents\Services\Domain\Financial\FinancialPersistencePlanner;
use HiEvents\Services\Domain\Financial\FinancialReadModelService;
use HiEvents\Services\Infrastructure\Authorization\FinanceReportAuthorizationService;
use HiEvents\Services\Infrastructure\Financial\FinancialReportSourceBindingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class GetFinancialReportHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private EventRepositoryInterface $eventRepository;

    private FinancialPersistenceRepositoryInterface $financialRepository;

    private TestFinanceReportAuthorizationService $authorizationService;

    private FinancialReadModelService $readModelService;

    private FinancialPersistencePlanner $persistencePlanner;

    private GetFinancialReportHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->financialRepository = Mockery::mock(FinancialPersistenceRepositoryInterface::class);
        config(['services.kamp_financial_reports.bindings_json' => json_encode([[
            'event_id' => 31,
            'university_id' => 'gcu',
            'cycle_id' => '2026-fall',
            'plan_source_namespace' => 'gcu_budget_2026',
            'ticket_source_namespace' => 'spark_gcu_2026',
            'settlement_source_namespace' => 'stripe_gcu_2026',
            'donation_source_namespace' => 'donorbox_gcu_2026',
        ]], JSON_THROW_ON_ERROR)]);

        $this->authorizationService = new TestFinanceReportAuthorizationService;
        $this->readModelService = Mockery::mock(FinancialReadModelService::class);
        $this->persistencePlanner = new FinancialPersistencePlanner;
        $this->handler = new GetFinancialReportHandler(
            $this->eventRepository,
            $this->financialRepository,
            $this->authorizationService,
            $this->readModelService,
            new FinancialReportSourceBindingService,
            $this->persistencePlanner,
        );
    }

    public function test_container_resolves_handler(): void
    {
        $this->assertInstanceOf(
            GetFinancialReportHandler::class,
            $this->app->make(GetFinancialReportHandler::class),
        );
    }

    public function test_authorized_read_uses_exact_queries_and_preserves_evidence_selections(): void
    {
        $data = $this->request(includeReconciliation: true);
        $user = Mockery::mock(UserDomainObject::class);
        $event = Mockery::mock(EventDomainObject::class);
        $plan = Mockery::mock(FinancialPersistedSnapshotDTO::class);
        $ticket = Mockery::mock(FinancialPersistedSnapshotDTO::class);
        $settlement = Mockery::mock(FinancialPersistedSnapshotDTO::class);
        $donation = Mockery::mock(FinancialPersistedSnapshotDTO::class);
        $expected = $this->response();

        $this->eventRepository->shouldReceive('findById')
            ->once()
            ->with(31)
            ->ordered()
            ->andReturn($event);
        $event->shouldReceive('getAccountId')->once()->ordered()->andReturn(7);
        $event->shouldReceive('getOrganizerId')->once()->ordered()->andReturn(19);

        $this->expectRead(
            'getLatestPromotable',
            FinancialSnapshotKind::PLANNED_POSITION,
            'gcu_budget_2026',
            $plan,
        );
        $this->expectRead(
            'getLatestPromotable',
            FinancialSnapshotKind::SPARK_TICKET,
            'spark_gcu_2026',
            $ticket,
        );
        $this->expectRead(
            'getLatestSourceControlled',
            FinancialSnapshotKind::STRIPE_SETTLEMENT,
            'stripe_gcu_2026',
            $settlement,
        );
        $this->expectRead(
            'getLatestSourceControlled',
            FinancialSnapshotKind::DONORBOX,
            'donorbox_gcu_2026',
            $donation,
        );

        $this->readModelService->shouldReceive('compose')
            ->once()
            ->withArgs(function (...$arguments) use ($plan, $ticket, $settlement, $donation): bool {
                return $arguments[0] === $this->scopeKey()
                    && $arguments[1] === 7
                    && $arguments[2] === 19
                    && $arguments[3] === 31
                    && $arguments[4] === 'gcu'
                    && $arguments[5] === '2026-fall'
                    && $arguments[6] === $plan
                    && $arguments[7] === $ticket
                    && $arguments[8] === $settlement
                    && $arguments[9] === $donation
                    && $arguments[12] === true;
            })
            ->ordered()
            ->andReturn($expected);

        $this->assertSame($expected, $this->handler->handle($data, $user, 7));
        $this->assertSame([
            'event' => $event,
            'user' => $user,
            'accountId' => 7,
            'surface' => 'view',
            'includeReconciliation' => true,
        ], $this->authorizationService->state->calls[0]);
    }

    public function test_authorization_denial_stops_before_any_financial_query(): void
    {
        $data = $this->request(includeReconciliation: true);
        $user = Mockery::mock(UserDomainObject::class);
        $event = Mockery::mock(EventDomainObject::class);

        $this->eventRepository->shouldReceive('findById')->once()->with(31)->andReturn($event);
        $this->authorizationService->state->exception = new UnauthorizedException('denied');
        $this->financialRepository->shouldNotReceive('getLatestPromotable');
        $this->financialRepository->shouldNotReceive('getLatestSourceControlled');
        $this->readModelService->shouldNotReceive('compose');
        $event->shouldNotReceive('getAccountId');
        $event->shouldNotReceive('getOrganizerId');

        $this->expectException(UnauthorizedException::class);
        $this->handler->handle($data, $user, 7);
    }

    public function test_export_uses_fixed_export_surface_before_financial_queries(): void
    {
        $data = $this->request();
        $user = Mockery::mock(UserDomainObject::class);
        $event = Mockery::mock(EventDomainObject::class);

        $this->eventRepository->shouldReceive('findById')->once()->with(31)->andReturn($event);
        $event->shouldReceive('getAccountId')->once()->andReturn(7);
        $event->shouldReceive('getOrganizerId')->once()->andReturn(19);
        $this->expectRead(
            'getLatestPromotable',
            FinancialSnapshotKind::PLANNED_POSITION,
            'gcu_budget_2026',
            null,
        );
        $this->financialRepository->shouldNotReceive('getLatestSourceControlled');
        $this->readModelService->shouldNotReceive('compose');

        try {
            $this->handler->export($data, $user, 7);
            $this->fail('Expected the missing plan to stop the export.');
        } catch (ResourceNotFoundException) {
            $this->assertSame('export', $this->authorizationService->state->calls[0]['surface']);
            $this->assertFalse($this->authorizationService->state->calls[0]['includeReconciliation']);
        }
    }

    public function test_export_rejects_reconciliation_before_event_or_financial_queries(): void
    {
        $data = $this->request(includeReconciliation: true);
        $user = Mockery::mock(UserDomainObject::class);

        $this->eventRepository->shouldNotReceive('findById');
        $this->financialRepository->shouldNotReceive('getLatestPromotable');
        $this->financialRepository->shouldNotReceive('getLatestSourceControlled');
        $this->readModelService->shouldNotReceive('compose');

        $this->expectException(UnauthorizedException::class);
        $this->handler->export($data, $user, 7);
    }

    public function test_missing_event_becomes_project_not_found_before_authorization(): void
    {
        $data = $this->request();
        $user = Mockery::mock(UserDomainObject::class);

        $this->eventRepository->shouldReceive('findById')
            ->once()
            ->with(31)
            ->andThrow(new ModelNotFoundException);
        $this->financialRepository->shouldNotReceive('getLatestPromotable');
        $this->financialRepository->shouldNotReceive('getLatestSourceControlled');
        $this->readModelService->shouldNotReceive('compose');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle($data, $user, 7);
    }

    public function test_event_account_mismatch_stops_after_authorization_and_before_financial_query(): void
    {
        $data = $this->request();
        $user = Mockery::mock(UserDomainObject::class);
        $event = Mockery::mock(EventDomainObject::class);

        $this->eventRepository->shouldReceive('findById')->once()->andReturn($event);
        $event->shouldReceive('getAccountId')->once()->andReturn(8);
        $event->shouldNotReceive('getOrganizerId');
        $this->financialRepository->shouldNotReceive('getLatestPromotable');
        $this->financialRepository->shouldNotReceive('getLatestSourceControlled');
        $this->readModelService->shouldNotReceive('compose');

        $this->expectException(UnauthorizedException::class);
        $this->handler->handle($data, $user, 7);
    }

    public function test_missing_promotable_plan_stops_before_other_source_reads(): void
    {
        $data = $this->request();
        $user = Mockery::mock(UserDomainObject::class);
        $event = Mockery::mock(EventDomainObject::class);

        $this->eventRepository->shouldReceive('findById')->once()->andReturn($event);
        $event->shouldReceive('getAccountId')->once()->andReturn(7);
        $event->shouldReceive('getOrganizerId')->once()->andReturn(19);
        $this->expectRead(
            'getLatestPromotable',
            FinancialSnapshotKind::PLANNED_POSITION,
            'gcu_budget_2026',
            null,
        );
        $this->financialRepository->shouldNotReceive('getLatestSourceControlled');
        $this->readModelService->shouldNotReceive('compose');

        $this->expectException(ResourceNotFoundException::class);
        $this->handler->handle($data, $user, 7);
    }

    private function expectRead(
        string $method,
        FinancialSnapshotKind $kind,
        string $sourceNamespace,
        ?FinancialPersistedSnapshotDTO $result,
    ): void {
        $this->financialRepository->shouldReceive($method)
            ->once()
            ->with(Mockery::on(function (FinancialSnapshotQueryDTO $query) use (
                $kind,
                $sourceNamespace,
            ): bool {
                $this->assertNotEmpty($this->authorizationService->state->calls);

                return $query->scopeKey === $this->scopeKey()
                    && $query->accountId === 7
                    && $query->organizerId === 19
                    && $query->eventId === 31
                    && $query->universityId === 'gcu'
                    && $query->cycleId === '2026-fall'
                    && $query->snapshotKind === $kind
                    && $query->sourceNamespace === $sourceNamespace;
            }))
            ->ordered()
            ->andReturn($result);
    }

    private function request(bool $includeReconciliation = false): GetFinancialReportDTO
    {
        return new GetFinancialReportDTO(
            eventId: 31,
            universityId: 'gcu',
            cycleId: '2026-fall',
            cutoffAt: new DateTimeImmutable('2026-07-25T23:59:59-07:00'),
            generatedAt: new DateTimeImmutable('2026-07-26T00:05:00-07:00'),
            includeReconciliation: $includeReconciliation,
        );
    }

    public function test_missing_server_owned_source_binding_stops_before_financial_query(): void
    {
        config(['services.kamp_financial_reports.bindings_json' => '[]']);
        $data = $this->request();
        $user = Mockery::mock(UserDomainObject::class);
        $event = Mockery::mock(EventDomainObject::class);

        $this->eventRepository->shouldReceive('findById')->once()->andReturn($event);
        $event->shouldReceive('getAccountId')->once()->andReturn(7);
        $event->shouldReceive('getOrganizerId')->once()->andReturn(19);
        $this->financialRepository->shouldNotReceive('getLatestPromotable');
        $this->financialRepository->shouldNotReceive('getLatestSourceControlled');
        $this->readModelService->shouldNotReceive('compose');

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('Financial report scope is unavailable');
        $this->handler->handle($data, $user, 7);
    }

    private function scopeKey(): string
    {
        return $this->persistencePlanner->scopeKey(7, 19, 31, 'gcu', '2026-fall');
    }

    private function response(): FinancialReadModelDTO
    {
        return new FinancialReadModelDTO(
            scope: [
                'scopeKey' => $this->scopeKey(),
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
            tickets: [],
            donations: [],
            variances: [],
            currentPosition: [],
            sourceEvidence: [],
            publishable: false,
            reconciliation: null,
        );
    }
}

readonly class TestFinanceReportAuthorizationService extends FinanceReportAuthorizationService
{
    public object $state;

    public function __construct()
    {
        $this->state = (object) [
            'calls' => [],
            'exception' => null,
        ];
    }

    public function authorize(
        EventDomainObject $event,
        UserDomainObject $authenticatedUser,
        int $currentAccountId,
        string $requestedSurface,
        bool $includeReconciliation,
    ): void {
        $this->state->calls[] = [
            'event' => $event,
            'user' => $authenticatedUser,
            'accountId' => $currentAccountId,
            'surface' => $requestedSurface,
            'includeReconciliation' => $includeReconciliation,
        ];
        if ($this->state->exception !== null) {
            throw $this->state->exception;
        }
    }
}

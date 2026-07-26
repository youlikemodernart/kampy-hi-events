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
use HiEvents\Http\Actions\Financial\ExportFinancialReportAction;
use HiEvents\Http\Request\Financial\ExportFinancialReportRequest;
use HiEvents\Services\Application\Handlers\Financial\DTO\GetFinancialReportDTO;
use HiEvents\Services\Application\Handlers\Financial\FinancialReportCsvService;
use HiEvents\Services\Application\Handlers\Financial\GetFinancialReportHandler;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReadModelDTO;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ExportFinancialReportActionTest extends TestCase
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
            ExportFinancialReportAction::class,
            $this->app->make(ExportFinancialReportAction::class),
        );
    }

    public function test_action_authorizes_then_returns_fixed_no_store_csv_without_reconciliation(): void
    {
        $action = $this->action();
        $request = new TestExportFinancialReportRequest($this->validInput());

        $this->handler->shouldReceive('export')
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
        $this->handler->shouldNotReceive('handle');

        $response = $action($request, 31);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame([31], $action->authorizedEventIds);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression(
            '/attachment; filename="financial-report-event-31-\d{8}_\d{6}\.csv"/',
            (string) $response->headers->get('Content-Disposition'),
        );

        $content = (string) $response->getContent();
        $lines = array_values(array_filter(explode("\n", trim($content)), 'strlen'));

        $this->assertCount(51, $lines);
        $this->assertSame(['section', 'metric', 'value', 'status'], str_getcsv($lines[0], ',', '"', ''));
        $this->assertStringContainsString('tickets,recognized_revenue_cents,WITHHELD,withheld', $content);
        $this->assertStringNotContainsString('reconciliation', $content);
        $this->assertStringNotContainsString('scope_key', $content);
    }

    public function test_action_authorization_denial_stops_before_handler(): void
    {
        $action = $this->action();
        $action->authorizationException = new UnauthorizedException('denied');
        $this->handler->shouldNotReceive('export');

        $this->expectException(UnauthorizedException::class);
        $action(new TestExportFinancialReportRequest($this->validInput()), 31);
    }

    public function test_missing_event_during_action_authorization_maps_to_sanitized_no_store_404(): void
    {
        $action = $this->action();
        $action->authorizationException = new ModelNotFoundException;
        $this->handler->shouldNotReceive('export');

        $response = $action(new TestExportFinancialReportRequest($this->validInput()), 31);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Financial report is unavailable.', $response->getData(true)['message']);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_missing_report_maps_to_sanitized_no_store_404(): void
    {
        $action = $this->action();
        $this->handler->shouldReceive('export')
            ->once()
            ->andThrow(new ResourceNotFoundException('internal scope detail'));

        $response = $action(new TestExportFinancialReportRequest($this->validInput()), 31);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Financial report is unavailable.', $response->getData(true)['message']);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringNotContainsString('scope', json_encode($response->getData(true)));
    }

    /** @dataProvider unavailableExceptionProvider */
    public function test_unavailable_report_errors_map_to_sanitized_no_store_503(\Throwable $exception): void
    {
        $action = $this->action();
        $this->handler->shouldReceive('export')->once()->andThrow($exception);

        $response = $action(new TestExportFinancialReportRequest($this->validInput()), 31);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Financial report data is unavailable.', $response->getData(true)['message']);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('internal', json_encode($response->getData(true)));
    }

    public static function unavailableExceptionProvider(): array
    {
        return [
            'read model' => [new FinancialReadModelValidationException('internal snapshot detail')],
            'configuration' => [new FinancialReportConfigurationException('internal binding detail')],
        ];
    }

    public function test_request_rejects_reconciliation_and_caller_owned_scope_or_source_fields(): void
    {
        $request = new ExportFinancialReportRequest;
        $validator = Validator::make([
            ...$this->validInput(),
            'include_reconciliation' => true,
            'scope_key' => str_repeat('a', 64),
            'organizer_id' => 19,
            'plan_source_namespace' => 'untrusted',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('include_reconciliation', $validator->errors()->toArray());
        $this->assertArrayHasKey('scope_key', $validator->errors()->toArray());
        $this->assertArrayHasKey('organizer_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('plan_source_namespace', $validator->errors()->toArray());
    }

    private function action(): TestExportFinancialReportAction
    {
        return new TestExportFinancialReportAction(
            $this->handler,
            new FinancialReportCsvService,
            $this->user,
            7,
        );
    }

    private function validInput(): array
    {
        return [
            'university_id' => 'gcu',
            'cycle_id' => '2026-fall',
            'cutoff_at' => '2026-07-25T23:59:59-07:00',
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
            generatedAt: new DateTimeImmutable('2026-07-26T00:05:00+00:00'),
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

class TestExportFinancialReportAction extends ExportFinancialReportAction
{
    public array $authorizedEventIds = [];

    public ?\Throwable $authorizationException = null;

    public function __construct(
        GetFinancialReportHandler $handler,
        FinancialReportCsvService $csvService,
        private readonly UserDomainObject $user,
        private readonly int $accountId,
    ) {
        parent::__construct($handler, $csvService);
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

class TestExportFinancialReportRequest extends ExportFinancialReportRequest
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

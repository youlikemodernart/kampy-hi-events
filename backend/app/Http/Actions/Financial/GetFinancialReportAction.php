<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Financial;

use DateTimeImmutable;
use DateTimeZone;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\FinancialReadModelValidationException;
use HiEvents\Exceptions\FinancialReportConfigurationException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Financial\GetFinancialReportRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Financial\FinancialReportResource;
use HiEvents\Services\Application\Handlers\Financial\DTO\FinancialReportResponseDTO;
use HiEvents\Services\Application\Handlers\Financial\DTO\GetFinancialReportDTO;
use HiEvents\Services\Application\Handlers\Financial\GetFinancialReportHandler;
use Illuminate\Http\JsonResponse;

class GetFinancialReportAction extends BaseAction
{
    public function __construct(
        private readonly GetFinancialReportHandler $handler,
    ) {}

    public function __invoke(GetFinancialReportRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $authenticatedUser = $this->getAuthenticatedUser();
        if (! $authenticatedUser instanceof UserDomainObject) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        try {
            $report = $this->handler->handle(
                data: new GetFinancialReportDTO(
                    eventId: $eventId,
                    universityId: $request->validated('university_id'),
                    cycleId: $request->validated('cycle_id'),
                    cutoffAt: new DateTimeImmutable($request->validated('cutoff_at')),
                    generatedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                    includeReconciliation: (bool) $request->validated(
                        'include_reconciliation',
                        false,
                    ),
                ),
                authenticatedUser: $authenticatedUser,
                currentAccountId: $this->getAuthenticatedAccountId(),
            );
        } catch (ResourceNotFoundException) {
            return $this->withNoStore($this->errorResponse(
                __('Financial report is unavailable.'),
                ResponseCodes::HTTP_NOT_FOUND,
            ));
        } catch (FinancialReadModelValidationException|FinancialReportConfigurationException $exception) {
            report($exception);

            return $this->withNoStore($this->errorResponse(
                __('Financial report data is unavailable.'),
                ResponseCodes::HTTP_SERVICE_UNAVAILABLE,
            ));
        }

        return $this->withNoStore($this->resourceResponse(
            FinancialReportResource::class,
            FinancialReportResponseDTO::fromReadModel($report),
        ));
    }

    private function withNoStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}

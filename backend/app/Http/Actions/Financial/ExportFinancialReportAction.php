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
use HiEvents\Http\Request\Financial\ExportFinancialReportRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Financial\DTO\FinancialReportResponseDTO;
use HiEvents\Services\Application\Handlers\Financial\DTO\GetFinancialReportDTO;
use HiEvents\Services\Application\Handlers\Financial\FinancialReportCsvService;
use HiEvents\Services\Application\Handlers\Financial\GetFinancialReportHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ExportFinancialReportAction extends BaseAction
{
    public function __construct(
        private readonly GetFinancialReportHandler $handler,
        private readonly FinancialReportCsvService $csvService,
    ) {}

    public function __invoke(
        ExportFinancialReportRequest $request,
        int $eventId,
    ): Response|JsonResponse {
        try {
            $this->isActionAuthorized($eventId, EventDomainObject::class);

            $authenticatedUser = $this->getAuthenticatedUser();
            if (! $authenticatedUser instanceof UserDomainObject) {
                throw new UnauthorizedException(__('You are not authorized to perform this action.'));
            }

            $report = $this->handler->export(
                data: new GetFinancialReportDTO(
                    eventId: $eventId,
                    universityId: $request->validated('university_id'),
                    cycleId: $request->validated('cycle_id'),
                    cutoffAt: new DateTimeImmutable($request->validated('cutoff_at')),
                    generatedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
                    includeReconciliation: false,
                ),
                authenticatedUser: $authenticatedUser,
                currentAccountId: $this->getAuthenticatedAccountId(),
            );
            $response = FinancialReportResponseDTO::fromReadModel($report);
            $csvContent = $this->renderCsv(
                $this->csvService->headers(),
                $this->csvService->rows($response),
            );
        } catch (ModelNotFoundException|ResourceNotFoundException) {
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

        $filename = sprintf(
            'financial-report-event-%d-%s.csv',
            $eventId,
            $report->generatedAt->format('Ymd_His'),
        );

        return new Response(
            $csvContent,
            ResponseCodes::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * @param  list<int|string>  $headers
     * @param  list<list<int|string>>  $rows
     */
    private function renderCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new FinancialReadModelValidationException(
                'Financial CSV output stream is unavailable.',
            );
        }

        try {
            if (fputcsv($handle, $headers, ',', '"', '') === false) {
                throw new FinancialReadModelValidationException(
                    'Financial CSV headers could not be written.',
                );
            }
            foreach ($rows as $row) {
                if (fputcsv($handle, $row, ',', '"', '') === false) {
                    throw new FinancialReadModelValidationException(
                        'A financial CSV row could not be written.',
                    );
                }
            }
            if (! rewind($handle)) {
                throw new FinancialReadModelValidationException(
                    'Financial CSV output could not be read.',
                );
            }
            $content = stream_get_contents($handle);
            if ($content === false) {
                throw new FinancialReadModelValidationException(
                    'Financial CSV output could not be read.',
                );
            }

            return $content;
        } finally {
            fclose($handle);
        }
    }

    private function withNoStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Financial;

use HiEvents\Exceptions\FinancialReportConfigurationException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Infrastructure\Financial\DTO\FinancialReportSourceBindingDTO;
use JsonException;

class FinancialReportSourceBindingService
{
    private const REQUIRED_KEYS = [
        'event_id',
        'university_id',
        'cycle_id',
        'plan_source_namespace',
        'ticket_source_namespace',
        'settlement_source_namespace',
        'donation_source_namespace',
    ];

    private const SAFE_IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/';

    public function resolve(
        int $eventId,
        string $universityId,
        string $cycleId,
    ): FinancialReportSourceBindingDTO {
        $bindingsJson = config('services.kamp_financial_reports.bindings_json');
        if (! is_string($bindingsJson)) {
            throw new FinancialReportConfigurationException(
                'Financial report source bindings must be JSON.',
            );
        }
        try {
            $bindings = json_decode($bindingsJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FinancialReportConfigurationException(
                'Financial report source bindings contain invalid JSON.',
                previous: $exception,
            );
        }
        if (! is_array($bindings) || ! array_is_list($bindings)) {
            throw new FinancialReportConfigurationException(
                'Financial report source bindings must be a JSON list.',
            );
        }

        $match = null;
        $seenScopes = [];
        foreach ($bindings as $index => $binding) {
            $validated = $this->validatedBinding($binding, $index);
            $scopeIdentity = json_encode([
                $validated['event_id'],
                $validated['university_id'],
                $validated['cycle_id'],
            ], JSON_THROW_ON_ERROR);
            if (isset($seenScopes[$scopeIdentity])) {
                throw new FinancialReportConfigurationException(
                    'Financial report source bindings contain a duplicate exact scope.',
                );
            }
            $seenScopes[$scopeIdentity] = true;

            if ($validated['event_id'] === $eventId
                && hash_equals($validated['university_id'], trim($universityId))
                && hash_equals($validated['cycle_id'], trim($cycleId))) {
                $match = $validated;
            }
        }

        if ($match === null) {
            throw new ResourceNotFoundException(__('Financial report scope is unavailable'));
        }

        return new FinancialReportSourceBindingDTO(
            planSourceNamespace: $match['plan_source_namespace'],
            ticketSourceNamespace: $match['ticket_source_namespace'],
            settlementSourceNamespace: $match['settlement_source_namespace'],
            donationSourceNamespace: $match['donation_source_namespace'],
        );
    }

    /**
     * @return array{
     *     event_id: int,
     *     university_id: string,
     *     cycle_id: string,
     *     plan_source_namespace: string,
     *     ticket_source_namespace: string,
     *     settlement_source_namespace: string,
     *     donation_source_namespace: string
     * }
     */
    private function validatedBinding(mixed $binding, int|string $index): array
    {
        if ($binding instanceof \stdClass) {
            $binding = get_object_vars($binding);
        }
        if (! is_array($binding)) {
            throw new FinancialReportConfigurationException(
                "Financial report source binding {$index} has an invalid shape.",
            );
        }
        $keys = array_keys($binding);
        $requiredKeys = self::REQUIRED_KEYS;
        sort($keys);
        sort($requiredKeys);
        if ($keys !== $requiredKeys) {
            throw new FinancialReportConfigurationException(
                "Financial report source binding {$index} has an invalid shape.",
            );
        }
        if (! is_int($binding['event_id']) || $binding['event_id'] < 1) {
            throw new FinancialReportConfigurationException(
                "Financial report source binding {$index} has an invalid event ID.",
            );
        }

        foreach (array_slice(self::REQUIRED_KEYS, 1) as $key) {
            if (! is_string($binding[$key])
                || ! preg_match(self::SAFE_IDENTIFIER_PATTERN, $binding[$key])) {
                throw new FinancialReportConfigurationException(
                    "Financial report source binding {$index} has an invalid {$key}.",
                );
            }
            $binding[$key] = trim($binding[$key]);
        }

        return $binding;
    }
}

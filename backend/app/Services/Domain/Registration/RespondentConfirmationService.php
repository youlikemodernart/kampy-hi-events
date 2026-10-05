<?php

namespace HiEvents\Services\Domain\Registration;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Mail\RespondentConfirmationChallenge;
use HiEvents\Repository\Eloquent\RespondentConfirmationRepository;
use HiEvents\Services\Domain\Order\OrderEffectOutboxService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Support\Facades\Mail;

final class RespondentConfirmationService
{
    public function __construct(
        private readonly RespondentConfirmationRepository $repository,
        private readonly GvsuRegistrationBridgeService $bridge,
        private readonly OrderEffectOutboxService $outbox,
        private readonly HtmlPurifierService $purifier,
    ) {}

    public function request(string $shortId): void
    {
        $delivery = $this->repository->issue($shortId);
        if ($delivery !== null) {
            // Synchronous single attempt: never serialize a plaintext challenge into a queue or retry an uncertain send.
            Mail::to($delivery->email)->send(new RespondentConfirmationChallenge($delivery->token));
        }
    }

    public function confirm(string $shortId, string $token, array $input): bool
    {
        if (! preg_match('/\A[0-9a-f]{64}\z/', $token) || count($input) < 1 || count($input) > 250) {
            return false;
        }
        $respondents = [];
        foreach ($input as $row) {
            if (is_array($row) && ($row['respondent_name'] ?? null) === null) {
                $row['respondent_name'] = '';
            }
            if (! is_array($row) || ! is_int($row['attendee_id'] ?? null) || $row['attendee_id'] < 1
                || ! in_array($row['route'] ?? null, ['adult', 'guardian'], true)
                || ! is_string($row['email'] ?? null) || strlen($row['email']) > 320 || ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)
                || ! is_string($row['respondent_name'] ?? null) || strlen($row['respondent_name']) > 200
                || isset($respondents[$row['attendee_id']])) {
                return false;
            }
            $name = trim(html_entity_decode(strip_tags($this->purifier->purify($row['respondent_name']) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($row['route'] === 'guardian' && $name === '') {
                return false;
            }
            $respondents[$row['attendee_id']] = ['attendee_id' => $row['attendee_id'], 'route' => $row['route'], 'respondent_name' => $row['route'] === 'adult' ? '' : $name, 'email' => strtolower(trim($row['email']))];
        }
        ksort($respondents, SORT_NUMERIC);

        return $this->repository->confirm($shortId, $token, array_values($respondents), function ($orderId, $attendees, $rows): void {
            foreach ($attendees as $index => $attendee) {
                $row = $rows[$index];
                $attendeeName = trim($attendee->first_name.' '.$attendee->last_name);
                if ($attendeeName === '') {
                    throw new ResourceConflictException(__('Attendee context is unavailable.'));
                }
                $this->bridge->bindRespondent($orderId, (int) $attendee->id, $attendeeName,
                    $row['route'] === 'adult' ? $attendeeName : $row['respondent_name'], $row['route'], $row['email'], null, true);
            }
            $this->outbox->enqueueRespondentConfirmation($orderId);
        });
    }
}

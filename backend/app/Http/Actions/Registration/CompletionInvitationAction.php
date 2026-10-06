<?php

namespace HiEvents\Http\Actions\Registration;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\RespondentConfirmationRepository;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class CompletionInvitationAction extends BaseAction
{
    public function __invoke(Request $request, string $orderShortId)
    {
        if (config('respondent-confirmation.enabled') !== true || config('respondent-confirmation.invitation_enabled') !== true) {
            return $this->jsonResponse([], 404);
        }
        $headers = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"];
        if ($request->isMethod('GET')) {
            return response()->view('registration.completion-invitation')->withHeaders($headers);
        }
        if (! RespondentConfirmationRequestGate::allows($request)) {
            return $this->jsonResponse([], 404)->withHeaders($headers);
        }
        $cookieName = 'kamp_invitation_'.hash('sha256', $orderShortId);
        $cookiePath = '/api/registration/invitation/'.rawurlencode($orderShortId);
        try {
            $token = $request->input('token');
            if (! is_string($token)) {
                $credential = json_decode(Crypt::decryptString((string) $request->cookie($cookieName)), true, 4, JSON_THROW_ON_ERROR);
                $token = $credential['order'] === $orderShortId && is_int($credential['expires'] ?? null) && $credential['expires'] > now()->timestamp ? $credential['token'] : '';
            }
            $repository = app(RespondentConfirmationRepository::class);
            $context = $repository->invitationContext($orderShortId, $token);
            if ($context === null) {
                return $this->jsonResponse(['status' => 'unavailable'], 409)->withHeaders($headers);
            }
            if ($request->input('action') === 'confirm') {
                if ($request->input('acknowledged') !== true || ! is_array($request->input('respondents'))
                    || ! app(RespondentConfirmationService::class)->confirm($orderShortId, $token, $request->input('respondents'))) {
                    return $this->jsonResponse(['status' => 'unavailable'], 409)->withHeaders($headers);
                }
                $context = $repository->invitationContext($orderShortId, $token);
            }
            $result = ['status' => $context['confirmed'] ? 'confirmed' : 'choosing', 'siblings' => $context['siblings']];
            // Provision only after a deliberate confirmation. Reload/resume POST retries the same durable batch.
            if ($context['confirmed']) {
                try {
                    app(GvsuRegistrationBridgeService::class)->provisionCompletedOrder($context['order_id']);
                    $result['links'] = app(GvsuRegistrationBridgePortalClient::class)->invitationHandoff($context['order_id'], $context['assignment_ids']);
                } catch (Throwable) {
                    $result['pending'] = true;
                }
            }
            $cookie = cookie($cookieName, Crypt::encryptString(json_encode(['order' => $orderShortId, 'token' => $token, 'expires' => now()->addMinutes(config('respondent-confirmation.invitation_session_minutes'))->timestamp], JSON_THROW_ON_ERROR)), config('respondent-confirmation.invitation_session_minutes'), $cookiePath, null, true, true, false, 'Strict');

            return $this->jsonResponse($result)->withHeaders($headers)->withCookie($cookie);
        } catch (Throwable) {
            // Capability/request/peer errors must never enter logs or exception telemetry.
            return $this->jsonResponse(['status' => 'unavailable'], 409)->withHeaders($headers);
        }
    }
}

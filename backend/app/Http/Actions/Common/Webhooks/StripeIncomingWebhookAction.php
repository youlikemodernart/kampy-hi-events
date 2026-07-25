<?php

namespace HiEvents\Http\Actions\Common\Webhooks;

use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Exceptions\StripeWebhookEventClaimBusyException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\DTO\StripeWebhookDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\IncomingWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class StripeIncomingWebhookAction extends BaseAction
{
    public function __construct(
        private readonly IncomingWebhookHandler $handler,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $this->handler->handle(new StripeWebhookDTO(
                headerSignature: $request->server('HTTP_STRIPE_SIGNATURE'),
                payload: $request->getContent(),
            ));
        } catch (StripeWebhookEventClaimBusyException) {
            return $this->noContentResponse(ResponseCodes::HTTP_CONFLICT);
        } catch (StripeLocalPaymentNotFoundException) {
            return $this->noContentResponse(ResponseCodes::HTTP_SERVICE_UNAVAILABLE);
        } catch (Throwable $exception) {
            logger()?->error('Failed to process incoming Stripe webhook', [
                'exception_class' => $exception::class,
            ]);

            return $this->noContentResponse(ResponseCodes::HTTP_BAD_REQUEST);
        }

        return $this->noContentResponse();
    }
}

<?php

namespace HiEvents\Services\Domain\Payment\Stripe;

use HiEvents\DomainObjects\StripeCustomerDomainObject;
use HiEvents\Exceptions\Stripe\CreatePaymentIntentFailedException;
use HiEvents\Exceptions\Stripe\KampStripeMetadataConfigurationException;
use HiEvents\Repository\Interfaces\StripeCustomerRepositoryInterface;
use HiEvents\Services\Domain\Order\DTO\ApplicationFeeValuesDTO;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreatePaymentIntentRequestDTO;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreatePaymentIntentResponseDTO;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

class StripePaymentIntentCreationService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Repository $config,
        private readonly StripeCustomerRepositoryInterface $stripeCustomerRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly OrderApplicationFeeCalculationService $orderApplicationFeeCalculationService,
        private readonly KampStripeMetadataService $kampStripeMetadataService,
    ) {}

    /**
     * @throws CreatePaymentIntentFailedException
     */
    public function retrievePaymentIntentClientSecretWithClient(
        StripeClient $stripeClient,
        string $paymentIntentId,
        ?string $accountId = null,
    ): string {
        try {
            return $stripeClient->paymentIntents->retrieve(
                id: $paymentIntentId,
                opts: $accountId ? ['stripe_account' => $accountId] : []
            )->client_secret;
        } catch (ApiErrorException $exception) {
            $this->logger->error("Stripe payment intent retrieval failed: {$exception->getMessage()}", [
                'exception' => $exception,
                'paymentIntentId' => $paymentIntentId,
            ]);

            throw new CreatePaymentIntentFailedException(
                __('There was an error communicating with the payment provider. Please try again later.')
            );
        }
    }

    /**
     * @throws CreatePaymentIntentFailedException
     */
    public function retrieveValidatedPaymentIntentClientSecretWithClient(
        StripeClient $stripeClient,
        string $paymentIntentId,
        CreatePaymentIntentRequestDTO $paymentIntentDTO,
    ): string {
        $kampMetadata = $this->kampStripeMetadataService->build($paymentIntentDTO);
        if ($kampMetadata === null) {
            return $this->retrievePaymentIntentClientSecretWithClient(
                $stripeClient,
                $paymentIntentId,
                $paymentIntentDTO->stripeAccountId,
            );
        }

        $accountConfiguration = $paymentIntentDTO->account->getConfiguration();
        $applicationFee = $this->orderApplicationFeeCalculationService->calculateApplicationFee(
            accountConfiguration: $accountConfiguration,
            order: $paymentIntentDTO->order,
            vatSettings: $paymentIntentDTO->vatSettings,
        );
        $this->assertKampApplicationFee($applicationFee, $accountConfiguration?->getBypassApplicationFees() ?? false, $kampMetadata->ticketQuantity);

        try {
            $paymentIntent = $stripeClient->paymentIntents->retrieve(
                $paymentIntentId,
                [],
                $this->getStripeAccountData($paymentIntentDTO),
            );
        } catch (ApiErrorException $exception) {
            $this->logger->error('Stripe payment intent retrieval failed', [
                'exception' => $exception,
                'paymentIntentId' => $paymentIntentId,
                'orderId' => $paymentIntentDTO->order->getId(),
                'eventId' => $paymentIntentDTO->order->getEventId(),
            ]);

            throw new CreatePaymentIntentFailedException(
                __('There was an error communicating with the payment provider. Please try again later.')
            );
        }

        $actualMetadata = method_exists($paymentIntent->metadata, 'toArray')
            ? $paymentIntent->metadata->toArray()
            : (array) $paymentIntent->metadata;
        $expectedMetadata = $kampMetadata->metadata;
        ksort($actualMetadata);
        ksort($expectedMetadata);

        if ($paymentIntent->amount !== $paymentIntentDTO->amount->toMinorUnit()
            || strtolower((string) $paymentIntent->currency) !== strtolower($paymentIntentDTO->currencyCode)
            || $paymentIntent->application_fee_amount !== (int) round(KampStripeMetadataService::FIXED_APPLICATION_FEE * 100 * $kampMetadata->ticketQuantity)
            || $actualMetadata !== $expectedMetadata
            || $paymentIntent->description !== $kampMetadata->description
            || ! is_string($paymentIntent->client_secret)
            || $paymentIntent->client_secret === '') {
            throw new KampStripeMetadataConfigurationException('existing_payment_intent_contract_mismatch');
        }

        return $paymentIntent->client_secret;
    }

    /**
     * @throws CreatePaymentIntentFailedException
     * @throws ApiErrorException|Throwable
     */
    public function createPaymentIntentWithClient(
        StripeClient $stripeClient,
        CreatePaymentIntentRequestDTO $paymentIntentDTO
    ): CreatePaymentIntentResponseDTO {
        try {
            $this->databaseManager->beginTransaction();

            $accountConfiguration = $paymentIntentDTO->account->getConfiguration();
            $bypassApplicationFees = $accountConfiguration?->getBypassApplicationFees() ?? false;

            $applicationFee = $this->orderApplicationFeeCalculationService->calculateApplicationFee(
                accountConfiguration: $accountConfiguration,
                order: $paymentIntentDTO->order,
                vatSettings: $paymentIntentDTO->vatSettings,
            );
            $kampMetadata = $this->kampStripeMetadataService->build($paymentIntentDTO);

            if ($kampMetadata !== null) {
                $this->assertKampApplicationFee($applicationFee, $bypassApplicationFees, $kampMetadata->ticketQuantity);
            }

            $metadata = $kampMetadata?->metadata
                ?? $this->getPaymentIntentMetadata($paymentIntentDTO, $applicationFee);
            $description = $kampMetadata?->description ?? $paymentIntentDTO->description;

            $paymentIntent = $stripeClient->paymentIntents->create([
                'amount' => $paymentIntentDTO->amount->toMinorUnit(),
                'currency' => $paymentIntentDTO->currencyCode,
                'customer' => $this->upsertStripeCustomerWithClient($stripeClient, $paymentIntentDTO)->getStripeCustomerId(),
                'metadata' => $metadata,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                ...($description ? ['description' => $description] : []),
                ...($applicationFee && ! $bypassApplicationFees ? ['application_fee_amount' => $applicationFee->grossApplicationFee->toMinorUnit()] : []),
            ], [
                ...$this->getStripeAccountData($paymentIntentDTO),
                'idempotency_key' => $this->paymentIntentIdempotencyKey($paymentIntentDTO),
            ]);

            $this->logger->debug('Stripe payment intent created', [
                'paymentIntentId' => $paymentIntent->id,
                'orderId' => $paymentIntentDTO->order->getId(),
                'eventId' => $paymentIntentDTO->order->getEventId(),
                'stripeAccountId' => $paymentIntentDTO->stripeAccountId,
            ]);

            $this->databaseManager->commit();

            return new CreatePaymentIntentResponseDTO(
                paymentIntentId: $paymentIntent->id,
                clientSecret: $paymentIntent->client_secret,
                accountId: $paymentIntentDTO->stripeAccountId,
                applicationFeeData: $applicationFee,
            );
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->logger->error('Kamp Stripe metadata configuration failed', [
                'reason' => $exception->getReason(),
                'orderId' => $paymentIntentDTO->order->getId(),
                'eventId' => $paymentIntentDTO->order->getEventId(),
            ]);

            $this->databaseManager->rollBack();

            throw $exception;
        } catch (ApiErrorException $exception) {
            $this->logger->error('Stripe payment intent creation failed', [
                'exception' => $exception,
                'orderId' => $paymentIntentDTO->order->getId(),
                'eventId' => $paymentIntentDTO->order->getEventId(),
                'stripeAccountId' => $paymentIntentDTO->stripeAccountId,
            ]);

            $this->databaseManager->rollBack();

            throw new CreatePaymentIntentFailedException(
                __('There was an error communicating with the payment provider. Please try again later.')
            );
        } catch (Throwable $exception) {
            $this->databaseManager->rollBack();

            throw $exception;
        }
    }

    /**
     * @throws CreatePaymentIntentFailedException
     */
    private function getStripeAccountData(CreatePaymentIntentRequestDTO $paymentIntentDTO): array
    {
        if (! $this->config->get('app.saas_mode_enabled')) {
            return [];
        }

        if ($paymentIntentDTO->stripeAccountId === null) {
            $this->logger->error(
                'Stripe Connect account not found for the event organizer, payment intent creation failed.
                You will need to connect your Stripe account to receive payments.',
                [
                    'orderId' => $paymentIntentDTO->order->getId(),
                    'eventId' => $paymentIntentDTO->order->getEventId(),
                ]
            );

            throw new CreatePaymentIntentFailedException(
                __('Stripe Connect account not found for the event organizer')
            );
        }

        return [
            'stripe_account' => $paymentIntentDTO->stripeAccountId,
        ];
    }

    /**
     * @throws ApiErrorException|CreatePaymentIntentFailedException
     */
    private function upsertStripeCustomerWithClient(
        StripeClient $stripeClient,
        CreatePaymentIntentRequestDTO $paymentIntentDTO
    ): StripeCustomerDomainObject {
        $customer = $this->stripeCustomerRepository->findFirstWhere([
            'email' => $paymentIntentDTO->order->getEmail(),
            'stripe_account_id' => $paymentIntentDTO->stripeAccountId,
        ]);

        if ($customer === null) {
            $order = $paymentIntentDTO->order;

            $customerData = [
                'email' => $order->getEmail(),
                'name' => $order->getFullName(),
            ];

            if (($address = $order->getAddressDTO()) && $address->address_line_1 && $address->country) {
                $customerData['address'] = [
                    'line1' => $address->address_line_1,
                    'line2' => $address->address_line_2 ?? '',
                    'city' => $address->city ?? '',
                    'state' => $address->state_or_region ?? '',
                    'postal_code' => $address->zip_or_postal_code ?? '',
                    'country' => $address->country,
                ];
            }

            $stripeCustomer = $stripeClient->customers->create(
                params: $customerData,
                opts: [
                    ...$this->getStripeAccountData($paymentIntentDTO),
                    'idempotency_key' => $this->customerIdempotencyKey($paymentIntentDTO),
                ],
            );

            return $this->stripeCustomerRepository->create([
                'name' => $stripeCustomer->name,
                'email' => $stripeCustomer->email,
                'stripe_customer_id' => $stripeCustomer->id,
                'stripe_account_id' => $paymentIntentDTO->stripeAccountId,
            ]);
        }

        if ($customer->getName() === $paymentIntentDTO->order->getFullName()) {
            return $customer;
        }

        $stripeCustomer = $stripeClient->customers->update(
            id: $customer->getStripeCustomerId(),
            params: ['name' => $paymentIntentDTO->order->getFullName()],
            opts: $this->getStripeAccountData($paymentIntentDTO),
        );

        $this->stripeCustomerRepository->updateFromArray($customer->getId(), [
            'name' => $stripeCustomer->name,
        ]);

        return $customer;
    }

    private function paymentIntentIdempotencyKey(CreatePaymentIntentRequestDTO $paymentIntentDTO): string
    {
        return 'hie:payment-intent:v1:'.hash('sha256', $paymentIntentDTO->order->getPublicId());
    }

    private function customerIdempotencyKey(CreatePaymentIntentRequestDTO $paymentIntentDTO): string
    {
        return 'hie:customer:v1:'.hash('sha256', $paymentIntentDTO->order->getPublicId());
    }

    private function assertKampApplicationFee(
        ?ApplicationFeeValuesDTO $applicationFee,
        bool $bypassApplicationFees,
        int $ticketQuantity,
    ): void {
        $expectedApplicationFee = (int) round(
            KampStripeMetadataService::FIXED_APPLICATION_FEE * 100 * $ticketQuantity
        );
        if ($applicationFee === null
            || $bypassApplicationFees
            || $applicationFee->grossApplicationFee->toMinorUnit() !== $expectedApplicationFee) {
            throw new KampStripeMetadataConfigurationException('application_fee_amount_mismatch');
        }
    }

    private function getPaymentIntentMetadata(
        CreatePaymentIntentRequestDTO $paymentIntentDTO,
        ?ApplicationFeeValuesDTO $applicationFee
    ): array {
        $metaData = [
            'order_id' => $paymentIntentDTO->order->getId(),
            'event_id' => $paymentIntentDTO->order->getEventId(),
            'order_short_id' => $paymentIntentDTO->order->getShortId(),
            'account_id' => $paymentIntentDTO->account->getId(),

        ];

        if ($applicationFee) {
            $metaData['application_fee_gross_amount'] = $applicationFee->grossApplicationFee?->toFloat();
            $metaData['application_fee_vat_amount'] = $applicationFee->applicationFeeVatAmount?->toFloat();
            $metaData['application_fee_net_amount'] = $applicationFee->netApplicationFee?->toFloat();
            $metaData['application_fee_vat_rate'] = $applicationFee->applicationFeeVatRate;
        }

        return $metaData;
    }
}

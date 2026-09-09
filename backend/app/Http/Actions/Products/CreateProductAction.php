<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Products;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\InvalidTaxOrFeeIdException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Product\UpsertProductRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Product\ProductResource;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Infrastructure\Authorization\ProductPricingAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateProductAction extends BaseAction
{
    public function __construct(
        private readonly CreateProductHandler $createProductHandler,
        private readonly ProductPricingAuthorizationService $productPricingAuthorizationService,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(int $eventId, UpsertProductRequest $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);
        $this->productPricingAuthorizationService->validateCreate(
            $this->getAuthenticatedUserRole(),
            $request->input('tax_and_fee_ids', []),
        );

        $request->merge([
            'event_id' => $eventId,
            'account_id' => $this->getAuthenticatedAccountId(),
        ]);

        try {
            $product = $this->createProductHandler->handle(UpsertProductDTO::fromArray($request->all()));
        } catch (InvalidTaxOrFeeIdException $e) {
            throw ValidationException::withMessages([
                'tax_and_fee_ids' => $e->getMessage(),
            ]);
        }

        return $this->resourceResponse(
            resource: ProductResource::class,
            data: $product,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}

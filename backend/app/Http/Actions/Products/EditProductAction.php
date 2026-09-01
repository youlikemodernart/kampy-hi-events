<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Products;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Exceptions\CannotChangeProductTypeException;
use HiEvents\Exceptions\InvalidTaxOrFeeIdException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Product\UpsertProductRequest;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Resources\Product\ProductResource;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Product\EditProductHandler;
use HiEvents\Services\Infrastructure\Authorization\ProductPricingAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditProductAction extends BaseAction
{
    public function __construct(
        private readonly EditProductHandler $editProductHandler,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductPricingAuthorizationService $productPricingAuthorizationService,
    ) {}

    /**
     * @throws Throwable
     * @throws ValidationException
     */
    public function __invoke(UpsertProductRequest $request, int $eventId, int $productId): JsonResponse|Response
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $product = $this->productRepository
            ->loadRelation(TaxAndFeesDomainObject::class)
            ->loadRelation(ProductPriceDomainObject::class)
            ->findFirstWhere([
                ProductDomainObjectAbstract::EVENT_ID => $eventId,
                ProductDomainObjectAbstract::ID => $productId,
            ]);

        if ($product === null) {
            return $this->notFoundResponse();
        }

        $this->productPricingAuthorizationService->validateUpdate(
            role: $this->getAuthenticatedUserRole(),
            product: $product,
            submittedPriceType: $request->string('type')->toString(),
            submittedPrices: $request->input('prices', []),
            submittedTaxAndFeeIds: $request->input('tax_and_fee_ids', []),
        );

        $request->merge([
            'event_id' => $eventId,
            'account_id' => $this->getAuthenticatedAccountId(),
            'product_id' => $productId,
        ]);

        try {
            $product = $this->editProductHandler->handle(UpsertProductDTO::fromArray($request->all()));
        } catch (InvalidTaxOrFeeIdException $e) {
            throw ValidationException::withMessages([
                'tax_and_fee_ids' => $e->getMessage(),
            ]);
        } catch (CannotChangeProductTypeException $e) {
            throw ValidationException::withMessages([
                'type' => $e->getMessage(),
            ]);
        }

        return $this->resourceResponse(ProductResource::class, $product);
    }
}

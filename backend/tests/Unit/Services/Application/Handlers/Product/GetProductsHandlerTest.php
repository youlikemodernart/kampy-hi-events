<?php

namespace Tests\Unit\Services\Application\Handlers\Product;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\GetProductsHandler;
use HiEvents\Services\Domain\Product\ProductFilterService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GetProductsHandlerTest extends TestCase
{
    private ProductRepositoryInterface|MockInterface $productRepository;
    private ProductFilterService|MockInterface $productFilterService;
    private GetProductsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->productFilterService = Mockery::mock(ProductFilterService::class);

        $this->handler = new GetProductsHandler(
            $this->productRepository,
            $this->productFilterService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHandleFiltersProductPaginatorAsProductsNotCategories(): void
    {
        $eventId = 2;
        $queryParams = QueryParamsDTO::fromArray([]);
        $product = new ProductDomainObject();
        $products = collect([$product]);
        $paginator = new LengthAwarePaginator($products, 1, 25);

        $this->productRepository->shouldReceive('loadRelation')->twice()->andReturnSelf();
        $this->productRepository->shouldReceive('findByEventId')
            ->once()
            ->with($eventId, $queryParams)
            ->andReturn($paginator);

        $this->productFilterService->shouldReceive('filterProducts')
            ->once()
            ->withAnyArgs()
            ->andReturn($products);
        $this->productFilterService->shouldNotReceive('filter');

        $result = $this->handler->handle($eventId, $queryParams);

        $this->assertSame($products, $result->getCollection());
    }
}

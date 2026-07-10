<?php

namespace Tests\Unit\Services\Domain\Product;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Product\CreateProductService;
use HiEvents\Services\Domain\Product\ProductOrderingService;
use HiEvents\Services\Domain\Product\ProductPriceCreateService;
use HiEvents\Services\Domain\Tax\TaxAndProductAssociationService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class CreateProductServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPersistProductIncludesHardTicketFields(): void
    {
        $productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $databaseManager = Mockery::mock(DatabaseManager::class);
        // Readonly class, and unused when taxAndFeeIds is null — resolve a real instance.
        $taxAndProductAssociationService = app(TaxAndProductAssociationService::class);
        $priceCreateService = Mockery::mock(ProductPriceCreateService::class);
        $purifier = Mockery::mock(HtmlPurifierService::class);
        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $productOrderingService = Mockery::mock(ProductOrderingService::class);
        $domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);

        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn($callback) => $callback());
        $eventRepository->shouldReceive('findById')->andReturn((new EventDomainObject())->setId(1)->setTimezone('UTC'));
        $productOrderingService->shouldReceive('getOrderForNewProduct')->andReturn(1);
        $purifier->shouldReceive('purify')->andReturnUsing(fn($value) => $value);
        $priceCreateService->shouldReceive('createPrices')->andReturn(new Collection());
        $domainEventDispatcherService->shouldReceive('dispatch');

        $capturedCreate = null;
        $productRepository->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $attributes) use (&$capturedCreate) {
                $capturedCreate = $attributes;
                return (new ProductDomainObject())->setId(10)->setEventId(1);
            });

        $service = new CreateProductService(
            $productRepository,
            $databaseManager,
            $taxAndProductAssociationService,
            $priceCreateService,
            $purifier,
            $eventRepository,
            $productOrderingService,
            $domainEventDispatcherService,
        );

        $product = (new ProductDomainObject())
            ->setTitle('Weekend Pass')
            ->setType('PAID')
            ->setProductType('TICKET')
            ->setEventId(1)
            ->setProductCategoryId(1)
            ->setProductPrices(new Collection())
            ->setIsHardTicket(true)
            ->setHardTicketFee(4.25);

        $service->createProduct(product: $product, accountId: 1, taxAndFeeIds: null);

        $this->assertArrayHasKey('is_hard_ticket', $capturedCreate);
        $this->assertTrue($capturedCreate['is_hard_ticket']);
        $this->assertSame(4.25, $capturedCreate['hard_ticket_fee']);
    }
}

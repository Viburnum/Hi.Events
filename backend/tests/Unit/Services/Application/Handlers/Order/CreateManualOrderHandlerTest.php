<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\FulfillmentStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\ManualOrderAttendeeException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CreateManualOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CreateManualOrderDTO;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Domain\Tax\TaxAndFeeRollupService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateManualOrderHandlerTest extends TestCase
{
    private const EVENT_ID = 10;

    private const ORDER_ID = 500;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private ProductRepositoryInterface|MockInterface $productRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private ProductQuantityUpdateService|MockInterface $productQuantityUpdateService;

    private OrderManagementService|MockInterface $orderManagementService;

    private DomainEventDispatcherService|MockInterface $domainEventDispatcherService;

    private CreateManualOrderHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OrderStatusChangedEvent::class]);

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->productQuantityUpdateService = Mockery::mock(ProductQuantityUpdateService::class);
        $this->orderManagementService = Mockery::mock(OrderManagementService::class);
        $this->domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->productRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->eventRepository->shouldReceive('findById')
            ->with(self::EVENT_ID)
            ->andReturn((new EventDomainObject)->setId(self::EVENT_ID)->setCurrency('EUR'));

        $this->handler = new CreateManualOrderHandler(
            $this->orderRepository,
            $this->attendeeRepository,
            $this->productRepository,
            $this->eventRepository,
            $this->productQuantityUpdateService,
            new TaxAndFeeRollupService,
            $this->orderManagementService,
            $this->domainEventDispatcherService,
            $databaseManager,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_creates_single_order_with_all_attendees(): void
    {
        $hardTicket = $this->product(id: 1, priceId: 11, isHardTicket: true, taxes: [
            (new TaxAndFeesDomainObject)->setId(7)->setName('VAT')->setType('TAX')->setRate(19)->setCalculationType('PERCENTAGE'),
        ]);
        $eTicket = $this->product(id: 2, priceId: 21, isHardTicket: false);

        $this->productRepository->shouldReceive('findWhereIn')->once()->andReturn(collect([$hardTicket, $eTicket]));
        $this->productRepository->shouldReceive('getQuantityRemainingForProductPrice')->andReturn(10);

        $order = (new OrderDomainObject)->setId(self::ORDER_ID)->setEventId(self::EVENT_ID);

        $this->orderRepository->shouldReceive('create')
            ->once()
            ->withArgs(function (array $attributes) {
                return $attributes['email'] === 'buyer@example.com'
                    && $attributes['address']['city'] === 'Berlin'
                    && $attributes['is_manually_created'] === true
                    && $attributes['payment_status'] === OrderPaymentStatus::PAYMENT_RECEIVED->name
                    && abs($attributes['total_gross'] - 119.0) < 0.001;
            })
            ->andReturn($order);

        $addedItems = [];
        $this->orderRepository->shouldReceive('addOrderItem')
            ->times(3)
            ->andReturnUsing(function (array $attributes) use (&$addedItems) {
                $addedItems[] = $attributes;

                return (new OrderItemDomainObject)->setProductId($attributes['product_id']);
            });

        $createdAttendees = [];
        $this->attendeeRepository->shouldReceive('create')
            ->times(3)
            ->andReturnUsing(function (array $attributes) use (&$createdAttendees) {
                $createdAttendees[] = $attributes;

                return new AttendeeDomainObject;
            });

        $this->orderManagementService->shouldReceive('updateOrderTotals')
            ->once()
            ->withArgs(fn ($passedOrder, $items) => $passedOrder === $order && $items->count() === 3)
            ->andReturn($order);

        $this->productQuantityUpdateService->shouldReceive('increaseQuantitySold')->once()->with(11, 2);
        $this->productQuantityUpdateService->shouldReceive('increaseQuantitySold')->once()->with(21, 1);

        $this->domainEventDispatcherService->shouldReceive('dispatch')->once();
        $this->orderRepository->shouldReceive('findById')->with(self::ORDER_ID)->andReturn($order);

        $result = $this->handler->handle($this->dto([
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 40, 'taxes_and_fees' => [['tax_or_fee_id' => 7, 'amount' => 7.6]]],
            ['product_id' => 1, 'first_name' => 'Ben', 'email' => 'ben@example.com', 'amount_paid' => 40, 'taxes_and_fees' => [['tax_or_fee_id' => 7, 'amount' => 7.6]]],
            ['product_id' => 2, 'first_name' => 'Cleo', 'amount_paid' => 23.8],
        ]));

        $this->assertSame($order, $result);

        $this->assertSame(11, $addedItems[0]['product_price_id']);
        $this->assertEqualsWithDelta(7.6, $addedItems[0]['total_tax'], 0.001);
        $this->assertEqualsWithDelta(47.6, $addedItems[0]['total_gross'], 0.001);
        $this->assertEqualsWithDelta(0.0, $addedItems[2]['total_tax'], 0.001);

        $this->assertSame('buyer@example.com', $createdAttendees[0]['email']);
        $this->assertSame('ben@example.com', $createdAttendees[1]['email']);
        $this->assertSame(FulfillmentStatus::PENDING->name, $createdAttendees[0]['fulfillment_status']);
        $this->assertNull($createdAttendees[2]['fulfillment_status']);

        Event::assertDispatched(OrderStatusChangedEvent::class, fn ($event) => $event->sendEmails === false);
    }

    public function test_rejects_product_not_belonging_to_event(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->orderRepository->shouldNotReceive('create');

        try {
            $this->handler->handle($this->dto([
                ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10],
                ['product_id' => 99, 'first_name' => 'Ben', 'amount_paid' => 10],
            ]));
            $this->fail('Expected ManualOrderAttendeeException');
        } catch (ManualOrderAttendeeException $exception) {
            $this->assertSame('attendees.1.product_id', $exception->getValidationKey());
        }
    }

    public function test_rejects_tax_not_assigned_to_product(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->orderRepository->shouldNotReceive('create');

        $this->expectException(ManualOrderAttendeeException::class);

        $this->handler->handle($this->dto([
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10, 'taxes_and_fees' => [['tax_or_fee_id' => 3, 'amount' => 1]]],
        ]));
    }

    public function test_rejects_when_combined_quantity_exceeds_capacity(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->productRepository->shouldReceive('getQuantityRemainingForProductPrice')->with(1, 11)->andReturn(1);
        $this->orderRepository->shouldNotReceive('create');

        try {
            $this->handler->handle($this->dto([
                ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10],
                ['product_id' => 1, 'first_name' => 'Ben', 'amount_paid' => 10],
            ]));
            $this->fail('Expected ManualOrderAttendeeException');
        } catch (ManualOrderAttendeeException $exception) {
            $this->assertSame('attendees.0.product_id', $exception->getValidationKey());
        }
    }

    public function test_rejects_unknown_price_tier(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->orderRepository->shouldNotReceive('create');

        try {
            $this->handler->handle($this->dto([
                ['product_id' => 1, 'product_price_id' => 999, 'first_name' => 'Ann', 'amount_paid' => 10],
            ]));
            $this->fail('Expected ManualOrderAttendeeException');
        } catch (ManualOrderAttendeeException $exception) {
            $this->assertSame('attendees.0.product_price_id', $exception->getValidationKey());
        }
    }

    private function product(int $id, int $priceId, bool $isHardTicket = false, array $taxes = []): ProductDomainObject
    {
        $product = (new ProductDomainObject)
            ->setId($id)
            ->setTitle('Product '.$id)
            ->setIsHardTicket($isHardTicket);

        $product->setProductPrices(collect([(new ProductPriceDomainObject)->setId($priceId)->setProductId($id)]));
        $product->setTaxAndFees(collect($taxes));

        return $product;
    }

    private function dto(array $attendees): CreateManualOrderDTO
    {
        return CreateManualOrderDTO::from([
            'event_id' => self::EVENT_ID,
            'first_name' => 'Buyer',
            'last_name' => 'Person',
            'email' => 'buyer@example.com',
            'locale' => 'en',
            'send_confirmation_email' => false,
            'address' => ['address_line_1' => 'Street 1', 'city' => 'Berlin', 'country' => 'DE'],
            'attendees' => $attendees,
        ]);
    }
}

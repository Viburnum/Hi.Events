<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CapacityAssignmentDomainObject;
use HiEvents\DomainObjects\Enums\EventType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\FulfillmentStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Enterprise\Seating\Exceptions\SeatSelectionInvalidException;
use HiEvents\Enterprise\Seating\Services\Domain\SeatingEventLockService;
use HiEvents\Enterprise\Seating\Services\Domain\SeatSelectionValidationService;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\ManualOrderAttendeeException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventOccurrenceRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CreateManualOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CreateManualOrderDTO;
use HiEvents\Services\Domain\EventOccurrence\OccurrencePurchaseEligibilityService;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Domain\SelfService\OrderAuditLogService;
use HiEvents\Services\Domain\Tax\TaxAndFeeRollupService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateManualOrderHandlerTest extends TestCase
{
    private const EVENT_ID = 10;

    private const OCCURRENCE_ID = 77;

    private const ORDER_ID = 500;

    private OrderRepositoryInterface|MockInterface $orderRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private ProductRepositoryInterface|MockInterface $productRepository;

    private EventRepositoryInterface|MockInterface $eventRepository;

    private EventOccurrenceRepositoryInterface|MockInterface $eventOccurrenceRepository;

    private ProductQuantityUpdateService|MockInterface $productQuantityUpdateService;

    private AvailableProductQuantitiesFetchService|MockInterface $availabilityService;

    private OccurrencePurchaseEligibilityService|MockInterface $eligibilityService;

    private SeatSelectionValidationService|MockInterface $seatSelectionValidationService;

    private OrderManagementService|MockInterface $orderManagementService;

    private OrderAuditLogService|MockInterface $orderAuditLogService;

    private DomainEventDispatcherService|MockInterface $domainEventDispatcherService;

    private EventDomainObject $event;

    private CreateManualOrderHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OrderStatusChangedEvent::class]);

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->eventOccurrenceRepository = Mockery::mock(EventOccurrenceRepositoryInterface::class);
        $this->productQuantityUpdateService = Mockery::mock(ProductQuantityUpdateService::class);
        $this->availabilityService = Mockery::mock(AvailableProductQuantitiesFetchService::class);
        $this->eligibilityService = Mockery::mock(OccurrencePurchaseEligibilityService::class);
        $this->seatSelectionValidationService = Mockery::mock(SeatSelectionValidationService::class);
        $this->orderManagementService = Mockery::mock(OrderManagementService::class);
        $this->orderAuditLogService = Mockery::mock(OrderAuditLogService::class);
        $this->domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);

        $seatingEventLock = Mockery::mock(SeatingEventLockService::class);
        $seatingEventLock->shouldReceive('lock')->with(self::EVENT_ID);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $this->productRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();

        $this->event = (new EventDomainObject)
            ->setId(self::EVENT_ID)
            ->setCurrency('EUR')
            ->setType(EventType::SINGLE->name);
        $this->eventRepository->shouldReceive('findById')->with(self::EVENT_ID)->andReturnUsing(fn () => $this->event);

        $this->eventOccurrenceRepository->shouldReceive('findFirstWhere')
            ->andReturn((new EventOccurrenceDomainObject)->setId(self::OCCURRENCE_ID));

        $this->eligibilityService->shouldReceive('assertOccurrencePurchasable')->byDefault();
        $this->eligibilityService->shouldReceive('assertProductsVisibleOnOccurrence')->byDefault();
        $this->seatSelectionValidationService->shouldReceive('validate')->byDefault();

        $this->handler = new CreateManualOrderHandler(
            $this->orderRepository,
            $this->attendeeRepository,
            $this->productRepository,
            $this->eventRepository,
            $this->eventOccurrenceRepository,
            $this->productQuantityUpdateService,
            $this->availabilityService,
            $this->eligibilityService,
            $this->seatSelectionValidationService,
            $seatingEventLock,
            new TaxAndFeeRollupService,
            $this->orderManagementService,
            $this->orderAuditLogService,
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
        $eTicket = $this->product(id: 2, priceId: 21);

        $this->productRepository->shouldReceive('findWhereIn')->once()->andReturn(collect([$hardTicket, $eTicket]));
        $this->mockAvailability([11 => 10, 21 => 10]);

        $this->eligibilityService->shouldReceive('assertOccurrencePurchasable')
            ->once()
            ->withArgs(fn (...$args) => $args === [self::EVENT_ID, self::OCCURRENCE_ID, 3, false]);

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

                return (new AttendeeDomainObject)->setId(count($createdAttendees));
            });

        $this->orderManagementService->shouldReceive('updateOrderTotals')
            ->once()
            ->withArgs(fn ($passedOrder, $items) => $passedOrder === $order && $items->count() === 3)
            ->andReturn($order);

        $this->productQuantityUpdateService->shouldReceive('increaseQuantitySold')->once()->with(11, 2, self::OCCURRENCE_ID);
        $this->productQuantityUpdateService->shouldReceive('increaseQuantitySold')->once()->with(21, 1, self::OCCURRENCE_ID);

        $this->domainEventDispatcherService->shouldReceive('dispatch')->once();
        $this->orderAuditLogService->shouldNotReceive('logManualAttendeeCapacityOverride');
        $this->orderRepository->shouldReceive('findById')->with(self::ORDER_ID)->andReturn($order);

        $result = $this->handler->handle($this->dto([
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 40, 'taxes_and_fees' => [['tax_or_fee_id' => 7, 'amount' => 7.6]]],
            ['product_id' => 1, 'first_name' => 'Ben', 'email' => 'ben@example.com', 'amount_paid' => 40, 'taxes_and_fees' => [['tax_or_fee_id' => 7, 'amount' => 7.6]]],
            ['product_id' => 2, 'first_name' => 'Cleo', 'amount_paid' => 23.8],
        ]));

        $this->assertSame($order, $result);

        $this->assertSame(11, $addedItems[0]['product_price_id']);
        $this->assertSame(self::OCCURRENCE_ID, $addedItems[0]['event_occurrence_id']);
        $this->assertEqualsWithDelta(7.6, $addedItems[0]['total_tax'], 0.001);
        $this->assertEqualsWithDelta(47.6, $addedItems[0]['total_gross'], 0.001);
        $this->assertEqualsWithDelta(0.0, $addedItems[2]['total_tax'], 0.001);

        $this->assertSame('buyer@example.com', $createdAttendees[0]['email']);
        $this->assertSame('ben@example.com', $createdAttendees[1]['email']);
        $this->assertSame(self::OCCURRENCE_ID, $createdAttendees[0]['event_occurrence_id']);
        $this->assertSame(FulfillmentStatus::PENDING->name, $createdAttendees[0]['fulfillment_status']);
        $this->assertNull($createdAttendees[2]['fulfillment_status']);

        Event::assertDispatched(OrderStatusChangedEvent::class, fn ($event) => $event->sendEmails === false);
    }

    public function test_logs_capacity_override_for_each_attendee(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->mockAvailability([11 => 10]);

        $order = (new OrderDomainObject)->setId(self::ORDER_ID)->setEventId(self::EVENT_ID);
        $this->orderRepository->shouldReceive('create')->andReturn($order);
        $this->orderRepository->shouldReceive('addOrderItem')->andReturn(new OrderItemDomainObject);
        $this->orderRepository->shouldReceive('findById')->andReturn($order);
        $this->attendeeRepository->shouldReceive('create')->andReturn(
            (new AttendeeDomainObject)->setId(1),
            (new AttendeeDomainObject)->setId(2),
        );
        $this->orderManagementService->shouldReceive('updateOrderTotals')->andReturn($order);
        $this->productQuantityUpdateService->shouldReceive('increaseQuantitySold');
        $this->domainEventDispatcherService->shouldReceive('dispatch');

        $this->eligibilityService->shouldReceive('assertOccurrencePurchasable')
            ->once()
            ->withArgs(fn (...$args) => $args === [self::EVENT_ID, self::OCCURRENCE_ID, 2, true]);

        $this->orderAuditLogService->shouldReceive('logManualAttendeeCapacityOverride')
            ->twice()
            ->withArgs(fn (...$args) => $args[0] === self::EVENT_ID && $args[1] === self::ORDER_ID && $args[3] === self::OCCURRENCE_ID);

        $result = $this->handler->handle($this->dto([
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 0],
            ['product_id' => 1, 'first_name' => 'Ben', 'amount_paid' => 0],
        ], ['override_capacity' => true, 'client_ip' => '127.0.0.1']));

        $this->assertSame($order, $result);
    }

    public function test_requires_occurrence_for_recurring_events(): void
    {
        $this->event->setType(EventType::RECURRING->name);
        $this->orderRepository->shouldNotReceive('create');

        try {
            $this->handler->handle($this->dto([
                ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10],
            ]));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event_occurrence_id', $exception->errors());
        }
    }

    public function test_rejects_product_not_belonging_to_event(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->orderRepository->shouldNotReceive('create');

        $this->assertManualOrderError('attendees.1.product_id', [
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10],
            ['product_id' => 99, 'first_name' => 'Ben', 'amount_paid' => 10],
        ]);
    }

    public function test_rejects_seated_ticket(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->seatSelectionValidationService->shouldReceive('validate')
            ->andThrow(new SeatSelectionInvalidException('Please choose a seat for every ticket'));
        $this->orderRepository->shouldNotReceive('create');

        $this->assertManualOrderError('attendees.0.product_id', [
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10],
        ]);
    }

    public function test_rejects_tax_not_assigned_to_product(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->orderRepository->shouldNotReceive('create');

        $this->assertManualOrderError('attendees.0.taxes_and_fees', [
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10, 'taxes_and_fees' => [['tax_or_fee_id' => 3, 'amount' => 1]]],
        ]);
    }

    public function test_rejects_when_combined_quantity_exceeds_capacity(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->mockAvailability([11 => 1]);
        $this->orderRepository->shouldNotReceive('create');

        $this->assertManualOrderError('attendees.0.product_id', [
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10],
            ['product_id' => 1, 'first_name' => 'Ben', 'amount_paid' => 10],
        ]);
    }

    public function test_rejects_when_shared_capacity_pool_is_full(): void
    {
        $productA = $this->product(id: 1, priceId: 11);
        $productB = $this->product(id: 2, priceId: 21);
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$productA, $productB]));

        $pool = (new CapacityAssignmentDomainObject)->setId(5)->setCapacity(1)->setUsedCapacity(0);
        $pool->setProducts(collect([$productB]));
        $this->mockAvailability([11 => 10, 21 => 10], collect([$pool]));
        $this->orderRepository->shouldNotReceive('create');

        $this->assertManualOrderError('attendees.1.product_id', [
            ['product_id' => 1, 'first_name' => 'Ann', 'amount_paid' => 10],
            ['product_id' => 2, 'first_name' => 'Ben', 'amount_paid' => 10],
            ['product_id' => 2, 'first_name' => 'Cleo', 'amount_paid' => 10],
        ]);
    }

    public function test_rejects_unknown_price_tier(): void
    {
        $this->productRepository->shouldReceive('findWhereIn')->andReturn(collect([$this->product(id: 1, priceId: 11)]));
        $this->orderRepository->shouldNotReceive('create');

        $this->assertManualOrderError('attendees.0.product_price_id', [
            ['product_id' => 1, 'product_price_id' => 999, 'first_name' => 'Ann', 'amount_paid' => 10],
        ]);
    }

    private function assertManualOrderError(string $expectedKey, array $attendees): void
    {
        try {
            $this->handler->handle($this->dto($attendees));
            $this->fail('Expected ManualOrderAttendeeException');
        } catch (ManualOrderAttendeeException $exception) {
            $this->assertSame($expectedKey, $exception->getValidationKey());
        }
    }

    private function mockAvailability(array $availableByPriceId, ?Collection $capacities = null): void
    {
        $quantities = collect($availableByPriceId)->map(fn (int $available, int $priceId) => new AvailableProductQuantitiesDTO(
            product_id: intdiv($priceId, 10),
            price_id: $priceId,
            product_title: 'Product',
            price_label: null,
            quantity_available: $available,
            quantity_reserved: 0,
            initial_quantity_available: $available,
            product_type: 'TICKET',
        ))->values();

        $this->availabilityService->shouldReceive('getAvailableProductQuantities')
            ->andReturn(new AvailableProductQuantitiesResponseDTO(productQuantities: $quantities, capacities: $capacities));
    }

    private function product(int $id, int $priceId, bool $isHardTicket = false, array $taxes = []): ProductDomainObject
    {
        $product = (new ProductDomainObject)
            ->setId($id)
            ->setTitle('Product '.$id)
            ->setProductType('TICKET')
            ->setIsHardTicket($isHardTicket);

        $product->setProductPrices(collect([(new ProductPriceDomainObject)->setId($priceId)->setProductId($id)]));
        $product->setTaxAndFees(collect($taxes));

        return $product;
    }

    private function dto(array $attendees, array $overrides = []): CreateManualOrderDTO
    {
        return CreateManualOrderDTO::from(array_merge([
            'event_id' => self::EVENT_ID,
            'first_name' => 'Buyer',
            'last_name' => 'Person',
            'email' => 'buyer@example.com',
            'locale' => 'en',
            'send_confirmation_email' => false,
            'address' => ['address_line_1' => 'Street 1', 'city' => 'Berlin', 'country' => 'DE'],
            'attendees' => $attendees,
        ], $overrides));
    }
}

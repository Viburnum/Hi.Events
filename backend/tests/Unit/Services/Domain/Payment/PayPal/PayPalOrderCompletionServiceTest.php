<?php

namespace Tests\Unit\Services\Domain\Payment\PayPal;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayPal\PayPalOrderCompletionService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class PayPalOrderCompletionServiceTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private AffiliateRepositoryInterface $affiliateRepository;
    private ProductQuantityUpdateService $quantityUpdateService;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private DomainEventDispatcherService $domainEventDispatcherService;
    private DatabaseManager $databaseManager;
    private LoggerInterface $logger;
    private PayPalOrderCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->affiliateRepository = m::mock(AffiliateRepositoryInterface::class);
        $this->quantityUpdateService = m::mock(ProductQuantityUpdateService::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->domainEventDispatcherService = m::mock(DomainEventDispatcherService::class);
        $this->databaseManager = m::mock(DatabaseManager::class);
        $this->logger = m::mock(LoggerInterface::class);

        $this->databaseManager->shouldReceive('transaction')
            ->andReturnUsing(fn($callback) => $callback());

        $this->logger->shouldReceive('info');

        $this->service = new PayPalOrderCompletionService(
            $this->orderRepository,
            $this->attendeeRepository,
            $this->affiliateRepository,
            $this->quantityUpdateService,
            $this->eventSettingsRepository,
            $this->domainEventDispatcherService,
            $this->databaseManager,
            $this->logger,
        );
    }

    public function testCompleteOrderTransitionsOrderAndActivatesAttendees(): void
    {
        Event::fake();

        $orderId = 55;

        $order = (new OrderDomainObject())
            ->setId($orderId)
            ->setEventId(10)
            ->setStatus(OrderStatus::RESERVED->name)
            ->setPaymentStatus(OrderPaymentStatus::AWAITING_PAYMENT->name)
            ->setTotalGross(60.00)
            ->setCurrency('USD')
            ->setAffiliateId(null);

        $updatedOrder = (new OrderDomainObject())
            ->setId($orderId)
            ->setEventId(10)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setPaymentStatus(OrderPaymentStatus::PAYMENT_RECEIVED->name)
            ->setTotalGross(60.00)
            ->setCurrency('USD')
            ->setAffiliateId(null);

        $this->orderRepository->shouldReceive('findById')
            ->once()
            ->with($orderId)
            ->andReturn($order);

        $this->orderRepository->shouldReceive('loadRelation')
            ->andReturnSelf();

        $this->orderRepository->shouldReceive('updateFromArray')
            ->once()
            ->with($orderId, m::on(function (array $attributes) {
                return $attributes['status'] === OrderStatus::COMPLETED->name
                    && $attributes['payment_status'] === OrderPaymentStatus::PAYMENT_RECEIVED->name
                    && $attributes['payment_provider'] === 'PAYPAL';
            }))
            ->andReturn($updatedOrder);

        $this->attendeeRepository->shouldReceive('updateWhere')
            ->once()
            ->with(
                ['status' => AttendeeStatus::ACTIVE->name],
                ['order_id' => $orderId, 'status' => AttendeeStatus::AWAITING_PAYMENT->name],
            )
            ->andReturn(1);

        $this->quantityUpdateService->shouldReceive('updateQuantitiesFromOrder')
            ->once()
            ->with($updatedOrder);

        $eventSettings = (new EventSettingDomainObject())->setEnableInvoicing(false);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($eventSettings);

        $this->domainEventDispatcherService->shouldReceive('dispatch')
            ->once();

        $this->affiliateRepository->shouldNotReceive('incrementSales');

        $this->service->completeOrder($orderId);

        Event::assertDispatched(OrderStatusChangedEvent::class);
    }

    public function testCompleteOrderIsIdempotentWhenOrderAlreadyCompleted(): void
    {
        Event::fake();

        $orderId = 77;

        $order = (new OrderDomainObject())
            ->setId($orderId)
            ->setEventId(10)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setPaymentStatus(OrderPaymentStatus::PAYMENT_RECEIVED->name)
            ->setTotalGross(60.00)
            ->setCurrency('USD');

        $this->orderRepository->shouldReceive('findById')
            ->once()
            ->with($orderId)
            ->andReturn($order);

        $this->orderRepository->shouldNotReceive('updateFromArray');
        $this->attendeeRepository->shouldNotReceive('updateWhere');
        $this->quantityUpdateService->shouldNotReceive('updateQuantitiesFromOrder');
        $this->domainEventDispatcherService->shouldNotReceive('dispatch');
        $this->affiliateRepository->shouldNotReceive('incrementSales');

        $this->service->completeOrder($orderId);

        Event::assertNotDispatched(OrderStatusChangedEvent::class);
    }
}

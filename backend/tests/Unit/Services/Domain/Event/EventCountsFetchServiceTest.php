<?php

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\DomainObjects\EventStatisticDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\FulfillmentStatus;
use HiEvents\Repository\Interfaces\EventStatisticRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Event\EventCountsFetchService;
use Illuminate\Support\Collection;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class EventCountsFetchServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private EventStatisticRepositoryInterface $repository;

    private OrderRepositoryInterface $orderRepository;

    private EventCountsFetchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = m::mock(EventStatisticRepositoryInterface::class);
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->service = new EventCountsFetchService($this->repository, $this->orderRepository);
    }

    public function test_it_returns_the_lifetime_counts_for_the_event(): void
    {
        $statistics = (new EventStatisticDomainObject)
            ->setOrdersCreated(12)
            ->setAttendeesRegistered(31);

        $this->repository
            ->shouldReceive('findFirstWhere')
            ->with([EventStatisticDomainObject::EVENT_ID => 5])
            ->once()
            ->andReturn($statistics);

        $this->orderRepository
            ->shouldReceive('findWhere')
            ->with([
                OrderDomainObject::EVENT_ID => 5,
                OrderDomainObject::FULFILLMENT_STATUS => FulfillmentStatus::PENDING->name,
            ])
            ->once()
            ->andReturn(new Collection([new OrderDomainObject, new OrderDomainObject]));

        $counts = $this->service->getEventCounts(5);

        $this->assertSame(12, $counts->total_orders);
        $this->assertSame(31, $counts->total_attendees_registered);
        $this->assertSame(2, $counts->total_pending_fulfillment_orders);
    }

    public function test_it_returns_zeros_when_the_event_has_no_statistics_row(): void
    {
        $this->repository
            ->shouldReceive('findFirstWhere')
            ->with([EventStatisticDomainObject::EVENT_ID => 9])
            ->once()
            ->andReturnNull();

        $this->orderRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn(new Collection);

        $counts = $this->service->getEventCounts(9);

        $this->assertSame(0, $counts->total_orders);
        $this->assertSame(0, $counts->total_attendees_registered);
        $this->assertSame(0, $counts->total_pending_fulfillment_orders);
    }
}

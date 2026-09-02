<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event;

use HiEvents\DomainObjects\EventStatisticDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\FulfillmentStatus;
use HiEvents\Repository\Interfaces\EventStatisticRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Event\DTO\EventCountsResponseDTO;

readonly class EventCountsFetchService
{
    public function __construct(
        private EventStatisticRepositoryInterface $eventStatisticRepository,
        private OrderRepositoryInterface $orderRepository,
    ) {}

    public function getEventCounts(int $eventId): EventCountsResponseDTO
    {
        $statistics = $this->eventStatisticRepository->findFirstWhere([
            EventStatisticDomainObject::EVENT_ID => $eventId,
        ]);

        $pendingFulfillmentOrders = $this->orderRepository->findWhere([
            OrderDomainObject::EVENT_ID => $eventId,
            OrderDomainObject::FULFILLMENT_STATUS => FulfillmentStatus::PENDING->name,
        ]);

        return new EventCountsResponseDTO(
            total_orders: $statistics?->getOrdersCreated() ?? 0,
            total_attendees_registered: $statistics?->getAttendeesRegistered() ?? 0,
            total_pending_fulfillment_orders: $pendingFulfillmentOrders->count(),
        );
    }
}

<?php

namespace HiEvents\Http\Actions\Fulfillment;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\FulfillmentStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Resources\Order\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UpdateOrderFulfillmentStatusAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    public function __invoke(Request $request, int $eventId, int $orderId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObject::ID => $orderId,
            OrderDomainObject::EVENT_ID => $eventId,
        ]);

        if ($order === null) {
            return $this->errorResponse(
                __('Order not found'),
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($order->getFulfillmentStatus() === null) {
            return $this->errorResponse(
                __('This order does not require fulfillment'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $attendees = $this->attendeeRepository->findWhere([
            AttendeeDomainObject::ORDER_ID => $orderId,
            AttendeeDomainObject::EVENT_ID => $eventId,
        ]);

        $productIds = $attendees->map(fn(AttendeeDomainObject $a) => $a->getProductId())->unique()->values()->toArray();
        $products = $this->productRepository->findWhereIn('id', $productIds);
        $hardTicketProductIds = $products->filter(fn($p) => $p->getIsHardTicket())->map(fn($p) => $p->getId())->toArray();

        $unfulfilledHardTicketAttendees = $attendees->filter(function (AttendeeDomainObject $attendee) use ($hardTicketProductIds) {
            return in_array($attendee->getProductId(), $hardTicketProductIds, true)
                && $attendee->getFulfillmentStatus() !== FulfillmentStatus::FULFILLED->name;
        });

        if ($unfulfilledHardTicketAttendees->isNotEmpty()) {
            return $this->errorResponse(
                __('All hard ticket attendees must have barcodes assigned before marking order as fulfilled'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $updatedOrder = $this->orderRepository->updateFromArray($orderId, [
            OrderDomainObject::FULFILLMENT_STATUS => FulfillmentStatus::FULFILLED->name,
        ]);

        return $this->resourceResponse(
            resource: OrderResource::class,
            data: $updatedOrder,
        );
    }
}

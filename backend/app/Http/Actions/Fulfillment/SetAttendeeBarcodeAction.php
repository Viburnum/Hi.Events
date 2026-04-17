<?php

namespace HiEvents\Http\Actions\Fulfillment;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\FulfillmentStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Fulfillment\SetAttendeeBarcodeRequest;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Resources\Attendee\AttendeeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SetAttendeeBarcodeAction extends BaseAction
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    public function __invoke(SetAttendeeBarcodeRequest $request, int $eventId, int $attendeeId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $attendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObject::ID => $attendeeId,
            AttendeeDomainObject::EVENT_ID => $eventId,
        ]);

        if ($attendee === null) {
            return $this->errorResponse(
                __('Attendee not found'),
                Response::HTTP_NOT_FOUND,
            );
        }

        $product = $this->productRepository->findById($attendee->getProductId());

        if (!$product->getIsHardTicket()) {
            return $this->errorResponse(
                __('This attendee\'s product is not a hard ticket'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $barcode = $request->input('barcode');

        $existingAttendee = $this->attendeeRepository->findFirstWhere([
            AttendeeDomainObject::PUBLIC_ID => $barcode,
            AttendeeDomainObject::EVENT_ID => $eventId,
        ]);

        if ($existingAttendee !== null && $existingAttendee->getId() !== $attendeeId) {
            return $this->errorResponse(
                __('This barcode is already assigned to another attendee in this event'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $updatedAttendee = $this->attendeeRepository->updateFromArray($attendeeId, [
            AttendeeDomainObject::PUBLIC_ID => $barcode,
            AttendeeDomainObject::FULFILLMENT_STATUS => FulfillmentStatus::FULFILLED->name,
        ]);

        return $this->resourceResponse(
            resource: AttendeeResource::class,
            data: $updatedAttendee,
        );
    }
}

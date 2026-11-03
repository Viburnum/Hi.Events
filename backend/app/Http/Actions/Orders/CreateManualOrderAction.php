<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ManualOrderAttendeeException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\CreateManualOrderRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Services\Application\Handlers\Order\CreateManualOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CreateManualOrderDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateManualOrderAction extends BaseAction
{
    public function __construct(
        private readonly CreateManualOrderHandler $createManualOrderHandler,
    ) {}

    /**
     * @throws ValidationException|Throwable
     */
    public function __invoke(CreateManualOrderRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $order = $this->createManualOrderHandler->handle(CreateManualOrderDTO::from(
                array_merge($request->validated(), [
                    'event_id' => $eventId,
                ])
            ));
        } catch (ManualOrderAttendeeException $exception) {
            throw ValidationException::withMessages([
                $exception->getValidationKey() => $exception->getMessage(),
            ]);
        }

        return $this->resourceResponse(
            resource: OrderResource::class,
            data: $order,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}

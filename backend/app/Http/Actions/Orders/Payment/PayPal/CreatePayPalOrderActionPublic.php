<?php

namespace HiEvents\Http\Actions\Orders\Payment\PayPal;

use HiEvents\Exceptions\PayPal\PayPalApiException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\Payment\PayPal\CreatePayPalOrderHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreatePayPalOrderActionPublic extends BaseAction
{
    public function __construct(
        private readonly CreatePayPalOrderHandler $createPayPalOrderHandler,
    ) {}

    public function __invoke(int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $response = $this->createPayPalOrderHandler->handle($orderShortId);
        } catch (PayPalApiException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse([
            'paypal_order_id' => $response->paypalOrderId,
            'client_id' => $response->clientId,
            'currency' => $response->currency,
        ]);
    }
}

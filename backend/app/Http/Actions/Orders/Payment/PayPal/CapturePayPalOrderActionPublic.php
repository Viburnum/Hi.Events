<?php

namespace HiEvents\Http\Actions\Orders\Payment\PayPal;

use HiEvents\Exceptions\PayPal\PayPalApiException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Order\Payment\PayPal\CapturePayPalOrderHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CapturePayPalOrderActionPublic extends BaseAction
{
    public function __construct(
        private readonly CapturePayPalOrderHandler $capturePayPalOrderHandler,
    ) {}

    /**
     * @throws Throwable
     */
    public function __invoke(int $eventId, string $orderShortId, string $paypalOrderId): JsonResponse
    {
        try {
            $response = $this->capturePayPalOrderHandler->handle($orderShortId, $paypalOrderId);
        } catch (PayPalApiException $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->jsonResponse([
            'status' => $response->status,
        ]);
    }
}

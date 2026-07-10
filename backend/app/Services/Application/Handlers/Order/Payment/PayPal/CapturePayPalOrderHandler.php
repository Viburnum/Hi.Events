<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\PayPal;

use HiEvents\DomainObjects\Generated\PaypalPaymentDomainObjectAbstract;
use HiEvents\Exceptions\PayPal\PayPalApiException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayPalPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayPal\DTOs\CapturePayPalOrderResponseDTO;
use HiEvents\Services\Domain\Payment\PayPal\PayPalOrderCaptureService;
use HiEvents\Services\Domain\Payment\PayPal\PayPalOrderCompletionService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Throwable;

readonly class CapturePayPalOrderHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private PayPalOrderCaptureService $payPalOrderCaptureService,
        private PayPalPaymentsRepositoryInterface $payPalPaymentsRepository,
        private PayPalOrderCompletionService $payPalOrderCompletionService,
        private CheckoutSessionManagementService $sessionIdentifierService,
    ) {}

    /**
     * @throws PayPalApiException
     * @throws Throwable
     */
    public function handle(string $orderShortId, string $paypalOrderId): CapturePayPalOrderResponseDTO
    {
        $order = $this->orderRepository->findByShortId($orderShortId);

        if (! $order || ! $this->sessionIdentifierService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        $payPalPayment = $this->payPalPaymentsRepository->findFirstWhere([
            PaypalPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            PaypalPaymentDomainObjectAbstract::PAYPAL_ORDER_ID => $paypalOrderId,
        ]);

        if ($payPalPayment === null) {
            throw new ResourceConflictException(__('Sorry, this payment could not be found.'));
        }

        $captureResult = $this->payPalOrderCaptureService->captureOrder($paypalOrderId);

        $this->payPalPaymentsRepository->updateWhere(
            attributes: [
                PaypalPaymentDomainObjectAbstract::CAPTURE_ID => $captureResult->captureId,
                PaypalPaymentDomainObjectAbstract::STATUS => $captureResult->status,
                PaypalPaymentDomainObjectAbstract::AMOUNT_RECEIVED => $captureResult->amountReceived,
            ],
            where: [
                PaypalPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
                PaypalPaymentDomainObjectAbstract::PAYPAL_ORDER_ID => $paypalOrderId,
            ],
        );

        if ($captureResult->status === 'COMPLETED') {
            $this->payPalOrderCompletionService->completeOrder($order->getId());
        }

        return $captureResult;
    }
}

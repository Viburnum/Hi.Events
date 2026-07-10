<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\PayPal;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use HiEvents\DomainObjects\Generated\PaypalPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\PaypalPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\PayPal\PayPalApiException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayPalPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayPal\DTOs\CreatePayPalOrderResponseDTO;
use HiEvents\Services\Domain\Payment\PayPal\PayPalOrderCreationService;
use HiEvents\Services\Infrastructure\PayPal\PayPalConfigurationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;

readonly class CreatePayPalOrderHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private PayPalOrderCreationService $payPalOrderCreationService,
        private CheckoutSessionManagementService $sessionIdentifierService,
        private PayPalPaymentsRepositoryInterface $payPalPaymentsRepository,
        private PayPalConfigurationService $payPalConfigurationService,
    ) {}

    /**
     * @throws PayPalApiException
     * @throws MathException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    public function handle(string $orderShortId): CreatePayPalOrderResponseDTO
    {
        $order = $this->orderRepository->findByShortId($orderShortId);

        if (! $order || ! $this->sessionIdentifierService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name || $order->isReservedOrderExpired()) {
            throw new ResourceConflictException(__('Sorry, is expired or not in a valid state.'));
        }

        $clientId = $this->payPalConfigurationService->getClientId();
        $currency = strtoupper($order->getCurrency());

        /** @var PaypalPaymentDomainObject|null $existingPayment */
        $existingPayment = $this->payPalPaymentsRepository->findFirstWhere([
            PaypalPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
        ]);

        if ($existingPayment !== null) {
            return new CreatePayPalOrderResponseDTO(
                paypalOrderId: $existingPayment->getPaypalOrderId(),
                clientId: $clientId,
                currency: $currency,
            );
        }

        $result = $this->payPalOrderCreationService->createOrder($order);

        $this->payPalPaymentsRepository->create([
            PaypalPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            PaypalPaymentDomainObjectAbstract::PAYPAL_ORDER_ID => $result->paypalOrderId,
            PaypalPaymentDomainObjectAbstract::STATUS => $result->status,
            PaypalPaymentDomainObjectAbstract::CURRENCY => $currency,
        ]);

        return new CreatePayPalOrderResponseDTO(
            paypalOrderId: $result->paypalOrderId,
            clientId: $clientId,
            currency: $currency,
        );
    }
}

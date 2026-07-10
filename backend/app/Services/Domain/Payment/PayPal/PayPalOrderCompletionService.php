<?php

namespace HiEvents\Services\Domain\Payment\PayPal;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mirrors PaymentIntentSucceededHandler — keep in sync on upstream changes.
 *
 * Performs the order-completion transition for PayPal payments. It is invoked both by the
 * capture handler (after a successful synchronous capture) and by the incoming webhook handler,
 * so it must remain idempotent: completing an already-completed order is a no-op.
 */
class PayPalOrderCompletionService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly ProductQuantityUpdateService $quantityUpdateService,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function completeOrder(int $orderId): void
    {
        $this->databaseManager->transaction(function () use ($orderId) {
            $order = $this->orderRepository->findById($orderId);

            if ($order->getStatus() === OrderStatus::COMPLETED->name) {
                $this->logger->info('PayPal order completion skipped, order already completed', [
                    'order_id' => $orderId,
                ]);

                return;
            }

            $this->validateOrderStatus($order);

            $updatedOrder = $this->updateOrderStatuses($order);

            $this->updateAttendeeStatuses($updatedOrder);

            $this->quantityUpdateService->updateQuantitiesFromOrder($updatedOrder);

            /** @var EventSettingDomainObject $eventSettings */
            $eventSettings = $this->eventSettingsRepository->findFirstWhere([
                EventSettingDomainObjectAbstract::EVENT_ID => $updatedOrder->getEventId(),
            ]);

            event(new OrderStatusChangedEvent($updatedOrder, createInvoice: $eventSettings->getEnableInvoicing()));

            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(
                    type: DomainEventType::ORDER_CREATED,
                    orderId: $updatedOrder->getId(),
                ),
            );

            $this->logger->info('PayPal order completed', [
                'order_id' => $updatedOrder->getId(),
            ]);
        });
    }

    /**
     * @throws CannotAcceptPaymentException
     */
    private function validateOrderStatus(OrderDomainObject $order): void
    {
        if (! in_array($order->getPaymentStatus(), [
            OrderPaymentStatus::AWAITING_PAYMENT->name,
            OrderPaymentStatus::PAYMENT_FAILED->name,
        ], true)) {
            throw new CannotAcceptPaymentException(
                __('Order is not awaiting payment. Order: :id', ['id' => $order->getId()])
            );
        }
    }

    private function updateOrderStatuses(OrderDomainObject $order): OrderDomainObject
    {
        $updatedOrder = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->updateFromArray($order->getId(), [
                OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
                OrderDomainObjectAbstract::PAYMENT_PROVIDER => PaymentProviders::PAYPAL->value,
            ]);

        if ($updatedOrder->getAffiliateId()) {
            $this->affiliateRepository->incrementSales(
                affiliateId: $updatedOrder->getAffiliateId(),
                amount: $updatedOrder->getTotalGross()
            );
        }

        return $updatedOrder;
    }

    private function updateAttendeeStatuses(OrderDomainObject $updatedOrder): void
    {
        $this->attendeeRepository->updateWhere(
            attributes: [
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            where: [
                'order_id' => $updatedOrder->getId(),
                'status' => AttendeeStatus::AWAITING_PAYMENT->name,
            ],
        );
    }
}

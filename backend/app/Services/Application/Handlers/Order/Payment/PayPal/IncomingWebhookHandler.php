<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\PayPal;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PaypalPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\PaypalPaymentDomainObject;
use HiEvents\Exceptions\PayPal\PayPalApiException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayPalPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\PayPal\DTO\PayPalWebhookDTO;
use HiEvents\Services\Domain\Payment\PayPal\PayPalOrderCompletionService;
use HiEvents\Services\Infrastructure\PayPal\PayPalClient;
use HiEvents\Services\Infrastructure\PayPal\PayPalConfigurationService;
use Illuminate\Cache\Repository;
use Illuminate\Log\Logger;
use JsonException;
use Throwable;

class IncomingWebhookHandler
{
    private const EVENT_PAYMENT_CAPTURE_COMPLETED = 'PAYMENT.CAPTURE.COMPLETED';

    private const EVENT_CHECKOUT_ORDER_APPROVED = 'CHECKOUT.ORDER.APPROVED';

    private static array $validEvents = [
        self::EVENT_PAYMENT_CAPTURE_COMPLETED,
        self::EVENT_CHECKOUT_ORDER_APPROVED,
    ];

    public function __construct(
        private readonly PayPalClient $payPalClient,
        private readonly PayPalConfigurationService $payPalConfigurationService,
        private readonly PayPalPaymentsRepositoryInterface $payPalPaymentsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PayPalOrderCompletionService $payPalOrderCompletionService,
        private readonly Logger $logger,
        private readonly Repository $cache,
    ) {}

    /**
     * @throws PayPalApiException
     * @throws JsonException
     * @throws Throwable
     */
    public function handle(PayPalWebhookDTO $webhookDTO): void
    {
        $webhookId = $this->payPalConfigurationService->getWebhookId();

        if (empty($webhookId)) {
            $this->logger->error('PayPal webhook id is not configured, cannot verify webhook signature');

            throw new PayPalApiException(__('PayPal webhook id is not configured.'));
        }

        $verified = $this->payPalClient->verifyWebhookSignature(
            $webhookDTO->headers,
            $webhookDTO->payload,
            $webhookId
        );

        if (! $verified) {
            $this->logger->error('Unable to verify PayPal webhook signature');

            throw new PayPalApiException(__('Unable to verify PayPal webhook signature.'));
        }

        $event = json_decode($webhookDTO->payload, true, 512, JSON_THROW_ON_ERROR);

        $eventId = $event['id'] ?? null;
        $eventType = $event['event_type'] ?? null;

        if (! in_array($eventType, self::$validEvents, true)) {
            $this->logger->debug('Received a PayPal event which has no handler', [
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            return;
        }

        if ($eventId && $this->cache->has('paypal_event_'.$eventId)) {
            $this->logger->debug('PayPal event already handled', [
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            return;
        }

        $orderId = $this->resolveOrderId($event);

        if ($orderId === null) {
            $this->logger->error('Unable to resolve order for PayPal webhook', [
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            return;
        }

        $this->payPalOrderCompletionService->completeOrder($orderId);

        if ($eventId) {
            $this->cache->put('paypal_event_'.$eventId, true, now()->addMinutes(60));
        }
    }

    private function resolveOrderId(array $event): ?int
    {
        $resource = $event['resource'] ?? [];

        $publicId = $resource['custom_id']
            ?? $resource['invoice_id']
            ?? ($resource['purchase_units'][0]['custom_id'] ?? null)
            ?? ($resource['purchase_units'][0]['invoice_id'] ?? null);

        if (is_string($publicId) && $publicId !== '') {
            $order = $this->orderRepository->findFirstWhere([
                OrderDomainObjectAbstract::PUBLIC_ID => $publicId,
            ]);

            if ($order) {
                return $order->getId();
            }
        }

        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id']
            ?? ($resource['id'] ?? null);

        if (is_string($paypalOrderId) && $paypalOrderId !== '') {
            /** @var PaypalPaymentDomainObject|null $payPalPayment */
            $payPalPayment = $this->payPalPaymentsRepository->findFirstWhere([
                PaypalPaymentDomainObjectAbstract::PAYPAL_ORDER_ID => $paypalOrderId,
            ]);

            if ($payPalPayment) {
                return $payPalPayment->getOrderId();
            }
        }

        return null;
    }
}

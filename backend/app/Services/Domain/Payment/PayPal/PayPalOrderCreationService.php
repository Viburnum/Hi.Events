<?php

namespace HiEvents\Services\Domain\Payment\PayPal;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\PayPal\PayPalApiException;
use HiEvents\Services\Domain\Payment\PayPal\DTOs\PayPalOrderCreationResultDTO;
use HiEvents\Services\Infrastructure\PayPal\PayPalClient;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

class PayPalOrderCreationService
{
    public function __construct(
        private readonly PayPalClient $payPalClient,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws PayPalApiException
     * @throws MathException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    public function createOrder(OrderDomainObject $order): PayPalOrderCreationResultDTO
    {
        $currency = strtoupper($order->getCurrency());
        // PayPal expects amount.value as a bare major-unit decimal string (e.g. "60.00"),
        // NOT minor units and NOT currency-prefixed. Assumes a 2-decimal currency (EUR/USD).
        $value = number_format(
            MoneyValue::fromFloat($order->getTotalGross(), $order->getCurrency())->toFloat(),
            2,
            '.',
            ''
        );

        $returnUrl = $this->buildFrontendUrl($order, 'payment_return');
        $cancelUrl = $this->buildFrontendUrl($order, 'payment');

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $order->getPublicId(),
                    'custom_id' => $order->getPublicId(),
                    'invoice_id' => $order->getPublicId(),
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => $value,
                    ],
                ],
            ],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ],
            ],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ];

        $response = $this->payPalClient->createOrder($payload);

        $paypalOrderId = $response['id'] ?? null;

        if (! is_string($paypalOrderId) || $paypalOrderId === '') {
            $this->logger->error('PayPal did not return an order id', [
                'order_id' => $order->getId(),
                'response' => $response,
            ]);

            throw new PayPalApiException(__('PayPal did not return an order id.'));
        }

        return new PayPalOrderCreationResultDTO(
            paypalOrderId: $paypalOrderId,
            status: $response['status'] ?? 'CREATED',
        );
    }

    private function buildFrontendUrl(OrderDomainObject $order, string $page): string
    {
        $frontendUrl = rtrim((string) $this->config->get('app.frontend_url'), '/');

        return sprintf(
            '%s/checkout/%d/%s/%s',
            $frontendUrl,
            $order->getEventId(),
            $order->getShortId(),
            $page,
        );
    }
}

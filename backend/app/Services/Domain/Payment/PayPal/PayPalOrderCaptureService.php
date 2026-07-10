<?php

namespace HiEvents\Services\Domain\Payment\PayPal;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use HiEvents\Exceptions\PayPal\PayPalApiException;
use HiEvents\Services\Domain\Payment\PayPal\DTOs\CapturePayPalOrderResponseDTO;
use HiEvents\Services\Infrastructure\PayPal\PayPalClient;
use HiEvents\Values\MoneyValue;
use Psr\Log\LoggerInterface;

class PayPalOrderCaptureService
{
    public function __construct(
        private readonly PayPalClient $payPalClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws PayPalApiException
     * @throws MathException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    public function captureOrder(string $paypalOrderId): CapturePayPalOrderResponseDTO
    {
        $response = $this->payPalClient->captureOrder($paypalOrderId);

        $capture = $response['purchase_units'][0]['payments']['captures'][0] ?? [];

        $captureId = $capture['id'] ?? null;
        $status = $capture['status'] ?? ($response['status'] ?? null);

        $amountValue = $capture['amount']['value'] ?? null;
        $currencyCode = $capture['amount']['currency_code'] ?? null;

        $amountReceived = null;
        if ($amountValue !== null && $currencyCode !== null) {
            $amountReceived = MoneyValue::fromFloat((float) $amountValue, $currencyCode)->toMinorUnit();
        }

        $this->logger->info('PayPal order captured', [
            'paypal_order_id' => $paypalOrderId,
            'capture_id' => $captureId,
            'status' => $status,
        ]);

        return new CapturePayPalOrderResponseDTO(
            captureId: $captureId,
            status: $status,
            amountReceived: $amountReceived,
        );
    }
}

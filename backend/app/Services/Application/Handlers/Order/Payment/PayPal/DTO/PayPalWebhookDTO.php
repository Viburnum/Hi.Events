<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\PayPal\DTO;

use HiEvents\DataTransferObjects\BaseDTO;

class PayPalWebhookDTO extends BaseDTO
{
    public function __construct(
        public readonly array $headers,
        public readonly string $payload,
    ) {}
}

<?php

namespace HiEvents\Services\Domain\Payment\PayPal\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;

class PayPalOrderCreationResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $paypalOrderId,
        public readonly string $status,
    ) {}
}

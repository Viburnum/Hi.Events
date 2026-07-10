<?php

namespace HiEvents\Services\Domain\Payment\PayPal\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;

class CapturePayPalOrderResponseDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $captureId = null,
        public readonly ?string $status = null,
        public readonly ?int $amountReceived = null,
    ) {}
}

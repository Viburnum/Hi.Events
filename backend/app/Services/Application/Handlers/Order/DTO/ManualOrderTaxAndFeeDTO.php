<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ManualOrderTaxAndFeeDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $tax_or_fee_id,
        public readonly float $amount,
    ) {}
}

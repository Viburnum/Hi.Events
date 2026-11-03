<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class ManualOrderAttendeeDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $product_id,
        public readonly string $first_name,
        public readonly float $amount_paid,
        public readonly ?int $product_price_id = null,
        public readonly ?string $last_name = null,
        public readonly ?string $email = null,
        #[DataCollectionOf(ManualOrderTaxAndFeeDTO::class)]
        public readonly ?Collection $taxes_and_fees = null,
    ) {}
}

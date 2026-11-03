<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ManualOrderAddressDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?string $address_line_1 = null,
        public readonly ?string $address_line_2 = null,
        public readonly ?string $city = null,
        public readonly ?string $state_or_region = null,
        public readonly ?string $zip_or_postal_code = null,
        public readonly ?string $country = null,
    ) {}
}

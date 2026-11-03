<?php

namespace HiEvents\Services\Application\Handlers\Order\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class CreateManualOrderDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $event_id,
        public readonly string $first_name,
        public readonly string $email,
        public readonly string $locale,
        public readonly bool $send_confirmation_email,
        #[DataCollectionOf(ManualOrderAttendeeDTO::class)]
        public readonly Collection $attendees,
        public readonly ?string $last_name = null,
        public readonly ?ManualOrderAddressDTO $address = null,
        public readonly ?int $event_occurrence_id = null,
        public readonly bool $override_capacity = false,
        public readonly ?string $client_ip = null,
        public readonly ?string $client_user_agent = null,
    ) {}
}

<?php

namespace HiEvents\Services\Domain\Wallet\DTO;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;

class WalletPassContextDTO
{
    public function __construct(
        public readonly AttendeeDomainObject $attendee,
        public readonly EventDomainObject $event,
    ) {}
}

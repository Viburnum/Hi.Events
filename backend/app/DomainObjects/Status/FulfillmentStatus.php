<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum FulfillmentStatus
{
    use BaseEnum;

    case PENDING;
    case FULFILLED;

    public static function getHumanReadableStatus(string $status): string
    {
        return match ($status) {
            self::PENDING->name => __('Pending'),
            self::FULFILLED->name => __('Fulfilled'),
        };
    }
}

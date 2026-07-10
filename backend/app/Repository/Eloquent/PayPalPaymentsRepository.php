<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\PaypalPaymentDomainObject;
use HiEvents\Models\PayPalPayment;
use HiEvents\Repository\Interfaces\PayPalPaymentsRepositoryInterface;

/**
 * @extends BaseRepository<PaypalPaymentDomainObject>
 */
class PayPalPaymentsRepository extends BaseRepository implements PayPalPaymentsRepositoryInterface
{
    protected function getModel(): string
    {
        return PayPalPayment::class;
    }

    public function getDomainObject(): string
    {
        return PaypalPaymentDomainObject::class;
    }
}

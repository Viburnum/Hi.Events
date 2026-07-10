<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayPalPayment extends BaseModel
{
    use SoftDeletes;

    protected $table = 'paypal_payments';

    protected function getTimestampsEnabled(): bool
    {
        return true;
    }

    protected function getCastMap(): array
    {
        return [
            'last_error' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

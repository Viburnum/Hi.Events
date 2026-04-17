<?php

namespace HiEvents\Http\Request\Fulfillment;

use HiEvents\Http\Request\BaseRequest;

class SetAttendeeBarcodeRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'barcode' => 'required|string|max:255',
        ];
    }
}

<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Locale;
use HiEvents\Validators\Rules\RulesHelper;
use Illuminate\Validation\Rule;

class CreateManualOrderRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:40'],
            'last_name' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'email'],
            'locale' => ['required', Rule::in(Locale::getSupportedLocales())],
            'send_confirmation_email' => ['required', 'boolean'],

            'address' => ['nullable', 'array'],
            'address.address_line_1' => ['nullable', 'string', 'max:255'],
            'address.address_line_2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:85'],
            'address.state_or_region' => ['nullable', 'string', 'max:85'],
            'address.zip_or_postal_code' => ['nullable', 'string', 'max:20'],
            'address.country' => ['nullable', 'string', 'max:2'],

            'attendees' => ['required', 'array', 'min:1', 'max:100'],
            'attendees.*.product_id' => ['required', 'integer'],
            'attendees.*.product_price_id' => ['nullable', 'integer'],
            'attendees.*.first_name' => ['required', 'string', 'max:40'],
            'attendees.*.last_name' => ['nullable', 'string', 'max:40'],
            'attendees.*.email' => ['nullable', 'email'],
            'attendees.*.amount_paid' => ['required', ...RulesHelper::MONEY],
            'attendees.*.taxes_and_fees' => ['nullable', 'array'],
            'attendees.*.taxes_and_fees.*.tax_or_fee_id' => ['required', 'integer'],
            'attendees.*.taxes_and_fees.*.amount' => ['required', ...RulesHelper::MONEY],
        ];
    }

    public function messages(): array
    {
        return [
            'attendees.required' => __('At least one attendee is required'),
            'attendees.min' => __('At least one attendee is required'),
            'attendees.max' => __('An order can have at most 100 attendees'),
            'attendees.*.product_id.required' => __('Please select a ticket'),
            'attendees.*.first_name.required' => __('First name is required'),
        ];
    }
}

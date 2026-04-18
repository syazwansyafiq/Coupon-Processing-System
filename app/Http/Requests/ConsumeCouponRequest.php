<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsumeCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coupon_code' => ['required', 'string', 'max:64'],
            'cart_id' => ['required', 'string', 'max:128'],
            'order_id' => ['required', 'string', 'max:128'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'setting_version' => ['required', 'integer'],
            'request_id' => ['required', 'string'],
            'cart_value' => ['required', 'numeric', 'min:0'],
        ];
    }
}

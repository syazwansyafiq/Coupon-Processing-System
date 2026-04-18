<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseCouponRequest extends FormRequest
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
            'cart_value' => ['required', 'numeric', 'min:0'],
            'request_id' => ['required', 'string'],
            'reason' => ['sometimes', 'string', 'max:128'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyCouponRequest extends FormRequest
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
            'item_categories' => ['sometimes', 'array'],
            'item_categories.*' => ['string'],
            'product_ids' => ['sometimes', 'array'],
            'product_ids.*' => ['integer'],
            'user_segments' => ['sometimes', 'array'],
            'user_segments.*' => ['string'],
        ];
    }
}

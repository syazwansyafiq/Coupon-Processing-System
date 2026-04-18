<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponStatusResource extends JsonResource
{
    /**
     * @param array $resource  Status payload stored in Redis.
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'message' => $this->resource['message'] ?? null,
            'coupon_code' => $this->resource['coupon_code'] ?? null,
            'discount_amount' => $this->resource['discount_amount'] ?? null,
            'setting_version' => $this->resource['setting_version'] ?? null,
            'expires_in_seconds' => $this->resource['expires_in_seconds'] ?? null,
            'order_id' => $this->resource['order_id'] ?? null,
            'reason' => $this->resource['reason'] ?? null,
        ];
    }
}

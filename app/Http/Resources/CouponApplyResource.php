<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponApplyResource extends JsonResource
{
    public function __construct(
        private readonly string $requestId,
        private readonly string $status,
        private readonly string $message,
    ) {
        parent::__construct(null);
    }

    public function toArray(Request $request): array
    {
        return [
            'request_id' => $this->requestId,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}

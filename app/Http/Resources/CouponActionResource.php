<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponActionResource extends JsonResource
{
    public function __construct(private readonly string $message)
    {
        parent::__construct(null);
    }

    public function toArray(Request $request): array
    {
        return [
            'message' => $this->message,
        ];
    }
}

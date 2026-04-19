<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TokenResource extends JsonResource
{
    public function __construct(
        private readonly string $token,
        private readonly string $name,
        private readonly string $email,
    ) {
        parent::__construct(null);
        static::withoutWrapping();
    }

    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'type' => 'Bearer',
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}

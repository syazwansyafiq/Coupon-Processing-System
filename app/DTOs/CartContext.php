<?php

namespace App\DTOs;

readonly class CartContext
{
    public function __construct(
        public string $cartId,
        public int $userId,
        public float $cartValue,
        public array $itemCategories = [],
        public array $productIds = [],
        public bool $isFirstOrder = false,
        public array $userSegments = [],
        public ?string $orderId = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            cartId: $data['cart_id'],
            userId: $data['user_id'],
            cartValue: (float) $data['cart_value'],
            itemCategories: $data['item_categories'] ?? [],
            productIds: $data['product_ids'] ?? [],
            isFirstOrder: (bool) ($data['is_first_order'] ?? false),
            userSegments: $data['user_segments'] ?? [],
            orderId: $data['order_id'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'cart_id' => $this->cartId,
            'user_id' => $this->userId,
            'cart_value' => $this->cartValue,
            'item_categories' => $this->itemCategories,
            'product_ids' => $this->productIds,
            'is_first_order' => $this->isFirstOrder,
            'user_segments' => $this->userSegments,
            'order_id' => $this->orderId,
        ];
    }
}

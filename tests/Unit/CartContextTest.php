<?php

namespace Tests\Unit;

use App\DTOs\CartContext;
use PHPUnit\Framework\TestCase;

class CartContextTest extends TestCase
{
    public function test_from_array_maps_all_fields(): void
    {
        $cart = CartContext::fromArray([
            'cart_id'         => 'cart-1',
            'user_id'         => 42,
            'cart_value'      => '150.50',
            'item_categories' => ['electronics'],
            'product_ids'     => [101, 202],
            'is_first_order'  => true,
            'user_segments'   => ['vip'],
            'order_id'        => 'order-99',
        ]);

        $this->assertSame('cart-1', $cart->cartId);
        $this->assertSame(42, $cart->userId);
        $this->assertSame(150.50, $cart->cartValue);
        $this->assertSame(['electronics'], $cart->itemCategories);
        $this->assertSame([101, 202], $cart->productIds);
        $this->assertTrue($cart->isFirstOrder);
        $this->assertSame(['vip'], $cart->userSegments);
        $this->assertSame('order-99', $cart->orderId);
    }

    public function test_from_array_applies_defaults_for_optional_fields(): void
    {
        $cart = CartContext::fromArray([
            'cart_id'    => 'cart-2',
            'user_id'    => 1,
            'cart_value' => 50,
        ]);

        $this->assertSame([], $cart->itemCategories);
        $this->assertSame([], $cart->productIds);
        $this->assertFalse($cart->isFirstOrder);
        $this->assertSame([], $cart->userSegments);
        $this->assertNull($cart->orderId);
    }

    public function test_cart_value_is_cast_to_float(): void
    {
        $cart = CartContext::fromArray(['cart_id' => 'c', 'user_id' => 1, 'cart_value' => '99']);

        $this->assertIsFloat($cart->cartValue);
        $this->assertSame(99.0, $cart->cartValue);
    }

    public function test_to_array_round_trips(): void
    {
        $data = [
            'cart_id'         => 'cart-3',
            'user_id'         => 5,
            'cart_value'      => 200.0,
            'item_categories' => ['clothing'],
            'product_ids'     => [10],
            'is_first_order'  => false,
            'user_segments'   => ['premium'],
            'order_id'        => null,
        ];

        $this->assertSame($data, CartContext::fromArray($data)->toArray());
    }
}

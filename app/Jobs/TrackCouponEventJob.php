<?php

namespace App\Jobs;

use App\DTOs\CartContext;
use App\Models\Coupon;
use App\Models\CouponEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackCouponEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 15;
    public int $backoff = 10;

    public function __construct(
        public readonly string $eventType,
        public readonly string $couponCode,
        public readonly CartContext $cart,
        public readonly string $idempotencyKey,
        public readonly array $extra = [],
        public readonly ?int $ruleVersion = null,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $coupon = Coupon::where('code', $this->couponCode)->first();

        CouponEvent::create([
            'event_type' => $this->eventType,
            'coupon_id' => $coupon?->id,
            'user_id' => $this->cart->userId,
            'cart_id' => $this->cart->cartId,
            'order_id' => $this->cart->orderId ?? $this->extra['order_id'] ?? null,
            'idempotency_key' => $this->idempotencyKey,
            'rule_version' => $this->ruleVersion,
            'payload' => array_merge(
                ['cart_context' => $this->cart->toArray()],
                $this->extra,
            ),
        ]);
    }
}

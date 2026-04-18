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
use Illuminate\Support\Facades\Log;

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
        Log::info('coupon.job.track_event.start', [
            'event_type'      => $this->eventType,
            'coupon_code'     => $this->couponCode,
            'user_id'         => $this->cart->userId,
            'idempotency_key' => $this->idempotencyKey,
            'rule_version'    => $this->ruleVersion,
        ]);

        $coupon = Coupon::where('code', $this->couponCode)->first();

        Log::info('coupon.job.track_event.db.write', [
            'event_type'  => $this->eventType,
            'coupon_code' => $this->couponCode,
            'coupon_id'   => $coupon?->id,
            'user_id'     => $this->cart->userId,
            'cart_id'     => $this->cart->cartId,
        ]);

        CouponEvent::create([
            'event_type'      => $this->eventType,
            'coupon_id'       => $coupon?->id,
            'user_id'         => $this->cart->userId,
            'cart_id'         => $this->cart->cartId,
            'order_id'        => $this->cart->orderId ?? $this->extra['order_id'] ?? null,
            'idempotency_key' => $this->idempotencyKey,
            'rule_version'    => $this->ruleVersion,
            'payload'         => array_merge(
                ['cart_context' => $this->cart->toArray()],
                $this->extra,
            ),
        ]);

        Log::info('coupon.job.track_event.complete', [
            'event_type'      => $this->eventType,
            'coupon_code'     => $this->couponCode,
            'user_id'         => $this->cart->userId,
            'idempotency_key' => $this->idempotencyKey,
        ]);
    }
}

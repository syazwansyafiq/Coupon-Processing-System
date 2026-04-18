<?php

namespace App\Jobs;

use App\DTOs\CartContext;
use App\Enums\CouponStatus;
use App\Messages\CouponMessage;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Services\CouponReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ConsumeCouponJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;
    public int $backoff = 10;

    public function __construct(
        public readonly string $couponCode,
        public readonly CartContext $cart,
        public readonly string $orderId,
        public readonly float $discountAmount,
        public readonly int $settingVersion,
        public readonly string $idempotencyKey,
    ) {
        $this->onQueue('default');
    }

    public function handle(CouponReservationService $reservationService): void
    {
        $coupon = Coupon::where('code', $this->couponCode)->firstOrFail();

        // Idempotency: already consumed for this order — nothing to do
        $alreadyConsumed = CouponUsage::where('coupon_id', $coupon->id)
            ->where('order_id', $this->orderId)
            ->exists();

        if ($alreadyConsumed) {
            return;
        }

        DB::transaction(function () use ($coupon, $reservationService) {
            CouponUsage::create([
                'coupon_id' => $coupon->id,
                'user_id' => $this->cart->userId,
                'order_id' => $this->orderId,
                'setting_version' => $this->settingVersion,
                'discount_applied' => $this->discountAmount,
                'consumed_at' => now(),
            ]);

            // Release Redis reservation after permanent MySQL record is written
            $reservationService->release($this->couponCode, $this->cart->userId);
        });

        TrackCouponEventJob::dispatch(
            eventType: 'consumed',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
            extra: [
                'order_id' => $this->orderId,
                'discount_amount' => $this->discountAmount,
                'setting_version' => $this->settingVersion,
            ],
            ruleVersion: $this->settingVersion,
        );

        $reservationService->setStatus($this->idempotencyKey, [
            'status' => CouponStatus::Consumed->value,
            'message' => CouponMessage::CONSUMED_SUCCESSFULLY,
            'order_id' => $this->orderId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        TrackCouponEventJob::dispatch(
            eventType: 'validation_failed',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
            extra: [
                'phase' => 'consumption',
                'order_id' => $this->orderId,
                'error' => $exception->getMessage(),
            ],
        );
    }
}

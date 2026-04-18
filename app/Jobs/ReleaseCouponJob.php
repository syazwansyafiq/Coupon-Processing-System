<?php

namespace App\Jobs;

use App\DTOs\CartContext;
use App\Enums\CouponStatus;
use App\Messages\CouponMessage;
use App\Services\CouponReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReleaseCouponJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 15;
    public int $backoff = 5;

    public function __construct(
        public readonly string $couponCode,
        public readonly CartContext $cart,
        public readonly string $idempotencyKey,
        public readonly string $reason = 'checkout_failed',
    ) {
        $this->onQueue('default');
    }

    public function handle(CouponReservationService $reservationService): void
    {
        $reservationService->release($this->couponCode, $this->cart->userId);

        TrackCouponEventJob::dispatch(
            eventType: 'released',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
            extra: ['reason' => $this->reason],
        );

        $reservationService->setStatus($this->idempotencyKey, [
            'status' => CouponStatus::Released->value,
            'message' => CouponMessage::RESERVATION_RELEASED,
            'reason' => $this->reason,
        ]);
    }
}

<?php

namespace App\Jobs;

use App\DTOs\CartContext;
use App\Enums\CouponStatus;
use App\Messages\CouponMessage;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Services\CouponReservationService;
use App\Services\CouponRuleEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ValidateCouponJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public int $backoff = 5;

    public function __construct(
        public readonly string $couponCode,
        public readonly CartContext $cart,
        public readonly string $idempotencyKey,
    ) {
        $this->onQueue('high');
    }

    /**
     * Unique key prevents duplicate validation jobs for the same cart+coupon combination.
     */
    public function uniqueId(): string
    {
        return $this->idempotencyKey;
    }

    public function handle(CouponRuleEngine $ruleEngine, CouponReservationService $reservationService): void
    {
        // Mark as processing
        $reservationService->setStatus($this->idempotencyKey, [
            'status' => CouponStatus::Processing->value,
            'message' => CouponMessage::VALIDATING,
        ]);

        // Dispatch analytics event for "applied" (coupon was submitted)
        TrackCouponEventJob::dispatch(
            eventType: 'applied',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
        );

        $result = $ruleEngine->validate($this->couponCode, $this->cart);

        if (! $result->isValid) {
            $reservationService->setStatus($this->idempotencyKey, [
                'status' => CouponStatus::Failed->value,
                'message' => $result->failureReason,
                'coupon_code' => $this->couponCode,
            ]);

            TrackCouponEventJob::dispatch(
                eventType: 'validation_failed',
                couponCode: $this->couponCode,
                cart: $this->cart,
                idempotencyKey: $this->idempotencyKey,
                extra: [
                    'failure_reason' => $result->failureReason,
                    'rule_snapshot' => $result->ruleSnapshot,
                ],
                ruleVersion: $result->settingVersion,
            );

            return;
        }

        // Reserve atomically in Redis
        $coupon = Coupon::where('code', $this->couponCode)->firstOrFail();
        $currentGlobalUsage = CouponUsage::where('coupon_id', $coupon->id)->count();

        $reserved = $reservationService->reserve(
            couponCode: $this->couponCode,
            userId: $this->cart->userId,
            idempotencyKey: $this->idempotencyKey,
            globalUsageLimit: $coupon->latestActiveSetting()->global_usage_limit ?? 0,
            currentGlobalUsage: $currentGlobalUsage,
        );

        if (! $reserved) {
            $reservationService->setStatus($this->idempotencyKey, [
                'status' => CouponStatus::Failed->value,
                'message' => 'global_limit_reached',
                'coupon_code' => $this->couponCode,
            ]);

            TrackCouponEventJob::dispatch(
                eventType: 'validation_failed',
                couponCode: $this->couponCode,
                cart: $this->cart,
                idempotencyKey: $this->idempotencyKey,
                extra: ['failure_reason' => 'global_limit_reached_at_reservation'],
                ruleVersion: $result->settingVersion,
            );

            return;
        }

        TrackCouponEventJob::dispatch(
            eventType: 'validation_passed',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
            extra: [
                'discount_amount' => $result->discountAmount,
                'rule_snapshot' => $result->ruleSnapshot,
            ],
            ruleVersion: $result->settingVersion,
        );

        TrackCouponEventJob::dispatch(
            eventType: 'reserved',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
            extra: [
                'discount_amount' => $result->discountAmount,
                'expires_in_seconds' => 300,
                'rule_snapshot' => $result->ruleSnapshot,
            ],
            ruleVersion: $result->settingVersion,
        );

        $reservationService->setStatus($this->idempotencyKey, [
            'status' => CouponStatus::Reserved->value,
            'message' => CouponMessage::RESERVED_SUCCESSFULLY,
            'coupon_code' => $this->couponCode,
            'discount_amount' => $result->discountAmount,
            'setting_version' => $result->settingVersion,
            'expires_in_seconds' => 300,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $reservationService = app(CouponReservationService::class);

        $reservationService->setStatus($this->idempotencyKey, [
            'status' => CouponStatus::Error->value,
            'message' => CouponMessage::SYSTEM_ERROR,
        ]);

        TrackCouponEventJob::dispatch(
            eventType: 'validation_failed',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
            extra: ['error' => $exception->getMessage()],
        );
    }
}

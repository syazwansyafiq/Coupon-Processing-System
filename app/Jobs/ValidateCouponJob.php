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
use Illuminate\Support\Facades\Log;

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

    public function uniqueId(): string
    {
        return $this->idempotencyKey;
    }

    public function handle(CouponRuleEngine $ruleEngine, CouponReservationService $reservationService): void
    {
        Log::info('coupon.job.validate.start', [
            'coupon_code'     => $this->couponCode,
            'user_id'         => $this->cart->userId,
            'cart_id'         => $this->cart->cartId,
            'idempotency_key' => $this->idempotencyKey,
        ]);

        $reservationService->setStatus($this->idempotencyKey, [
            'status'  => CouponStatus::Processing->value,
            'message' => CouponMessage::VALIDATING,
        ]);

        TrackCouponEventJob::dispatch(
            eventType: 'applied',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
        );

        $result = $ruleEngine->validate($this->couponCode, $this->cart);

        if (! $result->isValid) {
            Log::warning('coupon.job.validate.invalid', [
                'coupon_code'     => $this->couponCode,
                'user_id'         => $this->cart->userId,
                'idempotency_key' => $this->idempotencyKey,
                'failure_reason'  => $result->failureReason,
            ]);

            $reservationService->setStatus($this->idempotencyKey, [
                'status'      => CouponStatus::Failed->value,
                'message'     => $result->failureReason,
                'coupon_code' => $this->couponCode,
            ]);

            TrackCouponEventJob::dispatch(
                eventType: 'validation_failed',
                couponCode: $this->couponCode,
                cart: $this->cart,
                idempotencyKey: $this->idempotencyKey,
                extra: [
                    'failure_reason' => $result->failureReason,
                    'rule_snapshot'  => $result->ruleSnapshot,
                ],
                ruleVersion: $result->settingVersion,
            );

            return;
        }

        $coupon = Coupon::where('code', $this->couponCode)->firstOrFail();

        $currentGlobalUsage = CouponUsage::where('coupon_id', $coupon->id)->count();

        Log::info('coupon.job.validate.db.usage_count', [
            'coupon_code'          => $this->couponCode,
            'coupon_id'            => $coupon->id,
            'current_global_usage' => $currentGlobalUsage,
        ]);

        $reserved = $reservationService->reserve(
            couponCode: $this->couponCode,
            userId: $this->cart->userId,
            idempotencyKey: $this->idempotencyKey,
            globalUsageLimit: $coupon->latestActiveSetting()->global_usage_limit ?? 0,
            currentGlobalUsage: $currentGlobalUsage,
        );

        if (! $reserved) {
            Log::warning('coupon.job.validate.reservation_failed', [
                'coupon_code'     => $this->couponCode,
                'user_id'         => $this->cart->userId,
                'idempotency_key' => $this->idempotencyKey,
                'reason'          => 'global_limit_reached_at_reservation',
            ]);

            $reservationService->setStatus($this->idempotencyKey, [
                'status'      => CouponStatus::Failed->value,
                'message'     => 'global_limit_reached',
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
                'rule_snapshot'   => $result->ruleSnapshot,
            ],
            ruleVersion: $result->settingVersion,
        );

        TrackCouponEventJob::dispatch(
            eventType: 'reserved',
            couponCode: $this->couponCode,
            cart: $this->cart,
            idempotencyKey: $this->idempotencyKey,
            extra: [
                'discount_amount'  => $result->discountAmount,
                'expires_in_seconds' => 300,
                'rule_snapshot'    => $result->ruleSnapshot,
            ],
            ruleVersion: $result->settingVersion,
        );

        $reservationService->setStatus($this->idempotencyKey, [
            'status'           => CouponStatus::Reserved->value,
            'message'          => CouponMessage::RESERVED_SUCCESSFULLY,
            'coupon_code'      => $this->couponCode,
            'discount_amount'  => $result->discountAmount,
            'setting_version'  => $result->settingVersion,
            'expires_in_seconds' => 300,
        ]);

        Log::info('coupon.job.validate.complete', [
            'coupon_code'     => $this->couponCode,
            'user_id'         => $this->cart->userId,
            'idempotency_key' => $this->idempotencyKey,
            'discount_amount' => $result->discountAmount,
            'setting_version' => $result->settingVersion,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('coupon.job.validate.failed', [
            'coupon_code'     => $this->couponCode,
            'user_id'         => $this->cart->userId,
            'idempotency_key' => $this->idempotencyKey,
            'error'           => $exception->getMessage(),
            'trace'           => $exception->getTraceAsString(),
        ]);

        $reservationService = app(CouponReservationService::class);

        $reservationService->setStatus($this->idempotencyKey, [
            'status'  => CouponStatus::Error->value,
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

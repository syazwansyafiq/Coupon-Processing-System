<?php

namespace App\Services;

use App\DTOs\CartContext;
use App\DTOs\CouponValidationResult;
use App\Models\Coupon;
use App\Models\CouponSetting;
use App\Models\CouponUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CouponRuleEngine
{
    public function validate(string $couponCode, CartContext $cart): CouponValidationResult
    {
        Log::info('coupon.rule_engine.validate.start', [
            'coupon_code' => $couponCode,
            'user_id'     => $cart->userId,
            'cart_id'     => $cart->cartId,
            'cart_value'  => $cart->cartValue,
        ]);

        $coupon = Coupon::where('code', $couponCode)->first();

        Log::info('coupon.rule_engine.db.coupon_lookup', [
            'coupon_code' => $couponCode,
            'found'       => $coupon !== null,
            'coupon_id'   => $coupon?->id,
        ]);

        if (! $coupon) {
            Log::warning('coupon.rule_engine.validate.failed', [
                'coupon_code' => $couponCode,
                'user_id'     => $cart->userId,
                'reason'      => 'coupon_not_found',
            ]);

            return CouponValidationResult::fail('coupon_not_found');
        }

        if (! $coupon->isCurrentlyValid()) {
            Log::warning('coupon.rule_engine.validate.failed', [
                'coupon_code' => $couponCode,
                'user_id'     => $cart->userId,
                'reason'      => 'coupon_inactive_or_expired',
                'is_active'   => $coupon->is_active,
                'valid_from'  => $coupon->valid_from,
                'valid_until' => $coupon->valid_until,
            ]);

            return CouponValidationResult::fail('coupon_inactive_or_expired');
        }

        $setting = $coupon->latestActiveSetting();

        Log::info('coupon.rule_engine.db.settings_lookup', [
            'coupon_code'     => $couponCode,
            'setting_found'   => $setting !== null,
            'setting_version' => $setting?->version,
        ]);

        if (! $setting) {
            Log::warning('coupon.rule_engine.validate.failed', [
                'coupon_code' => $couponCode,
                'user_id'     => $cart->userId,
                'reason'      => 'no_active_settings',
            ]);

            return CouponValidationResult::fail('no_active_settings');
        }

        if ($failed = $this->checkUsageLimits($coupon, $setting, $cart)) {
            Log::warning('coupon.rule_engine.validate.failed', [
                'coupon_code'     => $couponCode,
                'user_id'         => $cart->userId,
                'reason'          => $failed,
                'setting_version' => $setting->version,
            ]);

            return CouponValidationResult::fail($failed);
        }

        if ($failed = $this->checkCartRules($setting, $cart)) {
            Log::warning('coupon.rule_engine.validate.failed', [
                'coupon_code'     => $couponCode,
                'user_id'         => $cart->userId,
                'reason'          => $failed,
                'setting_version' => $setting->version,
            ]);

            return CouponValidationResult::fail($failed);
        }

        $discount = $this->calculateDiscount($coupon, $cart->cartValue);

        Log::info('coupon.rule_engine.validate.passed', [
            'coupon_code'     => $couponCode,
            'user_id'         => $cart->userId,
            'setting_version' => $setting->version,
            'discount_amount' => $discount,
            'coupon_type'     => $coupon->type,
        ]);

        return CouponValidationResult::pass(
            discountAmount: $discount,
            couponId: $coupon->id,
            settingVersion: $setting->version,
            ruleSnapshot: $setting->toRuleSnapshot(),
        );
    }

    private function checkUsageLimits(Coupon $coupon, CouponSetting $setting, CartContext $cart): ?string
    {
        if ($setting->global_usage_limit !== null) {
            $globalUsed = CouponUsage::where('coupon_id', $coupon->id)->count();

            Log::info('coupon.rule_engine.db.global_usage_count', [
                'coupon_code'        => $coupon->code,
                'global_used'        => $globalUsed,
                'global_usage_limit' => $setting->global_usage_limit,
            ]);

            if ($globalUsed >= $setting->global_usage_limit) {
                return 'global_limit_reached';
            }
        }

        if ($setting->per_user_limit !== null) {
            $userUsed = CouponUsage::where('coupon_id', $coupon->id)
                ->where('user_id', $cart->userId)
                ->count();

            Log::info('coupon.rule_engine.db.user_usage_count', [
                'coupon_code'    => $coupon->code,
                'user_id'        => $cart->userId,
                'user_used'      => $userUsed,
                'per_user_limit' => $setting->per_user_limit,
            ]);

            if ($userUsed >= $setting->per_user_limit) {
                return 'user_limit_reached';
            }
        }

        return null;
    }

    private function checkCartRules(CouponSetting $setting, CartContext $cart): ?string
    {
        if ($setting->min_cart_value !== null && $cart->cartValue < $setting->min_cart_value) {
            return 'cart_value_too_low';
        }

        $rules = $setting->rules ?? [];

        if (! empty($rules['first_time_user']) && ! $cart->isFirstOrder) {
            return 'not_first_time_user';
        }

        if (! empty($rules['categories'])) {
            $required = $rules['categories'];
            $intersection = array_intersect($required, $cart->itemCategories);
            if (empty($intersection)) {
                return 'required_categories_missing';
            }
        }

        if (! empty($rules['product_ids'])) {
            $required = $rules['product_ids'];
            $intersection = array_intersect($required, $cart->productIds);
            if (empty($intersection)) {
                return 'required_products_missing';
            }
        }

        if (! empty($rules['time_window'])) {
            if ($failed = $this->checkTimeWindow($rules['time_window'])) {
                return $failed;
            }
        }

        if (! empty($rules['user_segments'])) {
            $required = $rules['user_segments'];
            $intersection = array_intersect($required, $cart->userSegments);
            if (empty($intersection)) {
                return 'user_segment_not_eligible';
            }
        }

        return null;
    }

    private function checkTimeWindow(array $window): ?string
    {
        $now = Carbon::now();
        $start = Carbon::createFromTimeString($window['start'] ?? '00:00');
        $end = Carbon::createFromTimeString($window['end'] ?? '23:59');

        if ($now->lt($start) || $now->gt($end)) {
            return 'outside_valid_time_window';
        }

        return null;
    }

    private function calculateDiscount(Coupon $coupon, float $cartValue): float
    {
        if ($coupon->type === 'percentage') {
            return round($cartValue * ((float) $coupon->value / 100), 2);
        }

        return min((float) $coupon->value, $cartValue);
    }
}

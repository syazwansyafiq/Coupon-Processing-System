<?php

namespace App\Services;

use App\DTOs\CartContext;
use App\DTOs\CouponValidationResult;
use App\Models\Coupon;
use App\Models\CouponSetting;
use App\Models\CouponUsage;
use Carbon\Carbon;

class CouponRuleEngine
{
    /**
     * Validate a coupon against the latest active settings and the given cart context.
     * Always loads fresh settings from DB — never from cache — so rule changes are immediate.
     */
    public function validate(string $couponCode, CartContext $cart): CouponValidationResult
    {
        $coupon = Coupon::where('code', $couponCode)->first();

        if (! $coupon) {
            return CouponValidationResult::fail('coupon_not_found');
        }

        if (! $coupon->isCurrentlyValid()) {
            return CouponValidationResult::fail('coupon_inactive_or_expired');
        }

        $setting = $coupon->latestActiveSetting();

        if (! $setting) {
            return CouponValidationResult::fail('no_active_settings');
        }

        if ($failed = $this->checkUsageLimits($coupon, $setting, $cart)) {
            return CouponValidationResult::fail($failed);
        }

        if ($failed = $this->checkCartRules($setting, $cart)) {
            return CouponValidationResult::fail($failed);
        }

        $discount = $this->calculateDiscount($coupon, $cart->cartValue);

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
            if ($globalUsed >= $setting->global_usage_limit) {
                return 'global_limit_reached';
            }
        }

        if ($setting->per_user_limit !== null) {
            $userUsed = CouponUsage::where('coupon_id', $coupon->id)
                ->where('user_id', $cart->userId)
                ->count();
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

        // Fixed discount — never exceed cart value
        return min((float) $coupon->value, $cartValue);
    }
}

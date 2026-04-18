<?php

namespace App\DTOs;

readonly class CouponValidationResult
{
    public function __construct(
        public bool $isValid,
        public ?string $failureReason = null,
        public ?float $discountAmount = null,
        public ?int $couponId = null,
        public ?int $settingVersion = null,
        public array $ruleSnapshot = [],
    ) {}

    public static function pass(
        float $discountAmount,
        int $couponId,
        int $settingVersion,
        array $ruleSnapshot,
    ): self {
        return new self(
            isValid: true,
            discountAmount: $discountAmount,
            couponId: $couponId,
            settingVersion: $settingVersion,
            ruleSnapshot: $ruleSnapshot,
        );
    }

    public static function fail(string $reason): self
    {
        return new self(isValid: false, failureReason: $reason);
    }
}

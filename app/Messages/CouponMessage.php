<?php

namespace App\Messages;

final class CouponMessage
{
    const VERIFICATION_IN_PROGRESS  = 'Coupon verification in progress';
    const VALIDATING                = 'Validating coupon...';
    const RESERVED_SUCCESSFULLY     = 'Coupon reserved successfully';
    const CONSUMPTION_QUEUED        = 'Coupon consumption queued';
    const CONSUMED_SUCCESSFULLY     = 'Coupon successfully applied to order';
    const RELEASE_QUEUED            = 'Coupon release queued';
    const RESERVATION_RELEASED      = 'Coupon reservation released';
    const SYSTEM_ERROR              = 'Validation failed due to a system error. Please try again.';
}

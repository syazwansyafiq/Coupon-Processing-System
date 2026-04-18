<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponEvent extends Model
{
    protected $fillable = [
        'event_type',
        'coupon_id',
        'user_id',
        'cart_id',
        'order_id',
        'idempotency_key',
        'rule_version',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'rule_version' => 'integer',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponSetting extends Model
{
    use HasFactory;
    protected $fillable = [
        'coupon_id',
        'version',
        'global_usage_limit',
        'per_user_limit',
        'min_cart_value',
        'rules',
        'is_active',
        'activated_at',
    ];

    protected $casts = [
        'global_usage_limit' => 'integer',
        'per_user_limit' => 'integer',
        'min_cart_value' => 'decimal:2',
        'rules' => 'array',
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Rules snapshot for event payload — includes all constraint fields.
     */
    public function toRuleSnapshot(): array
    {
        return [
            'version' => $this->version,
            'global_usage_limit' => $this->global_usage_limit,
            'per_user_limit' => $this->per_user_limit,
            'min_cart_value' => $this->min_cart_value,
            'rules' => $this->rules ?? [],
            'activated_at' => $this->activated_at?->toIso8601String(),
        ];
    }
}

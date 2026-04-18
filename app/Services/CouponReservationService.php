<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CouponReservationService
{
    private const RESERVATION_TTL = 300; // 5 minutes in seconds

    public function reserve(
        string $couponCode,
        int $userId,
        string $idempotencyKey,
        int $globalUsageLimit,
        int $currentGlobalUsage,
    ): bool {
        $reservationKey = $this->reservationKey($couponCode, $userId);
        $setKey = $this->reservationSetKey($couponCode);
        $expireAt = time() + self::RESERVATION_TTL;

        Log::info('coupon.redis.reserve.attempt', [
            'coupon_code'          => $couponCode,
            'user_id'              => $userId,
            'idempotency_key'      => $idempotencyKey,
            'global_usage_limit'   => $globalUsageLimit,
            'current_global_usage' => $currentGlobalUsage,
            'reservation_key'      => $reservationKey,
        ]);

        $script = <<<'LUA'
        local res_key      = KEYS[1]
        local set_key      = KEYS[2]
        local now          = tonumber(ARGV[1])
        local expire_at    = tonumber(ARGV[2])
        local ttl          = tonumber(ARGV[3])
        local global_limit = tonumber(ARGV[4])
        local current_used = tonumber(ARGV[5])
        local payload      = ARGV[6]

        -- Idempotency: already reserved → refresh TTL and succeed
        if redis.call('EXISTS', res_key) == 1 then
            redis.call('EXPIRE', res_key, ttl)
            return 1
        end

        -- Prune expired entries from the sorted set
        redis.call('ZREMRANGEBYSCORE', set_key, '-inf', now)

        -- Count active reservations
        local active = redis.call('ZCARD', set_key)
        local total  = current_used + active

        if global_limit > 0 and total >= global_limit then
            return 0
        end

        -- Commit reservation
        redis.call('SET', res_key, payload, 'EX', ttl)
        redis.call('ZADD', set_key, expire_at, res_key)
        redis.call('EXPIRE', set_key, ttl + 60)

        return 1
        LUA;

        $payload = json_encode([
            'coupon_code'     => $couponCode,
            'user_id'         => $userId,
            'idempotency_key' => $idempotencyKey,
            'reserved_at'     => now()->toIso8601String(),
            'expires_at'      => now()->addSeconds(self::RESERVATION_TTL)->toIso8601String(),
        ]);

        $result = Redis::eval(
            $script,
            2,
            $reservationKey,
            $setKey,
            time(),
            $expireAt,
            self::RESERVATION_TTL,
            $globalUsageLimit ?? 0,
            $currentGlobalUsage,
            $payload,
        );

        $reserved = (bool) $result;

        Log::info('coupon.redis.reserve.result', [
            'coupon_code'     => $couponCode,
            'user_id'         => $userId,
            'idempotency_key' => $idempotencyKey,
            'reserved'        => $reserved,
        ]);

        return $reserved;
    }

    public function release(string $couponCode, int $userId): void
    {
        $reservationKey = $this->reservationKey($couponCode, $userId);
        $setKey = $this->reservationSetKey($couponCode);

        Log::info('coupon.redis.release', [
            'coupon_code'     => $couponCode,
            'user_id'         => $userId,
            'reservation_key' => $reservationKey,
        ]);

        Redis::pipeline(function ($pipe) use ($reservationKey, $setKey) {
            $pipe->del($reservationKey);
            $pipe->zrem($setKey, $reservationKey);
        });
    }

    public function exists(string $couponCode, int $userId): bool
    {
        $exists = (bool) Redis::exists($this->reservationKey($couponCode, $userId));

        Log::info('coupon.redis.exists', [
            'coupon_code' => $couponCode,
            'user_id'     => $userId,
            'exists'      => $exists,
        ]);

        return $exists;
    }

    public function get(string $couponCode, int $userId): ?array
    {
        $data = Redis::get($this->reservationKey($couponCode, $userId));
        $result = $data ? json_decode($data, true) : null;

        Log::info('coupon.redis.get', [
            'coupon_code' => $couponCode,
            'user_id'     => $userId,
            'found'       => $result !== null,
        ]);

        return $result;
    }

    public function activeCount(string $couponCode): int
    {
        $setKey = $this->reservationSetKey($couponCode);
        Redis::zremrangebyscore($setKey, '-inf', (string) time());
        $count = (int) Redis::zcard($setKey);

        Log::info('coupon.redis.active_count', [
            'coupon_code'  => $couponCode,
            'active_count' => $count,
        ]);

        return $count;
    }

    public function setStatus(string $idempotencyKey, array $status, int $ttl = 600): void
    {
        Log::info('coupon.redis.set_status', [
            'idempotency_key' => $idempotencyKey,
            'status'          => $status['status'],
            'ttl'             => $ttl,
        ]);

        Redis::setex("coupon:status:{$idempotencyKey}", $ttl, json_encode($status));
    }

    public function getStatus(string $idempotencyKey): ?array
    {
        $data = Redis::get("coupon:status:{$idempotencyKey}");
        $result = $data ? json_decode($data, true) : null;

        Log::info('coupon.redis.get_status', [
            'idempotency_key' => $idempotencyKey,
            'found'           => $result !== null,
            'status'          => $result['status'] ?? null,
        ]);

        return $result;
    }

    public function pruneExpiredReservations(string $couponCode): int
    {
        $setKey = $this->reservationSetKey($couponCode);
        $pruned = (int) Redis::zremrangebyscore($setKey, '-inf', (string) time());

        Log::info('coupon.redis.prune_expired', [
            'coupon_code'  => $couponCode,
            'pruned_count' => $pruned,
        ]);

        return $pruned;
    }

    private function reservationKey(string $couponCode, int $userId): string
    {
        return "coupon:res:{$couponCode}:{$userId}";
    }

    private function reservationSetKey(string $couponCode): string
    {
        return "coupon:res_set:{$couponCode}";
    }
}

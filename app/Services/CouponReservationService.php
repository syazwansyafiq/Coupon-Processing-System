<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class CouponReservationService
{
    private const RESERVATION_TTL = 300; // 5 minutes in seconds

    /**
     * Atomically reserve a coupon for a user using a Lua script.
     *
     * Uses a Sorted Set (ZADD) for the active-reservations set so expired entries
     * are prunable without relying on decrement counters that can drift on TTL expiry.
     *
     * Returns true on success (including idempotent re-reservation), false if
     * the global slot is already full.
     */
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
            'coupon_code' => $couponCode,
            'user_id' => $userId,
            'idempotency_key' => $idempotencyKey,
            'reserved_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds(self::RESERVATION_TTL)->toIso8601String(),
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

        return (bool) $result;
    }

    public function release(string $couponCode, int $userId): void
    {
        $reservationKey = $this->reservationKey($couponCode, $userId);
        $setKey = $this->reservationSetKey($couponCode);

        Redis::pipeline(function ($pipe) use ($reservationKey, $setKey) {
            $pipe->del($reservationKey);
            $pipe->zrem($setKey, $reservationKey);
        });
    }

    public function exists(string $couponCode, int $userId): bool
    {
        return (bool) Redis::exists($this->reservationKey($couponCode, $userId));
    }

    public function get(string $couponCode, int $userId): ?array
    {
        $data = Redis::get($this->reservationKey($couponCode, $userId));

        return $data ? json_decode($data, true) : null;
    }

    public function activeCount(string $couponCode): int
    {
        $setKey = $this->reservationSetKey($couponCode);
        Redis::zremrangebyscore($setKey, '-inf', (string) time());

        return (int) Redis::zcard($setKey);
    }

    public function setStatus(string $idempotencyKey, array $status, int $ttl = 600): void
    {
        Redis::setex("coupon:status:{$idempotencyKey}", $ttl, json_encode($status));
    }

    public function getStatus(string $idempotencyKey): ?array
    {
        $data = Redis::get("coupon:status:{$idempotencyKey}");

        return $data ? json_decode($data, true) : null;
    }

    /** Release all reservations whose TTL has passed but whose set entry lingers. */
    public function pruneExpiredReservations(string $couponCode): int
    {
        $setKey = $this->reservationSetKey($couponCode);

        return (int) Redis::zremrangebyscore($setKey, '-inf', (string) time());
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

<?php

namespace App\Jobs;

use App\Models\Coupon;
use App\Services\CouponReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled recovery job — prunes stale entries from Redis reservation sets.
 * Redis TTLs handle individual reservation keys; this job cleans the sorted sets
 * so activeCount() stays accurate. Schedule via Horizon or artisan schedule.
 */
class ReleaseExpiredReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(CouponReservationService $reservationService): void
    {
        Coupon::where('is_active', true)->each(function (Coupon $coupon) use ($reservationService) {
            $pruned = $reservationService->pruneExpiredReservations($coupon->code);

            if ($pruned > 0) {
                \Log::info('Pruned expired coupon reservations', [
                    'coupon_code' => $coupon->code,
                    'pruned_count' => $pruned,
                ]);
            }
        });
    }
}

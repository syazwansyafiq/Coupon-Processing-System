<?php

namespace App\Jobs;

use App\Models\Coupon;
use App\Services\CouponReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        Log::info('coupon.job.release_expired.start');

        $totalPruned = 0;

        Coupon::where('is_active', true)->each(function (Coupon $coupon) use ($reservationService, &$totalPruned) {
            $pruned = $reservationService->pruneExpiredReservations($coupon->code);
            $totalPruned += $pruned;
        });

        Log::info('coupon.job.release_expired.complete', [
            'total_pruned' => $totalPruned,
        ]);
    }
}

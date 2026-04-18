<?php

namespace App\Console\Commands;

use App\Jobs\ReleaseExpiredReservationsJob;
use Illuminate\Console\Command;

class ReleaseExpiredReservations extends Command
{
    protected $signature = 'coupons:release-expired';
    protected $description = 'Prune stale reservation entries from Redis sorted sets';

    public function handle(): int
    {
        ReleaseExpiredReservationsJob::dispatch();
        $this->info('ReleaseExpiredReservationsJob dispatched.');

        return Command::SUCCESS;
    }
}

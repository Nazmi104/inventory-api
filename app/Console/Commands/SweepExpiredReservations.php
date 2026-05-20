<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * SweepExpiredReservations
 *
 * Belt-and-suspenders sweep that catches any pending reservations whose
 * expires_at has passed but whose ExpireReservation job never fired
 * (e.g. queue worker was down).
 *
 * Register in App\Console\Kernel:
 *   $schedule->command('inventory:sweep-expired')->everyMinute();
 */
class SweepExpiredReservations extends Command
{
    protected $signature   = 'inventory:sweep-expired';
    protected $description = 'Expire all pending reservations past their expiry time.';

    public function handle(InventoryService $service): int
    {
        $stale = Reservation::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->pluck('id');

        if ($stale->isEmpty()) {
            $this->info('No stale reservations found.');
            return self::SUCCESS;
        }

        foreach ($stale as $id) {
            try {
                $service->expireReservation($id);
                $this->line("  ✓ Expired reservation #{$id}");
            } catch (\Throwable $e) {
                $this->warn("  ✗ Reservation #{$id}: {$e->getMessage()}");
            }
        }

        $this->info("Swept {$stale->count()} reservation(s).");
        return self::SUCCESS;
    }
}
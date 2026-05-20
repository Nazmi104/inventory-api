<?php

namespace App\Jobs;

use App\Services\InventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ExpireReservation
 *
 * Dispatched with a 5-minute delay when a reservation is created.
 * If the reservation was confirmed or cancelled before the delay fires,
 * InventoryService::expireReservation() is a safe no-op.
 *
 * Queue driver recommendations
 * ─────────────────────────────
 *  • Local dev  : QUEUE_CONNECTION=database  (php artisan queue:work)
 *  • Production : Redis (Horizon) or SQS – both support delayed jobs natively.
 *
 * Fallback (no queue worker)
 * ──────────────────────────
 *  A scheduled command (App\Console\Commands\SweepExpiredReservations) runs
 *  every minute via the Scheduler as a belt-and-suspenders sweep.
 */
class ExpireReservation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $reservationId) {}

    public function handle(InventoryService $service): void
    {
        try {
            $service->expireReservation($this->reservationId);
            Log::info("Reservation {$this->reservationId} expired by job.");
        } catch (\Throwable $e) {
            Log::error("Failed to expire reservation {$this->reservationId}: {$e->getMessage()}");
            throw $e;
        }
    }
}
<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\Product;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * InventoryService
 *
 * All state-changing operations run inside a database transaction with
 * a pessimistic row-level lock (SELECT … FOR UPDATE) on the product row.
 *
 * ┌─────────────────────────────────────────────────────┐
 * │  Concurrency strategy                               │
 * │                                                     │
 * │  MySQL / PostgreSQL guarantee that only ONE          │
 * │  transaction can hold the FOR UPDATE lock on a      │
 * │  given product row at a time. Competing requests    │
 * │  queue up and are served one at a time, so the      │
 * │  "available = stock - reserved" check is always     │
 * │  accurate and overselling is structurally           │
 * │  impossible.                                        │
 * └─────────────────────────────────────────────────────┘
 */
class InventoryService
{
    private const RESERVATION_TTL_MINUTES = 5;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Check whether a product is eligible for sale (active + stock available).
     */
    public function checkEligibility(int $productId): array
    {
        $product = Product::findOrFail($productId);

        return [
            'product_id'         => $product->id,
            'sku'                => $product->sku,
            'name'               => $product->name,
            'price'              => $product->price,
            'is_active'          => $product->is_active,
            'stock_quantity'     => $product->stock_quantity,
            'reserved_quantity'  => $product->reserved_quantity,
            'available_quantity' => $product->available_quantity,
            'eligible_for_sale'  => $product->isEligibleForSale(),
        ];
    }

    /**
     * Reserve `$quantity` units of `$productId` for `$orderId`.
     *
     * Rules enforced here:
     *   • Cannot oversell (available must be >= requested qty)
     *   • Same orderId cannot reserve twice
     *   • Reservation expires in 5 minutes unless confirmed
     */
    public function reserve(string $orderId, int $productId, int $quantity): Reservation
    {
        if ($quantity < 1) {
            throw new InventoryException('Quantity must be at least 1.');
        }

        return DB::transaction(function () use ($orderId, $productId, $quantity) {

            // 1. Lock the product row – only one transaction proceeds at a time.
            /** @var Product $product */
            $product = Product::lockForUpdate()->findOrFail($productId);

            // 2. Reject inactive products.
            if (! $product->is_active) {
                throw new InventoryException("Product [{$product->sku}] is not available for sale.");
            }

            // 3. Guard: same orderId cannot reserve twice.
            $existing = Reservation::where('order_id', $orderId)->first();
            if ($existing) {
                throw new InventoryException(
                    "Order ID [{$orderId}] already has a reservation (status: {$existing->status})."
                );
            }

            // 4. Guard: do not oversell.
            if ($product->available_quantity < $quantity) {
                throw new InventoryException(
                    "Insufficient stock. Requested: {$quantity}, available: {$product->available_quantity}."
                );
            }

            // 5. Deduct from available stock by increasing the reservation counter.
            $product->increment('reserved_quantity', $quantity);

            // 6. Persist the reservation record.
            $reservation = Reservation::create([
                'order_id'   => $orderId,
                'product_id' => $productId,
                'quantity'   => $quantity,
                'status'     => 'pending',
                'expires_at' => Carbon::now()->addMinutes(self::RESERVATION_TTL_MINUTES),
            ]);

            // 7. Schedule the auto-release job exactly at expiry time.
            \App\Jobs\ExpireReservation::dispatch($reservation->id)
                ->delay($reservation->expires_at);

            return $reservation;
        });
    }

    /**
     * Confirm (place) an order. The reservation must still be pending & unexpired.
     */
    public function confirmOrder(string $orderId): Reservation
    {
        return DB::transaction(function () use ($orderId) {

            $reservation = Reservation::where('order_id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPendingAndNotExpired($reservation);

            // Mark as confirmed – stock is now permanently consumed.
            $reservation->update([
                'status'       => 'confirmed',
                'confirmed_at' => Carbon::now(),
            ]);

            // Decrease reserved_quantity (stock_quantity already reduced at reserve time,
            // so we just release the "hold" label).
            $reservation->product()->lockForUpdate()->first()
                ->decrement('reserved_quantity', $reservation->quantity);

            // Also permanently reduce stock to reflect the sale.
            $reservation->product
                ->decrement('stock_quantity', $reservation->quantity);

            return $reservation->fresh('product');
        });
    }

    /**
     * Cancel an order and return the held stock to available.
     */
    public function cancelOrder(string $orderId): Reservation
    {
        return DB::transaction(function () use ($orderId) {

            $reservation = Reservation::where('order_id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($reservation->status, ['pending', 'confirmed'], true)) {
                throw new InventoryException(
                    "Reservation [{$orderId}] cannot be cancelled (status: {$reservation->status})."
                );
            }

            $this->releaseStock($reservation);

            $reservation->update([
                'status'       => 'cancelled',
                'cancelled_at' => Carbon::now(),
            ]);

            return $reservation->fresh('product');
        });
    }

    /**
     * Called by the ExpireReservation job. Marks the reservation expired and
     * returns the stock. No-ops if the reservation was already actioned.
     */
    public function expireReservation(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {

            $reservation = Reservation::lockForUpdate()->find($reservationId);

            if (! $reservation || ! $reservation->isPending()) {
                // Already confirmed / cancelled – nothing to do.
                return;
            }

            $this->releaseStock($reservation);

            $reservation->update(['status' => 'expired']);
        });
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function assertPendingAndNotExpired(Reservation $reservation): void
    {
        if (! $reservation->isPending()) {
            throw new InventoryException(
                "Reservation [{$reservation->order_id}] is no longer pending (status: {$reservation->status})."
            );
        }

        if ($reservation->isExpired()) {
            throw new InventoryException(
                "Reservation [{$reservation->order_id}] has expired."
            );
        }
    }

    /**
     * Give back the held quantity to the product's available pool.
     */
    private function releaseStock(Reservation $reservation): void
    {
        Product::lockForUpdate()
            ->find($reservation->product_id)
            ?->decrement('reserved_quantity', $reservation->quantity);
    }
}

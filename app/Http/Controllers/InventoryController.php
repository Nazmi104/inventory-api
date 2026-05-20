<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryException;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    // ── GET /api/products/{productId}/eligibility ─────────────────────────────

    /**
     * Check whether a product is eligible for sale.
     *
     * Response 200:
     * {
     *   "eligible_for_sale": true,
     *   "available_quantity": 42,
     *   ...
     * }
     */
    public function checkEligibility(int $productId): JsonResponse
    {
        try {
            $data = $this->service->checkEligibility($productId);
            return response()->json($data);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound("Product [{$productId}] not found.");
        }
    }

    // ── POST /api/reservations ────────────────────────────────────────────────

    /**
     * Reserve stock for an order.
     *
     * Request body:
     * {
     *   "order_id":   "ORD-20240501-XYZ",
     *   "product_id": 1,
     *   "quantity":   3
     * }
     */
    public function reserve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id'   => 'required|string|max:100',
            'product_id' => 'required|integer|min:1',
            'quantity'   => 'required|integer|min:1',
        ]);

        try {
            $reservation = $this->service->reserve(
                $data['order_id'],
                $data['product_id'],
                $data['quantity'],
            );

            return response()->json([
                'message'     => 'Reservation created. You have 5 minutes to confirm.',
                'reservation' => $this->formatReservation($reservation),
            ], 201);

        } catch (InventoryException $e) {
            return $this->unprocessable($e->getMessage());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound("Product [{$data['product_id']}] not found.");
        }
    }

    // ── POST /api/orders/{orderId}/confirm ────────────────────────────────────

    /**
     * Confirm (place) a reserved order.
     */
    public function confirmOrder(string $orderId): JsonResponse
    {
        try {
            $reservation = $this->service->confirmOrder($orderId);

            return response()->json([
                'message'     => 'Order confirmed successfully.',
                'reservation' => $this->formatReservation($reservation),
            ]);

        } catch (InventoryException $e) {
            return $this->unprocessable($e->getMessage());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound("Order [{$orderId}] not found.");
        }
    }

    // ── DELETE /api/orders/{orderId} ──────────────────────────────────────────

    /**
     * Cancel an order and return the reserved stock.
     */
    public function cancelOrder(string $orderId): JsonResponse
    {
        try {
            $reservation = $this->service->cancelOrder($orderId);

            return response()->json([
                'message'     => 'Order cancelled and stock returned.',
                'reservation' => $this->formatReservation($reservation),
            ]);

        } catch (InventoryException $e) {
            return $this->unprocessable($e->getMessage());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound("Order [{$orderId}] not found.");
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatReservation($reservation): array
    {
        return [
            'id'                 => $reservation->id,
            'order_id'           => $reservation->order_id,
            'product_id'         => $reservation->product_id,
            'quantity'           => $reservation->quantity,
            'status'             => $reservation->status,
            'expires_at'         => $reservation->expires_at?->toIso8601String(),
            'confirmed_at'       => $reservation->confirmed_at?->toIso8601String(),
            'cancelled_at'       => $reservation->cancelled_at?->toIso8601String(),
            'product'            => $reservation->product ? [
                'sku'                => $reservation->product->sku,
                'name'               => $reservation->product->name,
                'available_quantity' => $reservation->product->available_quantity,
            ] : null,
        ];
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 404);
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 422);
    }
}
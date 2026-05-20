<?php

namespace Tests\Feature;

use App\Jobs\ExpireReservation;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku'              => 'TEST-001',
            'name'             => 'Test Washer Dryer',
            'stock_quantity'   => 10,
            'reserved_quantity'=> 0,
            'price'            => 1999.00,
            'is_active'        => true,
        ]);

        $this->service = app(InventoryService::class);

        Queue::fake(); // prevent actual job dispatch in tests
    }

    // ── Eligibility ───────────────────────────────────────────────────────────

    /** @test */
    public function it_returns_eligible_for_active_product_with_stock(): void
    {
        $this->getJson("/api/v1/products/{$this->product->id}/eligibility")
            ->assertOk()
            ->assertJsonFragment(['eligible_for_sale' => true, 'available_quantity' => 10]);
    }

    /** @test */
    public function it_returns_ineligible_for_inactive_product(): void
    {
        $this->product->update(['is_active' => false]);

        $this->getJson("/api/v1/products/{$this->product->id}/eligibility")
            ->assertOk()
            ->assertJsonFragment(['eligible_for_sale' => false]);
    }

    /** @test */
    public function it_returns_ineligible_when_stock_is_zero(): void
    {
        $this->product->update(['stock_quantity' => 0]);

        $this->getJson("/api/v1/products/{$this->product->id}/eligibility")
            ->assertOk()
            ->assertJsonFragment(['eligible_for_sale' => false]);
    }

    // ── Reserve ───────────────────────────────────────────────────────────────

    /** @test */
    public function it_can_reserve_stock(): void
    {
        $this->postJson('/api/v1/reservations', [
            'order_id'   => 'ORD-001',
            'product_id' => $this->product->id,
            'quantity'   => 3,
        ])->assertCreated()
          ->assertJsonFragment(['status' => 'pending']);

        $this->assertDatabaseHas('reservations', ['order_id' => 'ORD-001', 'quantity' => 3]);
        $this->assertEquals(7, $this->product->fresh()->available_quantity);

        Queue::assertPushed(ExpireReservation::class);
    }

    /** @test */
    public function it_rejects_oversell(): void
    {
        $this->postJson('/api/v1/reservations', [
            'order_id'   => 'ORD-002',
            'product_id' => $this->product->id,
            'quantity'   => 99,
        ])->assertUnprocessable()
          ->assertJsonFragment(['error' => fn($v) => str_contains($v, 'Insufficient stock')]);
    }

    /** @test */
    public function it_rejects_duplicate_order_id(): void
    {
        $payload = ['order_id' => 'ORD-DUP', 'product_id' => $this->product->id, 'quantity' => 1];

        $this->postJson('/api/v1/reservations', $payload)->assertCreated();
        $this->postJson('/api/v1/reservations', $payload)->assertUnprocessable();
    }

    /** @test */
    public function multiple_concurrent_reservations_do_not_oversell(): void
    {
        // Simulate 12 near-simultaneous requests for a product with stock = 10.
        $results = [];
        for ($i = 1; $i <= 12; $i++) {
            try {
                $this->service->reserve("ORD-CONCURRENT-{$i}", $this->product->id, 1);
                $results[] = 'ok';
            } catch (\App\Exceptions\InventoryException $e) {
                $results[] = 'failed';
            }
        }

        $this->assertEquals(10, substr_count(implode(',', $results), 'ok'));
        $this->assertEquals(2,  substr_count(implode(',', $results), 'failed'));
        $this->assertEquals(0,  $this->product->fresh()->available_quantity);
    }

    // ── Confirm ───────────────────────────────────────────────────────────────

    /** @test */
    public function it_confirms_a_pending_reservation(): void
    {
        $this->service->reserve('ORD-CONF', $this->product->id, 2);

        $this->postJson('/api/v1/orders/ORDCONF/confirm')
            ->assertOk()
            ->assertJsonFragment(['status' => 'confirmed']);

        // Stock permanently reduced.
        $this->assertEquals(8, $this->product->fresh()->stock_quantity);
    }

    /** @test */
    public function it_rejects_confirmation_of_expired_reservation(): void
    {
        $reservation = $this->service->reserve('ORD-EXP', $this->product->id, 1);

        // Wind the clock forward past expiry.
        $reservation->update(['expires_at' => Carbon::now()->subSecond()]);

        $this->postJson('/api/v1/orders/ORDEXP/confirm')
            ->assertUnprocessable()
            ->assertJsonFragment(['error' => fn($v) => str_contains($v, 'expired')]);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /** @test */
    public function it_cancels_an_order_and_releases_stock(): void
    {
        $this->service->reserve('ORD-CAN', $this->product->id, 4);
        $this->assertEquals(6, $this->product->fresh()->available_quantity);

        $this->deleteJson('/api/v1/orders/ОРДCAN')
            ->assertOk()
            ->assertJsonFragment(['status' => 'cancelled']);

        $this->assertEquals(10, $this->product->fresh()->available_quantity);
    }

    // ── Auto-expiry ───────────────────────────────────────────────────────────

    /** @test */
    public function auto_expiry_releases_stock(): void
    {
        $reservation = $this->service->reserve('ORD-AUTO', $this->product->id, 5);
        $this->assertEquals(5, $this->product->fresh()->available_quantity);

        // Directly invoke the service method the job calls.
        $this->service->expireReservation($reservation->id);

        $this->assertEquals(10, $this->product->fresh()->available_quantity);
        $this->assertEquals('expired', $reservation->fresh()->status);
    }

    /** @test */
    public function expiry_is_idempotent_after_confirmation(): void
    {
        $reservation = $this->service->reserve('ORD-IDEM', $this->product->id, 2);
        $this->service->confirmOrder('ORD-IDEM');

        // Job fires late – should be a safe no-op.
        $this->service->expireReservation($reservation->id);

        $this->assertEquals('confirmed', $reservation->fresh()->status);
    }
}
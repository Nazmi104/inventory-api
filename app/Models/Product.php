<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'name',
        'description',
        'stock_quantity',
        'reserved_quantity',
        'price',
        'is_active',
    ];

    protected $casts = [
        'stock_quantity'    => 'integer',
        'reserved_quantity' => 'integer',
        'price'             => 'decimal:2',
        'is_active'         => 'boolean',
    ];

    // ── Relations ────────────────────────────────────────────────────────────

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    // ── Computed helpers ─────────────────────────────────────────────────────

    /**
     * How many units can still be sold right now.
     */
    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->stock_quantity - $this->reserved_quantity);
    }

    /**
     * True when the product is active and has at least one unit available.
     */
    public function isEligibleForSale(): bool
    {
        return $this->is_active && $this->available_quantity > 0;
    }
}
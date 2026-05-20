<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->unique();           // external order identifier (must be unique)
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'expired'])
                  ->default('pending');
            $table->timestamp('expires_at');               // now + 5 minutes on creation
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);       // efficient expiry sweep
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

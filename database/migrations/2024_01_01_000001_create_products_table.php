<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('stock_quantity');       // total physical stock
            $table->unsignedInteger('reserved_quantity')->default(0); // held by active reservations
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // available_quantity = stock_quantity - reserved_quantity (computed in app layer)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

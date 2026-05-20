<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory API Routes
|--------------------------------------------------------------------------
|
| All routes return JSON. No authentication middleware is added here so
| the assessment can be run immediately; in production, wrap with
| auth:sanctum or auth:api as needed.
|
*/

Route::prefix('v1')->group(function () {

    // 1. Eligibility check
    Route::get('products/{productId}/eligibility', [InventoryController::class, 'checkEligibility'])
        ->whereNumber('productId');

    // 2. Reserve stock
    Route::post('reservations', [InventoryController::class, 'reserve']);

    // 3. Confirm order
    Route::post('orders/{orderId}/confirm', [InventoryController::class, 'confirmOrder'])
        ->whereAlphaNumeric('orderId');

    // 4. Cancel order
    Route::delete('orders/{orderId}', [InventoryController::class, 'cancelOrder'])
        ->whereAlphaNumeric('orderId');
});

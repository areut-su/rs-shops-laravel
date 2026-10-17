<?php

use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::prefix('storefront/v1')->group(function (): void {
    Route::get('/catalog', [StorefrontController::class, 'catalog']);
    Route::get('/catalog/meta', [StorefrontController::class, 'catalogMetadata']);
    Route::get('/translations', [StorefrontController::class, 'translations']);
});

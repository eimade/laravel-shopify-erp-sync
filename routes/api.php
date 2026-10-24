<?php

use App\Http\Controllers\Api\ErpImportController;
use App\Http\Controllers\Api\ErpItemController;
use App\Http\Controllers\Api\SyncRunController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Middleware\VerifyErpToken;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

// Webhooks Shopify : signature HMAC obligatoire.
Route::post('/webhooks/shopify', ShopifyWebhookController::class)
    ->middleware(VerifyShopifyWebhook::class)
    ->name('webhooks.shopify');

// API REST du mini-ERP (jeton X-Erp-Token).
Route::middleware([VerifyErpToken::class, 'throttle:120,1'])->prefix('erp')->group(function () {
    Route::get('/items', [ErpItemController::class, 'index']);
    Route::get('/items/{sku}', [ErpItemController::class, 'show']);
    Route::patch('/items/{sku}', [ErpItemController::class, 'update']);
    Route::post('/import', ErpImportController::class);

    Route::get('/sync-runs', [SyncRunController::class, 'index']);
    Route::get('/sync-runs/{syncRun}', [SyncRunController::class, 'show']);
    Route::post('/sync-runs/{syncRun}/replay', [SyncRunController::class, 'replay']);
});

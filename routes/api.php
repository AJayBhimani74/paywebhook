<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{reference}', [OrderController::class, 'show']);

Route::post('/webhooks/payments', WebhookController::class)->middleware(VerifyWebhookSignature::class);

// Stand-in for a real payment provider, only for running the demo locally.
if (config('gateway.simulator')) {
    Route::post('/fake-gateway/payments', function (Request $request) {
        $key = $request->header('Idempotency-Key');

        abort_unless($key, 400, 'Idempotency-Key header is required.');

        $id = Cache::rememberForever("fake-gateway:{$key}", fn () => 'pay_'.Str::random(14));

        return response()->json(['id' => $id, 'status' => 'requires_payment']);
    });
}

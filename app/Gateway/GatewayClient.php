<?php

namespace App\Gateway;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GatewayClient
{
    public function createPayment(Order $order): string
    {
        $response = Http::baseUrl(config('gateway.url'))
            ->acceptJson()
            ->timeout(5)
            ->withHeaders(['Idempotency-Key' => $order->reference])
            ->retry(3, 200, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()))
            ->post('/payments', [
                'amount' => $order->amount,
                'currency' => $order->currency,
                'email' => $order->customer_email,
                'metadata' => ['order_reference' => $order->reference],
            ]);

        return $response->json('id');
    }
}

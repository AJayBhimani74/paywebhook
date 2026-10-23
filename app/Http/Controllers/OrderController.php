<?php

namespace App\Http\Controllers;

use App\Gateway\GatewayClient;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function store(Request $request, GatewayClient $gateway): JsonResponse
    {
        $data = $request->validate([
            'customer_email' => ['required', 'email'],
            'amount' => ['required', 'integer', 'min:100', 'max:100000000'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $order = Order::create($data + ['reference' => 'ORD-'.strtoupper(Str::random(10))]);

        try {
            $order->update(['payment_id' => $gateway->createPayment($order)]);
        } catch (RequestException|ConnectionException) {
            $order->update(['status' => Order::FAILED]);

            return response()->json(['message' => 'The payment gateway is not available. Please try again.'], 502);
        }

        return response()->json($order, 201);
    }

    public function show(string $reference): JsonResponse
    {
        return response()->json(Order::where('reference', $reference)->firstOrFail());
    }
}

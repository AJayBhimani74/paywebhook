<?php

namespace App\Http\Middleware;

use App\Gateway\Signature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $parts = [];
        foreach (explode(',', (string) $request->header('Gateway-Signature')) as $piece) {
            [$key, $value] = array_pad(explode('=', $piece, 2), 2, null);
            $parts[trim($key)] = $value;
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if (! ctype_digit((string) $timestamp) || ! $signature) {
            return response()->json(['message' => 'Missing or malformed signature.'], 400);
        }

        if (abs(time() - (int) $timestamp) > config('gateway.tolerance')) {
            return response()->json(['message' => 'Signature timestamp is too old.'], 400);
        }

        $expected = Signature::hash($request->getContent(), (int) $timestamp, config('gateway.secret'));

        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        return $next($request);
    }
}

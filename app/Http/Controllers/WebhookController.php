<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:60'],
            'data' => ['required', 'array'],
        ]);

        try {
            $event = WebhookEvent::firstOrCreate(
                ['event_id' => $payload['id']],
                ['type' => $payload['type'], 'payload' => $payload]
            );
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        if (! $event->wasRecentlyCreated) {
            return response()->json(['status' => 'duplicate']);
        }

        ProcessWebhookEvent::dispatch($event);

        return response()->json(['status' => 'received']);
    }
}

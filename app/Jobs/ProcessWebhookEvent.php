<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public WebhookEvent $event) {}

    public function backoff(): array
    {
        return [5, 20, 60, 180];
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $event = WebhookEvent::whereKey($this->event->id)->lockForUpdate()->first();

            if (in_array($event->status, [WebhookEvent::PROCESSED, WebhookEvent::IGNORED])) {
                return;
            }

            $event->increment('attempts');

            $reference = $event->payload['data']['metadata']['order_reference'] ?? null;
            $order = Order::where('reference', $reference)->lockForUpdate()->first();

            if (! $order) {
                $this->finish($event, WebhookEvent::IGNORED, 'No order with that reference.');

                return;
            }

            $outcome = match ($event->type) {
                'payment.succeeded' => $this->paymentSucceeded($order, $event),
                'payment.failed' => $this->paymentFailed($order),
                'refund.completed' => $this->refundCompleted($order),
                default => WebhookEvent::IGNORED,
            };

            if ($outcome === 'retry') {
                $event->update(['error' => "Order is {$order->status}, cannot apply {$event->type} yet."]);
                $this->release($this->backoff()[min($event->attempts, 4) - 1]);

                return;
            }

            $this->finish($event, $outcome);
        });
    }

    public function failed(Throwable $e): void
    {
        $this->event->update(['status' => WebhookEvent::FAILED, 'error' => $e->getMessage()]);
    }

    private function paymentSucceeded(Order $order, WebhookEvent $event): string
    {
        if ($order->status === Order::REFUNDED) {
            return WebhookEvent::IGNORED;
        }

        if ($order->status !== Order::PAID) {
            $order->update([
                'status' => Order::PAID,
                'paid_at' => now(),
                'payment_id' => $order->payment_id ?? ($event->payload['data']['id'] ?? null),
            ]);
        }

        return WebhookEvent::PROCESSED;
    }

    private function paymentFailed(Order $order): string
    {
        if ($order->status !== Order::PENDING) {
            return WebhookEvent::IGNORED;
        }

        $order->update(['status' => Order::FAILED]);

        return WebhookEvent::PROCESSED;
    }

    private function refundCompleted(Order $order): string
    {
        return match ($order->status) {
            Order::PAID => $this->markRefunded($order),
            Order::PENDING => 'retry',
            Order::REFUNDED => WebhookEvent::PROCESSED,
            default => WebhookEvent::IGNORED,
        };
    }

    private function markRefunded(Order $order): string
    {
        $order->update(['status' => Order::REFUNDED, 'refunded_at' => now()]);

        return WebhookEvent::PROCESSED;
    }

    private function finish(WebhookEvent $event, string $status, ?string $error = null): void
    {
        $event->update(['status' => $status, 'error' => $error, 'processed_at' => now()]);
    }
}

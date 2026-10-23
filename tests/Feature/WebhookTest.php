<?php

namespace Tests\Feature;

use App\Gateway\Signature;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Order;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function event(Order $order, string $type = 'payment.succeeded', string $id = 'evt_1'): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'data' => ['id' => 'pay_1', 'metadata' => ['order_reference' => $order->reference]],
        ];
    }

    private function send(array $payload, ?string $header = null, int $age = 0, ?string $secret = null)
    {
        $body = json_encode($payload);
        $header ??= Signature::header($body, time() - $age, $secret ?? config('gateway.secret'));

        return $this->call('POST', '/api/webhooks/payments', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_GATEWAY_SIGNATURE' => $header,
        ], $body);
    }

    public function test_valid_event_marks_the_order_paid(): void
    {
        $order = Order::factory()->create();

        $this->send($this->event($order))->assertOk()->assertJson(['status' => 'received']);

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(WebhookEvent::PROCESSED, WebhookEvent::first()->status);
    }

    public function test_missing_or_malformed_signature_is_rejected(): void
    {
        $order = Order::factory()->create();
        Queue::fake();

        $this->send($this->event($order), header: '')->assertStatus(400);
        $this->send($this->event($order), header: 'garbage')->assertStatus(400);
        $this->send($this->event($order), header: 't=abc,v1=123')->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $order = Order::factory()->create();

        $this->send($this->event($order), secret: 'someone-else')->assertStatus(400);

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_body_changed_after_signing_is_rejected(): void
    {
        $order = Order::factory()->create();
        $signed = json_encode($this->event($order));
        $header = Signature::header($signed, time(), config('gateway.secret'));

        $tampered = $this->event($order, 'refund.completed');

        $this->send($tampered, header: $header)->assertStatus(400);
    }

    public function test_old_signature_is_rejected_as_a_replay(): void
    {
        $order = Order::factory()->create();

        $this->send($this->event($order), age: 301)->assertStatus(400);
        $this->send($this->event($order), age: 290)->assertOk();
    }

    public function test_same_event_delivered_twice_is_processed_once(): void
    {
        $order = Order::factory()->create();
        Queue::fake();

        $this->send($this->event($order))->assertJson(['status' => 'received']);
        $this->send($this->event($order))->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_running_the_job_again_does_not_repeat_the_work(): void
    {
        $order = Order::factory()->create();
        $this->send($this->event($order));
        $paidAt = $order->fresh()->paid_at;

        $this->travel(5)->minutes();
        (new ProcessWebhookEvent(WebhookEvent::first()))->handle();

        $this->assertEquals($paidAt, $order->fresh()->paid_at);
    }

    public function test_payload_must_have_id_type_and_data(): void
    {
        $this->send(['type' => 'payment.succeeded'])->assertStatus(422);
    }

    public function test_failed_payment_only_applies_to_pending_orders(): void
    {
        $pending = Order::factory()->create();
        $paid = Order::factory()->create(['status' => Order::PAID]);

        $this->send($this->event($pending, 'payment.failed', 'evt_a'));
        $this->send($this->event($paid, 'payment.failed', 'evt_b'));

        $this->assertSame(Order::FAILED, $pending->fresh()->status);
        $this->assertSame(Order::PAID, $paid->fresh()->status);
        $this->assertSame(WebhookEvent::IGNORED, WebhookEvent::where('event_id', 'evt_b')->value('status'));
    }

    public function test_refund_marks_a_paid_order_refunded(): void
    {
        $order = Order::factory()->create(['status' => Order::PAID]);

        $this->send($this->event($order, 'refund.completed'));

        $this->assertSame(Order::REFUNDED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->refunded_at);
    }

    public function test_late_success_event_does_not_undo_a_refund(): void
    {
        $order = Order::factory()->create(['status' => Order::REFUNDED]);

        $this->send($this->event($order, 'payment.succeeded'));

        $this->assertSame(Order::REFUNDED, $order->fresh()->status);
    }

    public function test_refund_arriving_before_the_payment_waits_and_is_applied_later(): void
    {
        $order = Order::factory()->create();
        Queue::fake();

        $this->send($this->event($order, 'refund.completed', 'evt_refund'));
        $refund = WebhookEvent::first();

        $job = new ProcessWebhookEvent($refund);
        $job->handle();
        $this->assertSame(Order::PENDING, $order->fresh()->status);
        $this->assertSame(WebhookEvent::RECEIVED, $refund->fresh()->status);
        $this->assertSame(1, $refund->fresh()->attempts);

        $order->update(['status' => Order::PAID]);
        $job->handle();

        $this->assertSame(Order::REFUNDED, $order->fresh()->status);
        $this->assertSame(WebhookEvent::PROCESSED, $refund->fresh()->status);
    }

    public function test_event_for_an_unknown_order_is_ignored_not_retried(): void
    {
        $order = Order::factory()->make(['reference' => 'ORD-NOPE']);

        $this->send($this->event($order));

        $this->assertSame(WebhookEvent::IGNORED, WebhookEvent::first()->status);
    }

    public function test_a_job_that_runs_out_of_tries_marks_the_event_failed_and_can_be_replayed(): void
    {
        $order = Order::factory()->create();
        Queue::fake();
        $this->send($this->event($order, 'refund.completed', 'evt_r'));
        $event = WebhookEvent::first();

        (new ProcessWebhookEvent($event))->failed(new \RuntimeException('Too many attempts'));
        $this->assertSame(WebhookEvent::FAILED, $event->fresh()->status);

        $order->update(['status' => Order::PAID]);
        $this->artisan('webhooks:replay')->expectsOutput('Queued 1 event(s).')->assertSuccessful();
        $this->assertSame(WebhookEvent::RECEIVED, $event->fresh()->status);
    }
}

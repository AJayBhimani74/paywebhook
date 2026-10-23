<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = ['customer_email' => 'buyer@example.com', 'amount' => 49900];

    public function test_order_is_created_and_the_gateway_payment_id_is_stored(): void
    {
        Http::fake(['*/payments' => Http::response(['id' => 'pay_123'], 200)]);

        $this->postJson('/api/orders', $this->payload)
            ->assertCreated()->assertJsonPath('payment_id', 'pay_123')->assertJsonPath('status', 'pending');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_gateway_is_called_with_idempotency_key_and_order_reference(): void
    {
        Http::fake(['*/payments' => Http::response(['id' => 'pay_123'])]);

        $reference = $this->postJson('/api/orders', $this->payload)->json('reference');

        Http::assertSent(fn (Request $request) => $request->header('Idempotency-Key')[0] === $reference
            && $request['metadata']['order_reference'] === $reference
            && $request['amount'] === 49900);
    }

    public function test_temporary_gateway_errors_are_retried(): void
    {
        Http::fake(['*/payments' => Http::sequence()
            ->push('Bad gateway', 502)
            ->push('Bad gateway', 502)
            ->push(['id' => 'pay_ok'], 200)]);

        $this->postJson('/api/orders', $this->payload)->assertCreated()->assertJsonPath('payment_id', 'pay_ok');

        Http::assertSentCount(3);
    }

    public function test_every_retry_uses_the_same_idempotency_key(): void
    {
        Http::fake(['*/payments' => Http::sequence()->push('error', 500)->push(['id' => 'pay_ok'])]);

        $this->postJson('/api/orders', $this->payload)->assertCreated();

        $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
        $this->assertCount(1, $keys);
    }

    public function test_gateway_that_stays_down_gives_502_and_a_failed_order(): void
    {
        Http::fake(['*/payments' => Http::response('down', 503)]);

        $this->postJson('/api/orders', $this->payload)->assertStatus(502);

        Http::assertSentCount(3);
        $this->assertSame(Order::FAILED, Order::first()->status);
    }

    public function test_client_errors_from_the_gateway_are_not_retried(): void
    {
        Http::fake(['*/payments' => Http::response(['error' => 'bad amount'], 422)]);

        $this->postJson('/api/orders', $this->payload)->assertStatus(502);

        Http::assertSentCount(1);
    }

    public function test_order_input_is_validated(): void
    {
        Http::fake();

        $this->postJson('/api/orders', ['customer_email' => 'nope', 'amount' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_email', 'amount']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_can_be_fetched_by_reference(): void
    {
        $order = Order::factory()->create();

        $this->getJson("/api/orders/{$order->reference}")->assertOk()->assertJsonPath('id', $order->id);
        $this->getJson('/api/orders/ORD-MISSING')->assertNotFound();
    }
}

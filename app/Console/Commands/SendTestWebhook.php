<?php

namespace App\Console\Commands;

use App\Gateway\Signature;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SendTestWebhook extends Command
{
    protected $signature = 'gateway:send
        {reference : Order reference}
        {type : payment.succeeded, payment.failed or refund.completed}
        {--id= : Event id (reuse one to simulate a duplicate delivery)}
        {--age=0 : Pretend the event was signed this many seconds ago}
        {--bad-signature : Sign with the wrong secret}';

    protected $description = 'Send a signed test webhook to this app, like the payment provider would';

    public function handle(): int
    {
        $order = Order::where('reference', $this->argument('reference'))->firstOrFail();

        $body = json_encode([
            'id' => $this->option('id') ?: 'evt_'.Str::random(12),
            'type' => $this->argument('type'),
            'data' => [
                'id' => $order->payment_id,
                'amount' => $order->amount,
                'metadata' => ['order_reference' => $order->reference],
            ],
        ]);

        $secret = $this->option('bad-signature') ? 'wrong-secret' : config('gateway.secret');
        $signature = Signature::header($body, time() - (int) $this->option('age'), $secret);

        $response = Http::withBody($body, 'application/json')
            ->withHeaders(['Gateway-Signature' => $signature])
            ->acceptJson()
            ->post(rtrim(config('app.url'), '/').'/api/webhooks/payments');

        $this->line($response->status().' '.$response->body());

        return self::SUCCESS;
    }
}

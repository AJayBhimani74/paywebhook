# How paywebhook works

Read this before an interview. It explains the project in plain words.

## The idea

Taking a card payment is not one request. You ask the payment provider to start a payment, the customer pays on their side, and later the provider tells you what happened by sending a **webhook**: an HTTP request from them to you. Your database must end up correct even though networks fail, requests repeat and messages arrive late or in the wrong order. This project is about handling those cases.

## Where things live

| File | What it does |
|---|---|
| `app/Gateway/GatewayClient.php` | Our call to the provider (create a payment). |
| `app/Gateway/Signature.php` | Builds and checks the signature string. |
| `app/Http/Middleware/VerifyWebhookSignature.php` | Rejects bad or old webhook requests before anything else. |
| `app/Http/Controllers/WebhookController.php` | Stores the event and queues a job. Nothing else. |
| `app/Jobs/ProcessWebhookEvent.php` | Applies the event to the order. |
| `app/Http/Controllers/OrderController.php` | Creates an order and a payment. |
| `app/Console/Commands/SendTestWebhook.php` | Plays the provider for demos (`gateway:send`). |
| `app/Console/Commands/ReplayWebhookEvent.php` | Re-queues failed events. |
| `routes/api.php` | Routes, plus the small fake provider that is only on when `GATEWAY_SIMULATOR=true`. |

## Part 1: creating the payment

1. `POST /api/orders` creates an order with status `pending` and a unique reference.
2. `GatewayClient` calls the provider with the header `Idempotency-Key: <reference>`.
3. If the call times out or the provider answers 5xx, it tries again (3 attempts, 200 ms apart). **The key is the same each time**, so if the first attempt actually worked and only the answer was lost, the provider sees the same key and returns the same payment instead of charging twice.
4. 4xx errors are our fault (bad data), so retrying would not help and we don't.
5. If it still fails, the order is marked `failed` and the client gets 502.

"Idempotent" means: doing it twice has the same effect as doing it once.

## Part 2: receiving the webhook

The middleware runs first:

1. The header looks like `t=1730000000,v1=ab12...`.
2. We rebuild `HMAC_SHA256(secret, "<t>.<raw body>")` and compare it with `hash_equals` (a constant-time comparison, so timing can't leak the signature).
3. Only someone who knows the secret can make a matching signature. If the body is changed by even one character, the signature no longer matches.
4. If `t` is more than 5 minutes old we refuse, so a captured request can't be replayed next week.

Then the controller:

1. Validates the shape (`id`, `type`, `data`).
2. Saves the event. `event_id` has a unique index. If it already exists (the provider sends duplicates, and they do) we answer `duplicate` and stop. If two copies arrive at the same moment, the database's unique rule makes one of them fail, and we catch that too.
3. Queues `ProcessWebhookEvent` and answers 200 immediately. Providers retry if you answer slowly or with an error, so do the slow work in the queue.

## Part 3: the job

For each event it opens a transaction and locks the event row and the order row (`lockForUpdate`), so two workers can't change the same order together. Then it applies a small set of rules:

| Event | Order is pending | paid | failed | refunded |
|---|---|---|---|---|
| `payment.succeeded` | becomes paid | nothing | becomes paid | ignored (a refund can't be undone) |
| `payment.failed` | becomes failed | ignored | nothing | ignored |
| `refund.completed` | **wait and retry** | becomes refunded | ignored | nothing |

"Wait and retry" is the out-of-order case: the refund arrived before we processed the payment. The job puts itself back on the queue with a delay (`release`). After 5 tries Laravel calls `failed()`, the event is marked `failed` and `webhooks:replay` can queue it again once the cause is fixed.

Events are matched to orders by `data.metadata.order_reference`, which we sent when creating the payment. That is more reliable than matching on the provider's payment id, which we might not have saved yet when an early webhook arrives.

## Questions you may get

**Why answer 200 before doing the work?** Providers time out and retry. Long work inside the request leads to duplicate deliveries. Store, queue, answer.

**Duplicates are handled by the unique index. Is that enough?** For delivery duplicates, yes. The job also re-checks the event status and the order state, so running it twice is harmless. I tested that.

**Why not trust the event and just set the status?** Events can be late or reordered. Only allowed transitions are applied.

**What about secret rotation?** Providers often send two signatures during rotation. The middleware could check each `v1` value against both secrets. This project uses one secret.

**What if the queue worker is down?** Events are stored first, so nothing is lost. They are processed when the worker starts. A "stuck events" alert (events in `received` for over N minutes) is the next thing I would add.

**How would this look with real Stripe?** Use their SDK or Cashier to verify signatures (`Stripe-Signature` has the same shape), keep the storage, queue and state rules exactly as they are, and replace `GatewayClient` with their payment intent call.

**What is simulated?** The provider. The `fake-gateway` route and `gateway:send` stand in for it. Nothing here talks to a real payment service.

## Try it yourself

Follow the README. Run each `gateway:send` line and look at the answer, then run `php artisan tinker --execute='print_r(App\Models\WebhookEvent::all()->toArray());'` to see the stored events. Then read `tests/Feature/WebhookTest.php`. Try breaking `VerifyWebhookSignature` yourself and see which tests fail.

# paywebhook

A small Laravel 12 app that shows how to connect to a payment provider properly: create payments with an idempotency key, retry temporary failures, and receive the provider's webhooks safely. It is a portfolio project. There is no real payment provider in it: a tiny built-in simulator plays that role, and the signature format is modelled on what Stripe-style providers do.

## What it handles

| Problem | What the code does |
|---|---|
| Someone posts fake events to the webhook URL | Every request must carry `Gateway-Signature: t=<time>,v1=<hmac>`. The HMAC-SHA256 is checked on the raw body with `hash_equals`. |
| An old, valid request is sent again later (replay) | The signed timestamp must be within 5 minutes. |
| The provider delivers the same event twice | The event id is stored with a unique index. The second delivery answers `duplicate` and nothing runs twice. |
| Processing is slow or fails | The endpoint only stores the event and queues a job, then answers at once. The job retries with growing delays (5s, 20s, 60s, 180s). |
| Events arrive in the wrong order (refund before payment) | A refund for an order that is still `pending` is put back on the queue until the payment event has been applied. |
| A late event must not undo a newer state | State changes are checked: a failed event can't touch a paid order, a late success can't undo a refund. |
| Our call to the provider times out and we send it again | Every attempt carries the same `Idempotency-Key` (the order reference), so the provider creates one payment only. 5xx errors and connection errors are retried, 4xx errors are not. |
| An event fails for good | It is marked `failed` with the error, and `php artisan webhooks:replay` queues it again. |

## Endpoints

| Method | Path | |
|---|---|---|
| POST | `/api/orders` | Create an order and a payment at the provider. Body: `customer_email`, `amount` (minor units), optional `currency`. |
| GET | `/api/orders/{reference}` | Order status: `pending`, `paid`, `failed`, `refunded`. |
| POST | `/api/webhooks/payments` | Called by the provider. Events: `payment.succeeded`, `payment.failed`, `refund.completed`. |

The API has no login on purpose, to keep the focus on the integration.

## Running it

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve --port=8093      # the app
php artisan serve --port=8094      # the simulated provider (same code, second process)
php artisan queue:work             # processes webhook events
```

The PHP dev server handles one request at a time, which is why the simulator needs its own process.

Try it:

```bash
curl -s -X POST localhost:8093/api/orders -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"customer_email":"a@b.com","amount":49900}'
# note the "reference" in the answer

php artisan gateway:send ORD-XXXX payment.succeeded --id=evt_1   # received
php artisan gateway:send ORD-XXXX payment.succeeded --id=evt_1   # duplicate
php artisan gateway:send ORD-XXXX payment.succeeded --bad-signature   # 400 Invalid signature
php artisan gateway:send ORD-XXXX payment.succeeded --age=900         # 400 too old
php artisan gateway:send ORD-XXXX refund.completed
curl -s localhost:8093/api/orders/ORD-XXXX -H 'Accept: application/json'   # refunded
```

`gateway:send` builds a signed request exactly like a provider would.

## Tests

```bash
php artisan test
```

23 tests cover the signature checks (missing, malformed, wrong secret, tampered body, too old), duplicates, every state change, out-of-order events, failed events and replay, plus the outgoing call (idempotency key, retries, giving up, no retry on 4xx). CI runs Pint and the tests on PHP 8.2 and 8.3.

For how it all fits together, read [docs/HOW-IT-WORKS.md](docs/HOW-IT-WORKS.md).

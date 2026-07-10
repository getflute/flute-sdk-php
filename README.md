# Flute PHP SDK

Official **server-side** PHP SDK for the [Flute](https://developer.flute.com) payment
platform.

> **Server-side only.** The SDK uses an OAuth client secret. Never ship it to a
> browser, mobile app, or any environment an end user can inspect.

## Requirements

- PHP 8.1+
- Composer

## Installation

```bash
composer require flute/sdk
```

## Quick Start: Authenticate and Run a Sale

Authentication is automatic — the SDK acquires a bearer token on the first API call
and refreshes it as needed. Credentials come from your Flute merchant dashboard.

```php
<?php

require 'vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\Address;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;

$flute = new Flute([
    'clientId' => getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox', // or 'production'
]);

$result = $flute->transactions->saleTransaction(new SaleTransactionRequest(
    amount: 10.00,
    accountNumber: '4111111111111111',
    currencyId: 1, // USD
    expirationMonth: 12,
    expirationYear: 2030,
    securityCode: '123',
    // Sandbox AVS denies sales without a matching billing address.
    billingAddress: new Address(line1: '123 Test St', postalCode: '10001'),
    /*
     * Unique per order, but reuse the same value if you retry this charge.
     * Duplicate control is opt-in per merchant; see the error-handling notes.
     */
    referenceId: 'order-' . uniqid(),
));

echo "Transaction {$result->transactionId}: {$result->status}\n";
```

Amounts are PHP `float`s end-to-end — the wire format is a JSON number, same as
the official TypeScript SDK. Floats accumulate representation error, so round
before comparing or aggregating (`round($x, 2)`) and never compare raw amounts
with `===`: use `round($result->processedAmount ?? 0.0, 2) === round($expected, 2)`.

## Token Caching Across PHP-FPM Requests

PHP-FPM gives every request a fresh process, so the in-memory token cache lives only
as long as one SDK instance. To avoid re-authenticating on every request, cache the
token in your application (Redis, APCu, your framework's cache) and pass it back in:

```php
<?php

require 'vendor/autoload.php';

use Flute\Sdk\Flute;

// First request: acquire and store.
$flute = new Flute([
    'clientId' => getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);
$token = $flute->sessions->getAccessToken();
// yourCache()->set('flute_token', $token, 3000);

// Later request: reuse the cached token — no token call is made.
$flute = new Flute([
    'clientId' => getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
    'accessToken' => $token, // from yourCache()->get('flute_token')
]);

echo $flute->sessions->getAccessToken() === $token ? "reused\n" : "reacquired\n";
```

If the cached token has expired, the SDK recovers transparently: the first API call
gets a 401, the SDK acquires a fresh token, and retries the request once.

The cached token grants full API access until it expires — protect the cache like a
secret store, and never share tokens across merchants or environments.

## Webhook Signature Verification

Flute signs webhook deliveries with HMAC-SHA256. Verify with the three delivery
headers, the **exact raw request body**, and your endpoint's signing secret (shown
once when the endpoint is created):

```php
<?php

require 'vendor/autoload.php';

use Flute\Sdk\Flute;

$flute = new Flute([
    'clientId' => getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

// Values from the incoming webhook HTTP request:
$rawBody = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_FLUTE_WEBHOOK_SIGNATURE'] ?? '';
$webhookId = $_SERVER['HTTP_FLUTE_WEBHOOK_ID'] ?? '';
$timestamp = $_SERVER['HTTP_FLUTE_WEBHOOK_TIMESTAMP'] ?? '';
$secret = getenv('FLUTE_WEBHOOK_SECRET') ?: '';

// verify() throws on empty input; treat a malformed delivery as 400.
if ($signature === '' || $webhookId === '' || $timestamp === '' || $rawBody === '' || $secret === '') {
    http_response_code(400);
    exit;
}

/*
 * verify() checks the HMAC signature AND timestamp freshness in one call, so
 * replayed deliveries older than the 5-minute window are rejected by default.
 * (Use the lower-level verifySignature() only if you need the HMAC check alone.)
 */
if ($flute->webhooks->verify($signature, $webhookId, $timestamp, $rawBody, $secret)) {
    // Replay protection: a captured, validly-signed delivery can still be replayed
    // within the freshness window. Reject IDs you have already handled — persist
    // $webhookId in your own cache/DB with a TTL >= the freshness window:
    //   if (yourCache()->get('flute_wh_' . $webhookId)) { http_response_code(200); exit; }
    //   yourCache()->set('flute_wh_' . $webhookId, '1', 600);
    // process the event (idempotently, keyed on $webhookId)
    http_response_code(200);
} else {
    http_response_code(401);
}
```

Always pass the raw body **before** JSON parsing — re-encoding the parsed payload
changes byte order and breaks the HMAC.

- **Laravel:** `$request->getContent()`
- **Symfony:** `$request->getContent()`
- **WooCommerce/WordPress:** `file_get_contents('php://input')` inside the REST callback
- **Vanilla PHP:** `file_get_contents('php://input')`

**Replay protection.** `verify()` rejects deliveries older than the 5-minute
window (it combines the signature check with `isTimestampFresh()`; call the
latter directly only if you verified the signature separately). But a valid
delivery captured and replayed *within* that window still verifies. Persist each
`Flute-Webhook-Id` in your own cache or database with a TTL at least as long as
the freshness window, and treat a delivery whose ID you have already processed as
a duplicate — acknowledge it with `200` and do no further work. Process events
idempotently, keyed on the webhook ID.

## Listing Transactions With Filters

```php
<?php

require 'vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;

$flute = new Flute([
    'clientId' => getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

// page is zero-based: page 0 is the first (newest-first) page.
$page = $flute->transactions->listTransactions(new ListTransactionsRequest(
    page: 0,
    pageSize: 25,
));

echo "Total transactions: {$page->total}\n";
foreach ($page->items as $transaction) {
    /*
     * List rows key the identifier as "id" and get-by-id uses "transactionId";
     * both are fallback-mapped, so transactionId is reliable for either shape.
     */
    echo "{$transaction->transactionId} {$transaction->status}\n";
}
```

Pagination (`page`, `pageSize`) is the supported filter set in v1.0.

## Voiding a Transaction

Void cancels an authorized or unsettled transaction. Settled transactions must be
refunded instead (`refundTransaction`).

```php
<?php

require 'vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\Address;
use Flute\Sdk\Models\Requests\AuthorizeTransactionRequest;
use Flute\Sdk\Models\Requests\VoidTransactionRequest;

$flute = new Flute([
    'clientId' => getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

$auth = $flute->transactions->authorizeTransaction(new AuthorizeTransactionRequest(
    amount: 42.00,
    accountNumber: '4111111111111111',
    currencyId: 1,
    expirationMonth: 12,
    expirationYear: 2030,
    securityCode: '123',
    // Sandbox AVS denies card transactions without a matching billing address.
    billingAddress: new Address(line1: '123 Test St', postalCode: '10001'),
    /*
     * Unique per order, but reuse the same value if you retry this charge.
     * Duplicate control is opt-in per merchant; see the error-handling notes.
     */
    referenceId: 'order-' . uniqid(),
));

if ($auth->transactionId === null) {
    fwrite(STDERR, "Authorization did not return a transaction id (status: {$auth->status})." . PHP_EOL);
    exit(1);
}

$voided = $flute->transactions->voidTransaction(new VoidTransactionRequest(
    transactionId: $auth->transactionId,
));

echo "Voided {$voided->transactionId}: {$voided->status}\n";
```

## Refunding a Settled Transaction

Once a sale has settled it can no longer be voided — you refund it instead. Omit
`amount` for a full refund, or pass an `amount` for a partial one. The response
carries the refund's own `transactionId`; a refund is a separate transaction, not
a mutation of the parent sale.

Settlement happens on Flute's side as a processor-batch operation, not a
per-payment SDK call, so a freshly captured sale is not immediately refundable —
pass the id of a transaction you know has settled.

```php
<?php

require 'vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\RefundTransactionRequest;

$flute = new Flute([
    'clientId' => getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

$refund = $flute->transactions->refundTransaction(new RefundTransactionRequest(
    transactionId: $settledTransactionId,
    // amount: 10.00, // uncomment for a partial refund
));

echo "Refunded {$refund->transactionId}: {$refund->status}\n";
```

## Partner Workflows: Merchants and API Keys

Partners (ISVs) hold a **partner credential** that manages the merchants under
their account. The partner credential drives `$flute->merchants`; payment
processing uses each merchant's own credential. Create one `Flute` instance
per credential — instances are fully isolated.

```php
<?php

require 'vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\CreateMerchantApiKeyRequest;
use Flute\Sdk\Models\Requests\ListMerchantsRequest;

$partner = new Flute([
    'clientId' => getenv('FLUTE_PARTNER_CLIENT_ID'),
    'clientSecret' => getenv('FLUTE_PARTNER_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

// Find the merchant to onboard.
$found = $partner->merchants->listMerchants(new ListMerchantsRequest(search: 'cafe'));
$merchantId = (string) $found->items[0]->merchantId;

/*
 * Mint the merchant's API credential. The clientSecret is returned ONLY
 * here, at creation — store both values securely now.
 */
$key = $partner->merchants->createMerchantApiKey(new CreateMerchantApiKeyRequest(
    merchantId: $merchantId,
    tokenName: 'Cafe production key',
));

// The merchant processes payments with its own minted credential.
$merchant = new Flute([
    'clientId' => (string) $key->clientId,
    'clientSecret' => (string) $key->clientSecret,
    'environment' => 'sandbox',
]);

// Later: audit a merchant's keys (listings never include secrets) ...
$keys = $partner->merchants->listMerchantApiKeys($merchantId);

// ... and rotate: mint a replacement, deploy it, then revoke the old one.
// $partner->merchants->revokeMerchantApiKey($oldClientId, $merchantId);
```

Runnable versions live in `examples/partner/` (list, onboard, rotate). The
mutating examples are **self-cleaning** — they revoke the key they mint, so
they are safe to run repeatedly against sandbox.

## Configuration Reference

| Key | Required | Default | Purpose |
| --- | --- | --- | --- |
| `clientId` | yes | — | OAuth client ID from the merchant dashboard |
| `clientSecret` | yes | — | OAuth client secret |
| `environment` | yes | — | `'sandbox'`, `'production'`, or an `Environment` case |
| `tokenRefreshBufferSeconds` | no | `60` | Proactive refresh window before token expiry |
| `httpTimeoutSeconds` | no | `30` | Per-request timeout |
| `baseUrl` | no | per environment | API host override (proxies, testing) |
| `oauthBaseUrl` | no | per environment | OAuth host override |
| `accessToken` | no | — | Pre-supplied token for app-managed caching |
| `httpClient` | no | new Guzzle client | Injected `GuzzleHttp\ClientInterface` (mockable) — see **Custom HTTP Client** below; it governs TLS verification and sees plaintext card/credential bodies |

The SDK never reads environment variables itself — pass values explicitly.

When building requests, omit optional nested objects (for example a billing
`Address` or `ContactInfo`) rather than passing one with every field null — an
all-null object serializes as JSON `[]`, not `{}`.

### Custom HTTP Client

The `httpClient` key accepts any `GuzzleHttp\ClientInterface`. It exists mainly
for testing (inject a mock transport), but it is also the SDK's most
security-sensitive seam: the client you supply governs **all** SDK traffic,
including the card-bearing sale/auth POSTs and the OAuth token request, which
share it. The SDK is safe by default — its built-in client keeps Guzzle's
`verify => true` and never overrides it per request — but if you replace the
client:

- **Keep TLS certificate verification on.** A client built with `verify => false`
  (a common "fix my SSL error" copy-paste) silently disables certificate
  validation for card and credential traffic — never do this in production (PCI
  Req 4). A custom CA bundle (`verify => '/path/to/ca.pem'`) is fine and is
  preserved; the SDK does not force `verify` per request, precisely so it cannot
  clobber a deliberate custom bundle.
- **Attach no request/response logging middleware.** It observes the full
  plaintext request body — the PAN and CVV on a card call, and the OAuth
  `client_secret` on the token call — before transmission, writing cardholder data
  and credentials into your logs (PCI Req 3).

## Testing Your Integration

The same `httpClient` seam lets your unit tests run entirely offline: inject a
Guzzle client backed by `GuzzleHttp\Handler\MockHandler` and queue canned
responses — no credentials, no network. MockHandler ships with Guzzle itself
(the SDK requires `guzzlehttp/guzzle` `^7`), so nothing extra to install. Queue
the OAuth token response first — the SDK authenticates before the first API
call — then one response per API call:

```php
<?php

require 'vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$mock = new MockHandler([
    // 1. The OAuth token response the SDK requests transparently.
    new Response(200, [], (string) json_encode([
        'access_token' => 'test-token',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ])),
    // 2. The API response for the first SDK call.
    new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'items' => [['transactionId' => 'tx-1', 'status' => 'Settled']],
        'total' => 1,
    ])),
]);

$flute = new Flute([
    'clientId' => 'test-id',
    'clientSecret' => 'test-secret',
    'environment' => 'sandbox',
    'httpClient' => new Client(['handler' => HandlerStack::create($mock)]),
]);

$page = $flute->transactions->listTransactions(new ListTransactionsRequest(page: 0));

assert($page->total === 1);
assert($page->items[0]->transactionId === 'tx-1');
```

Drop the same shape into a PHPUnit test (`$this->assertSame(1, $page->total)`)
around your own integration code. To exercise your error paths, queue a non-2xx
response instead and assert the `FluteApiException`. Reusing one `Flute`
instance across calls needs only one token response — the token is cached for
the instance's lifetime.

## Error Handling

Every runtime SDK failure is a typed exception under the common base
`Flute\Sdk\Exceptions\FluteSdkException`; the one carve-out is programmer
errors — invalid constructor configuration and empty required IDs throw SPL
`\InvalidArgumentException`, outside the hierarchy:

| Exception | When |
| --- | --- |
| `FluteApiException` | API returned non-2xx. Exposes `getStatusCode()`, `getErrorCode()`, `getErrorDetails()`, `getCorrelationId()`, and `getRetryAfterSeconds()` (429 only) |
| `FluteAuthException` | Token acquisition failed, or 401 persisted after the automatic retry |
| `FluteNetworkException` | Transport failure (DNS, connect, timeout) |
| `FluteWebhookException` | `verify()`/`verifySignature()` called with missing/empty parameters |

```php
use Flute\Sdk\Exceptions\FluteApiException;

try {
    $flute->transactions->saleTransaction($request);
} catch (FluteApiException $e) {
    error_log(sprintf(
        'Sale failed: HTTP %d, code %s, correlation %s',
        $e->getStatusCode(),
        $e->getErrorCode() ?? 'n/a',
        $e->getCorrelationId() ?? 'n/a',
    ));
}
```

The SDK never retries 429 responses; `getRetryAfterSeconds()` surfaces the server's
requested delay so your application can schedule its own retry.

Retry safety depends on the operation, not just the error. 5xx and
`FluteNetworkException` are transient, but a timeout can fire after the request
reached Flute, leaving the outcome unknown. Retry reads (list/get) and other
idempotent calls freely; for **mutating** calls — `saleTransaction`,
`authorizeTransaction`, `captureTransaction`, `voidTransaction`,
`refundTransaction`, `createPaymentSession`, and merchant key create/revoke —
the SDK sends no idempotency key, because Flute does not offer one. A blind
retry can therefore double-charge or duplicate a key.

Flute has **no endpoint to look a transaction up by `referenceId`**, so you
cannot directly query whether an ambiguous sale processed. Reconcile before
retrying instead:

- **Sales/auth** — if the merchant has duplicate control enabled, resubmit
  with the **same** `referenceId` and the gateway rejects the duplicate. Flute
  builds the dedupe key as `{merchantId}-{amount}-{transactionType}-{PAN
  last4}[-{referenceId}]`, so a *reused* `referenceId` blocks the retry while a
  *fresh* one would let it through as a distinct charge. This is opt-in per
  merchant, so it is not a guaranteed safety net.
- **Otherwise** — page `listTransactions` and match your own `referenceId`
  client-side (there is no server-side filter yet), or reconcile out of band.
- **Merchant keys** — reconcile against the merchant key list before re-issuing.

The runnable `examples/07-handling-errors.php` is the full reference: the complete
catch ladder, a `withRetry()` helper that backs off on transient 5xx/network
failures (wrapping a read), and the 4xx-vs-5xx distinction. The other examples
stay on the happy path for readability.

**Security note.** SDK exception messages, the exception **chain**
(`getPrevious()` returns a `RedactedHttpException` carrying only method, path, and
status), and network-failure messages are all sanitized — they never embed the
Authorization header, request/response body, credentials, or card data, so they
are safe to log. As defense in depth, gateway-supplied error text and field-level
details are scrubbed of card and secret patterns before they reach the exception
(best-effort for free-form prose). Two practices keep this airtight:

- **Log the structured getters** — `getStatusCode()`, `getErrorCode()`,
  `getCorrelationId()` — rather than the whole exception object or its trace.
- **Set `zend.exception_ignore_args=On` in production.** PHP records each frame's
  call arguments in `getTrace()` (what Sentry/Bugsnag/Monolog deep-serialize). The
  SDK marks card and credential parameters `#[\SensitiveParameter]` (redacted on
  PHP 8.2+); the ini setting covers the PHP 8.1 floor and third-party frames.

The exact redaction mechanics (scrub levels, the `var_export()` caveat,
`RedactedHttpException` internals) are documented in
[`docs/portal-outline.md`](docs/portal-outline.md).

## Handling Cardholder Data (PCI)

The SDK **transmits** card data to Flute over TLS and **never stores** it: the
PAN and `securityCode` (CVV) live only on the in-memory request object, are
serialized into the POST body, and are then discarded. The SDK writes no card
data to disk, cache, or logs.

This reduces *retention and logging* risk, but it does **not** by itself put you
in a low PCI scope. Because PAN/CVV traverses your server in this direct-card
flow, that environment is in PCI DSS scope — typically SAQ D rather than the
outsourced SAQ A. Confirm the correct SAQ and the controls you owe with your QSA
or acquirer, and prefer a hosted or tokenized payment flow where available. With
that understood, keep card data out of *your* storage and logs:

- **Never persist a CVV** (PCI Req 3.2). Don't serialize, cache, or queue a
  card-bearing request object (`SaleTransactionRequest` /
  `AuthorizeTransactionRequest`) into a job queue or session. As defense in depth
  the SDK masks the generic serialization paths (`serialize()`, `json_encode()`,
  `var_dump()`, `print_r()`) — but `var_export()` it cannot mask, so never
  `var_export()` one. The same applies to `CreateMerchantApiKeyResponse` (one-time
  `clientSecret`) and `FluteConfig`. `toArray()`/direct getters are the only
  intended cleartext access.
- **Log structured getters, not whole objects** (see the security note above).
- **Set `zend.exception_ignore_args=On` in production** (covers the PHP 8.1 floor
  and third-party frames).
- **If you inject a custom `httpClient`,** keep TLS verification on and attach no
  request/response logging middleware — see [Custom HTTP Client](#custom-http-client)
  above.

## Contributing

Local development, the test suites, and DDEV setup are documented in
[CONTRIBUTING.md](CONTRIBUTING.md).

## Versioning

Semantic versioning from `1.0.0`. Breaking changes (removed methods, changed
signatures, changed exception types) require a major version bump.

## License

MIT

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/*
 * The other examples stay on the happy path for readability. This one is the
 * canonical reference for production error handling: every runtime SDK failure
 * is a typed exception under Flute\Sdk\Exceptions\FluteSdkException, so you catch
 * the specific types you can act on and let the rest surface.
 *
 *   FluteAuthException     bad credentials, or a 401 that persisted after the
 *                          SDK's automatic token refresh. Not retryable — fix
 *                          the credentials or the grant.
 *   FluteApiException      any non-2xx response. getStatusCode() tells you
 *                          whether it is your fault (4xx, permanent) or the
 *                          server's (5xx, transient and safe to retry).
 *   FluteNetworkException  transport failure (DNS, connect, timeout) — no
 *                          response was received. Transient, but a timeout can
 *                          land after the request reached Flute: safe to retry
 *                          reads, reconcile first before retrying a mutation.
 *   FluteWebhookException  verifySignature() called with missing/empty inputs
 *                          (see 03-webhook-verification.php; not network).
 *
 * One carve-out: programmer errors — invalid constructor configuration and
 * empty required IDs — throw SPL \InvalidArgumentException, outside the tree.
 */

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;
use Flute\Sdk\Models\Responses\ListTransactionsResponse;

$flute = new Flute([
    'clientId' => (string) getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

/*
 * Retry a call on transient failures (5xx and transport errors) with a simple
 * backoff. The SDK never retries for you (by design), so this is where your
 * application decides its own policy. 4xx and auth errors are re-thrown
 * immediately — retrying them only wastes time. In your own code this would be
 * a method; here it is a closure to keep the example a single runnable script.
 *
 * Safe here because the wrapped call is a read (listTransactions). Do NOT wrap
 * a mutating call (sale/authorize/capture/void/refund, payment-session
 * creation, merchant key create/revoke) this way: a 5xx or post-send timeout
 * has an unknown outcome and the SDK sends no idempotency key (Flute has none),
 * so a blind retry can double-charge. Flute has no lookup-by-referenceId
 * endpoint; reconcile before retrying — for sales, if the merchant enabled
 * duplicate control, resubmit with the SAME referenceId and the gateway rejects
 * the duplicate; otherwise page listTransactions and match referenceId yourself.
 */
$withRetry = static function (callable $operation, int $maxAttempts = 3): mixed {
    $attempt = 0;
    while (true) {
        $attempt++;
        try {
            return $operation();
        } catch (FluteApiException $e) {
            $transient = $e->getStatusCode() >= 500;
            if (!$transient || $attempt >= $maxAttempts) {
                throw $e;
            }
            /*
             * Only 5xx reaches here (429 is re-thrown above; rate-limit retries
             * are the app's responsibility, per the README). Flute sends no
             * Retry-After on 5xx, so this is linear backoff on the attempt count.
             */
            $delay = $e->getRetryAfterSeconds() ?? $attempt;
            fwrite(STDERR, "Transient HTTP {$e->getStatusCode()}; retry {$attempt} in {$delay}s." . PHP_EOL);
            sleep($delay);
        } catch (FluteNetworkException $e) {
            if ($attempt >= $maxAttempts) {
                throw $e;
            }
            fwrite(STDERR, "Transport error; retry {$attempt} in {$attempt}s: {$e->getMessage()}" . PHP_EOL);
            sleep($attempt);
        }
    }
};

/*
 * 1) The full catch ladder around a real call. The arms are siblings (all
 *    extend FluteSdkException), so order does not affect matching; keep the
 *    base class last only if you add a catch-all.
 */
try {
    /** @var ListTransactionsResponse $page */
    $page = $withRetry(static fn (): ListTransactionsResponse => $flute->transactions->listTransactions(
        new ListTransactionsRequest(page: 0, pageSize: 1),
    ));
    echo 'Connectivity OK — total transactions visible: '
        . ($page->total ?? count($page->items)) . PHP_EOL;
} catch (FluteAuthException $e) {
    fwrite(STDERR, 'Authentication failed — check FLUTE_CLIENT_ID/FLUTE_CLIENT_SECRET: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (FluteApiException $e) {
    // A 4xx is permanent: inspect the structured error and fix the request.
    fwrite(STDERR, sprintf(
        'API error: HTTP %d%s%s.' . PHP_EOL,
        $e->getStatusCode(),
        $e->getErrorCode() !== null ? ' code=' . $e->getErrorCode() : '',
        $e->getCorrelationId() !== null ? ' correlation=' . $e->getCorrelationId() : '',
    ));
    foreach ($e->getErrorDetails() as $field => $messages) {
        fwrite(STDERR, "  - {$field}: " . implode('; ', $messages) . PHP_EOL);
    }
    exit($e->getStatusCode() >= 500 ? 75 : 1); // 75 = EX_TEMPFAIL: a 5xx is retryable.
} catch (FluteNetworkException $e) {
    fwrite(STDERR, 'Could not reach Flute (transport error), retry later: ' . $e->getMessage() . PHP_EOL);
    exit(75);
}

/*
 * 2) A permanent (4xx) error, triggered and handled gracefully. A declined
 *    card is NOT this — that returns a result with a declined status. This is
 *    a request the API rejects outright: an unknown transaction id → 404.
 */
try {
    $flute->transactions->getTransaction('tx_does_not_exist_' . uniqid());
    echo 'Unexpected: the bogus transaction id resolved.' . PHP_EOL;
} catch (FluteApiException $e) {
    echo "Handled expected API error for a bad id: HTTP {$e->getStatusCode()}"
        . ($e->getErrorCode() !== null ? " ({$e->getErrorCode()})" : '') . '.' . PHP_EOL;
}

/*
 * Security note: getPrevious() on a mapped exception returns a sanitized
 * RedactedHttpException carrying only method, path, and HTTP status — never the
 * signed request (Authorization header or card data), so getPrevious() and the
 * messages are safe to log. Prefer the structured getters above over logging the
 * whole exception object: the exception's own getTrace() can still capture call
 * arguments unless zend.exception_ignore_args=On (the SDK marks card-data and
 * credential parameters #[\SensitiveParameter], active on PHP 8.2+).
 */
echo 'Done.' . PHP_EOL;

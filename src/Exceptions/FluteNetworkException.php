<?php

declare(strict_types=1);

namespace Flute\Sdk\Exceptions;

/**
 * Transport-level failure before a valid HTTP response was received — DNS,
 * connection refused, TLS, or timeout.
 *
 * Retry safety depends on the call, not the exception. A timeout can fire
 * *after* the request reached Flute but before the response got back, so the
 * outcome is unknown:
 *
 *  - Reads (list/get) and other idempotent calls: safe to retry directly.
 *  - Mutating calls (sale/authorize/capture/void/refund, payment-session
 *    creation, merchant key create/revoke): the SDK has no idempotency key, so
 *    retry only after reconciling the real state — by referenceId, a
 *    transaction lookup, or the merchant key list — to avoid duplicate charges
 *    or keys.
 */
final class FluteNetworkException extends FluteSdkException
{
}

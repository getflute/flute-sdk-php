<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

/**
 * Authorization-only transaction request (capture later via captureTransaction).
 *
 * Wire-required fields: amount, accountNumber, currencyId, expirationMonth,
 * expirationYear, cardDataSource. The SDK does not pre-validate; the API
 * returns a 4xx FluteApiException for missing/invalid fields.
 *
 * Shares its constructor and serialization with {@see SaleTransactionRequest}
 * via {@see AbstractCardTransactionRequest}.
 */
final class AuthorizeTransactionRequest extends AbstractCardTransactionRequest
{
}

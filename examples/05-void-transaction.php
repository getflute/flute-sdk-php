<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\Address;
use Flute\Sdk\Models\Requests\AuthorizeTransactionRequest;
use Flute\Sdk\Models\Requests\VoidTransactionRequest;

$flute = new Flute([
    'clientId' => (string) getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_CLIENT_SECRET'),
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
     * Duplicate control is opt-in per merchant (see 07-handling-errors.php).
     */
    referenceId: 'order-' . uniqid(),
    // Required under the sandbox merchant's zero-cost DualPricing mode: the
    // amount is charged as-is (see 01-sale.php).
    useCardPrice: true,
));

if ($auth->transactionId === null) {
    fwrite(STDERR, "Authorization did not return a transaction id (status: {$auth->status})." . PHP_EOL);
    exit(1);
}
echo "Authorized {$auth->transactionId}: {$auth->status}" . PHP_EOL;

$voided = $flute->transactions->voidTransaction(new VoidTransactionRequest(
    transactionId: $auth->transactionId,
));
echo "Voided {$voided->transactionId}: {$voided->status}" . PHP_EOL;

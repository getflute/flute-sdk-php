<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\Address;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;

$flute = new Flute([
    'clientId' => (string) getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

$result = $flute->transactions->saleTransaction(new SaleTransactionRequest(
    amount: 10.00,
    accountNumber: '4111111111111111',
    currencyId: 1,
    expirationMonth: 12,
    expirationYear: 2030,
    securityCode: '123',
    // Sandbox AVS denies sales without a matching billing address.
    billingAddress: new Address(line1: '123 Test St', postalCode: '10001'),
    /*
     * The sandbox merchant is configured for zero-cost DualPricing, which makes
     * this flag mandatory: true charges the amount as-is (it IS the card price);
     * false treats it as the cash price and adds the card-price uplift.
     * Merchants without a zero-cost option can omit it.
     */
    useCardPrice: true,
    /*
     * Unique per order, but reuse the same value if you retry this charge.
     * Duplicate control is opt-in per merchant (see 07-handling-errors.php).
     */
    referenceId: 'order-' . uniqid(),
));

// transactionId is nullable on the response type; a captured sale without one
// is broken output, so fail loudly rather than print a blank id.
if ($result->transactionId === null || $result->transactionId === '') {
    fwrite(STDERR, "Sale returned no transaction id (status: {$result->status})." . PHP_EOL);
    exit(1);
}
echo "Transaction {$result->transactionId}: {$result->status}" . PHP_EOL;

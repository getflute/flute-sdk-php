<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/*
 * Refunding a settled transaction.
 *
 * A refund returns funds on a sale that has already SETTLED. This is the line
 * between refund and void (05-void-transaction.php): void cancels a transaction
 * before settlement; once its batch settles you can no longer void it and must
 * refund instead.
 *
 * Settlement is a processor-batch operation on Flute's side, not a per-payment
 * SDK call — a fresh sale sits in an open batch as "Captured" until the batch
 * settles on the processor's schedule. The SDK's public surface deliberately
 * does not wrap batch settlement (it mirrors the TS SDK, which does not expose
 * it either), so this example does NOT create-and-settle a sale inline the way
 * the void example authorizes-then-voids. Instead it refunds a transaction you
 * already know is settled, supplied via FLUTE_REFUND_TX_ID. That keeps the
 * sample pure-SDK and shows exactly the call you write in production.
 */

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\RefundTransactionRequest;

$flute = new Flute([
    'clientId' => (string) getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

$transactionId = (string) getenv('FLUTE_REFUND_TX_ID');
if ($transactionId === '') {
    // Missing prerequisite: skip with a reason rather than fail (see the
    // settlement note above — a runnable sale→refund needs a batch settle that
    // is outside the SDK). Regression scenario H-7 exercises the full path.
    echo 'Set FLUTE_REFUND_TX_ID to a settled transaction id to run this refund example.' . PHP_EOL;
    exit(0);
}

/*
 * Omit amount for a full refund; pass amount for a partial refund. The response
 * carries the refund's OWN transactionId — a refund is a separate transaction
 * from the parent sale, not a mutation of it.
 */
$refund = $flute->transactions->refundTransaction(new RefundTransactionRequest(
    transactionId: $transactionId,
));

echo "Refunded {$refund->transactionId}: {$refund->status}" . PHP_EOL;

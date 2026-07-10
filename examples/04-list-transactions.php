<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;

$flute = new Flute([
    'clientId' => (string) getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

// page is zero-based: page 0 is the first (newest-first) page.
$page = $flute->transactions->listTransactions(new ListTransactionsRequest(page: 0, pageSize: 25));

if ($page->items === []) {
    echo 'No transactions yet.' . PHP_EOL;
    echo 'Run 01-sale.php first to create one, then list again.' . PHP_EOL;
    exit(0);
}

echo 'Total transactions: ' . ($page->total ?? count($page->items)) . PHP_EOL . PHP_EOL;

$format = "%-38s  %-17s  %-10s  %s" . PHP_EOL;
printf($format, 'ID', 'DATE', 'STATUS', 'AMOUNT');
foreach ($page->items as $transaction) {
    /*
     * Use the typed accessors. List rows key these fields differently on the
     * wire (id/date/currencyCode vs transactionId/transactionDateTime/currency
     * on get-by-id), but the DTO normalizes both shapes, so reads are uniform
     * and you never reach into toArray() for identifiers (divergence entry 21).
     */
    $date = substr(str_replace('T', ' ', (string) $transaction->transactionDateTime), 0, 16);
    $amount = $transaction->totalAmount === null
        ? ''
        : trim(number_format($transaction->totalAmount, 2) . ' ' . (string) $transaction->currency);
    printf($format, (string) $transaction->transactionId, $date, (string) $transaction->status, $amount);
}

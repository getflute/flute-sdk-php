<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\ListMerchantsRequest;

// Partner (ISV) credential — not a merchant-scoped key.
$flute = new Flute([
    'clientId' => (string) getenv('FLUTE_PARTNER_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_PARTNER_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

// page is zero-based: page 0 is the first page.
$page = $flute->merchants->listMerchants(new ListMerchantsRequest(page: 0, pageSize: 25));

if ($page->items === []) {
    echo 'No merchants visible to this partner credential.' . PHP_EOL;
    echo 'Merchants are provisioned by Flute under your partner account.' . PHP_EOL;
    exit(0);
}

echo 'Total merchants: ' . ($page->total ?? count($page->items)) . PHP_EOL . PHP_EOL;

$format = "%-38s  %s" . PHP_EOL;
printf($format, 'MERCHANT ID', 'COMPANY');
foreach ($page->items as $merchant) {
    printf($format, (string) $merchant->merchantId, (string) $merchant->companyName);
}

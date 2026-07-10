<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\CreateMerchantApiKeyRequest;
use Flute\Sdk\Models\Requests\ListMerchantsRequest;

// Partner (ISV) credential — not a merchant-scoped key.
$flute = new Flute([
    'clientId' => (string) getenv('FLUTE_PARTNER_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_PARTNER_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

// Rotation target. Real flows already know the merchant id.
$merchantId = (string) getenv('FLUTE_MERCHANT_ID');
if ($merchantId === '') {
    $merchants = $flute->merchants->listMerchants(new ListMerchantsRequest(pageSize: 1));
    if ($merchants->items === [] || $merchants->items[0]->merchantId === null) {
        echo 'No merchants visible to this partner credential.' . PHP_EOL;
        exit(0);
    }
    $merchantId = $merchants->items[0]->merchantId;
}

// Key listings never include secrets — only clientId, name, creation date.
$before = $flute->merchants->listMerchantApiKeys($merchantId);
echo 'Keys before rotation: ' . count($before->keys) . PHP_EOL;

// Rotate: mint the replacement first so the merchant is never keyless.
$replacement = $flute->merchants->createMerchantApiKey(new CreateMerchantApiKeyRequest(
    merchantId: $merchantId,
    tokenName: 'sdk-example rotation ' . date('Ymd-His'),
));

if ($replacement->clientId === null) {
    fwrite(STDERR, 'The API did not return a clientId.' . PHP_EOL);
    exit(1);
}
echo 'Minted replacement:   ' . $replacement->clientId . PHP_EOL;

/*
 * In a real rotation you would now deploy the new credential, confirm it
 * works, and only then revoke the OLD key by its clientId. This demo
 * revokes the key it just minted, so your existing keys are never touched.
 */
$flute->merchants->revokeMerchantApiKey($replacement->clientId, $merchantId);
echo 'Revoked demo key:     ' . $replacement->clientId . PHP_EOL;

$after = $flute->merchants->listMerchantApiKeys($merchantId);
echo 'Keys after demo:      ' . count($after->keys) . ' (net zero)' . PHP_EOL;

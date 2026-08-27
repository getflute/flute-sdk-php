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

/*
 * Pick the merchant to onboard. Real flows know this id from their own
 * records; the fallback grabs the first merchant so the demo runs as-is.
 */
$merchantId = (string) getenv('FLUTE_MERCHANT_ID');
if ($merchantId === '') {
    $merchants = $flute->merchants->listMerchants(new ListMerchantsRequest(pageSize: 1));
    if ($merchants->items === [] || $merchants->items[0]->merchantId === null) {
        echo 'No merchants visible to this partner credential.' . PHP_EOL;
        exit(0);
    }
    $merchantId = $merchants->items[0]->merchantId;
}

/*
 * Onboard: mint the merchant's API credential. This call is the whole
 * integration — store both values now; the secret is shown only once.
 */
$key = $flute->merchants->createMerchantApiKey(new CreateMerchantApiKeyRequest(
    merchantId: $merchantId,
    tokenName: 'sdk-example onboarding ' . date('Ymd-His'),
));

if ($key->clientId === null) {
    fwrite(STDERR, 'The API did not return a clientId.' . PHP_EOL);
    exit(1);
}

/*
 * The clientSecret is returned ONLY here, at creation — print it once so the
 * integrator can capture and store it, exactly as Flute's API surfaces it.
 * Printing it is deliberate: the secret is never retrievable again, so the
 * example must show the one place an integrator can capture it. It is a
 * sandbox credential that the demo revokes below.
 *
 * The print/revoke pair is wrapped so the demo cleanup always runs: if anything
 * throws after the key is minted, finally still revokes it, so a printed sandbox
 * secret can never outlive the run. Real onboarding ends at the mint
 * above — the merchant keeps the credential; only this demo revokes it.
 */
try {
    echo 'Merchant:      ' . $merchantId . PHP_EOL;
    echo 'Client ID:     ' . $key->clientId . PHP_EOL;
    echo 'Client secret: ' . (string) $key->clientSecret . PHP_EOL;
    echo '               (shown once — store it securely now)' . PHP_EOL;
} finally {
    $flute->merchants->revokeMerchantApiKey($key->clientId, $merchantId);
    echo 'Demo cleanup:  key revoked.' . PHP_EOL;
}

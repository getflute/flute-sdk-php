<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Integration;

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\CreateMerchantApiKeyRequest;
use Flute\Sdk\Models\Requests\ListMerchantsRequest;
use Flute\Sdk\Tests\Support\LiveTestCase;

/**
 * Live partner-endpoint coverage: merchant listing and the API-key
 * lifecycle. The lifecycle test revokes the key it mints even when
 * assertions fail, so reruns never accumulate orphan keys.
 */
final class MerchantsIntegrationTest extends LiveTestCase
{
    public function testListMerchantsReturnsTotalAndWellFormedItems(): void
    {
        $response = $this->flutePartner()->merchants->listMerchants(
            new ListMerchantsRequest(page: 0, pageSize: 10),
        );

        self::assertNotNull($response->total, 'total missing — check response field casing');
        foreach ($response->items as $merchant) {
            self::assertNotNull($merchant->merchantId);
            self::assertNotNull($merchant->companyName);
        }
    }

    public function testApiKeyLifecycleCreateListRevoke(): void
    {
        $flute = $this->flutePartner();
        $merchantId = $this->targetMerchantId($flute);

        $created = $flute->merchants->createMerchantApiKey(new CreateMerchantApiKeyRequest(
            merchantId: $merchantId,
            tokenName: 'sdk-integration lifecycle ' . uniqid(),
        ));
        $clientId = $created->clientId;
        if ($clientId === null || $clientId === '') {
            self::fail('createMerchantApiKey returned no clientId.');
        }
        try {
            self::assertNotNull($created->clientSecret, 'clientSecret must be returned at creation');
            $listed = $flute->merchants->listMerchantApiKeys($merchantId);
            self::assertContains($clientId, self::clientIds($listed->keys));
        } finally {
            // Always revoke — a failed assertion must not orphan the key.
            $flute->merchants->revokeMerchantApiKey($clientId, $merchantId);
        }

        $after = $flute->merchants->listMerchantApiKeys($merchantId);
        self::assertNotContains($clientId, self::clientIds($after->keys));
    }

    private function targetMerchantId(Flute $flute): string
    {
        $explicit = self::env('FLUTE_MERCHANT_ID');
        if ($explicit !== null) {
            return $explicit;
        }

        $merchants = $flute->merchants->listMerchants(new ListMerchantsRequest(pageSize: 1));
        $first = $merchants->items[0] ?? null;
        if ($first === null || $first->merchantId === null) {
            self::markTestSkipped('No merchant visible to the partner credential; set FLUTE_MERCHANT_ID.');
        }

        return $first->merchantId;
    }

    /**
     * @param list<\Flute\Sdk\Models\Responses\MerchantApiKey> $keys
     *
     * @return list<string>
     */
    private static function clientIds(array $keys): array
    {
        $ids = [];
        foreach ($keys as $key) {
            if ($key->clientId !== null) {
                $ids[] = $key->clientId;
            }
        }

        return $ids;
    }
}

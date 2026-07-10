<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Responses;

use Flute\Sdk\Models\Responses\CreateMerchantApiKeyResponse;
use Flute\Sdk\Models\Responses\ListMerchantApiKeysResponse;
use Flute\Sdk\Models\Responses\ListMerchantsResponse;
use Flute\Sdk\Models\Responses\MerchantApiKey;
use PHPUnit\Framework\TestCase;

final class MerchantResponsesTest extends TestCase
{
    public function testListMerchantsHydratesItemsAndTotal(): void
    {
        $response = ListMerchantsResponse::fromArray([
            'items' => [
                ['merchantId' => 'm-1', 'companyName' => 'Peppared Street Cafe'],
                'not-an-item',
            ],
            'total' => 4,
        ]);

        self::assertCount(1, $response->items);
        self::assertSame('m-1', $response->items[0]->merchantId);
        self::assertSame('Peppared Street Cafe', $response->items[0]->companyName);
        self::assertSame(4, $response->total);
    }

    public function testListMerchantsToleratesEmptyPayload(): void
    {
        $response = ListMerchantsResponse::fromArray([]);

        self::assertSame([], $response->items);
        self::assertNull($response->total);
        self::assertSame([], $response->toArray());
    }

    public function testApiKeysHydrateFromWireTokensArray(): void
    {
        $response = ListMerchantApiKeysResponse::fromArray([
            'tokens' => [[
                'merchantId' => 'm-1',
                'tokenName' => 'Cafe key',
                'clientId' => 'key-1',
                'creationDate' => '2026-02-19T20:24:52.934Z',
            ]],
        ]);

        self::assertCount(1, $response->keys);
        self::assertSame('m-1', $response->keys[0]->merchantId);
        self::assertSame('Cafe key', $response->keys[0]->tokenName);
        self::assertSame('key-1', $response->keys[0]->clientId);
        self::assertSame('2026-02-19T20:24:52.934Z', $response->keys[0]->creationDate);
        // toArray() keeps the raw wire shape, including the "tokens" name.
        self::assertArrayHasKey('tokens', $response->toArray());
    }

    public function testApiKeysTolerateMissingAndUnknownFields(): void
    {
        $response = ListMerchantApiKeysResponse::fromArray(['unexpected' => true]);
        self::assertSame([], $response->keys);

        $key = MerchantApiKey::fromArray(['clientId' => 'key-2', 'novel' => 'field']);
        self::assertSame('key-2', $key->clientId);
        self::assertNull($key->tokenName);
        self::assertNull($key->creationDate);
    }

    public function testCreateApiKeyExposesPairAndToleratesNullSecret(): void
    {
        $created = CreateMerchantApiKeyResponse::fromArray([
            'clientId' => 'key-3',
            'clientSecret' => 's3cret',
        ]);
        self::assertSame('key-3', $created->clientId);
        self::assertSame('s3cret', $created->clientSecret);

        $bare = CreateMerchantApiKeyResponse::fromArray(['clientId' => 'key-4']);
        self::assertNull($bare->clientSecret);
        self::assertSame(['clientId' => 'key-4'], $bare->toArray());
    }

    public function testCreateApiKeyDebugInfoMasksMintedSecret(): void
    {
        $created = CreateMerchantApiKeyResponse::fromArray([
            'clientId' => 'key-3',
            'clientSecret' => 'cs_live_SUPERSECRET',
        ]);

        // __debugInfo() masks both the typed property and its echo in `raw`
        // (the raw echo is scrubbed key-aware, so it carries the Redact mask).
        $debug = $created->__debugInfo();
        self::assertSame('key-3', $debug['clientId']);
        self::assertSame('***redacted***', $debug['clientSecret']);
        self::assertStringNotContainsString('SUPERSECRET', (string) json_encode($debug['raw']));

        ob_start();
        var_dump($created);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('cs_live_SUPERSECRET', $dump);

        // toArray() still returns the real secret for the one-time capture.
        self::assertSame('cs_live_SUPERSECRET', $created->toArray()['clientSecret']);
    }

    public function testCreateApiKeyMasksCredentialAliasesAndCasingInRaw(): void
    {
        // The retained raw payload is scrubbed key-aware, so casing variants,
        // alias credential keys, and future credential fields are masked too —
        // not only the exact `clientSecret` key (CR-08-7 S2).
        $created = CreateMerchantApiKeyResponse::fromArray([
            'clientId' => 'key-7',
            'clientSecret' => 'cs_live_SUPERSECRET',
            'ClientSecret' => 'CASESECRET',
            'secret' => 'OTHERSECRET',
            'accessToken' => 'TOKENSECRET',
        ]);

        foreach ([(string) json_encode($created), serialize($created)] as $output) {
            foreach (['cs_live_SUPERSECRET', 'CASESECRET', 'OTHERSECRET', 'TOKENSECRET'] as $secret) {
                self::assertStringNotContainsString($secret, $output);
            }
        }

        // clientId stays readable; toArray() remains the explicit cleartext path.
        self::assertSame('key-7', $created->__debugInfo()['clientId']);
        self::assertSame('CASESECRET', $created->toArray()['ClientSecret']);
    }

    public function testCreateApiKeyJsonEncodeAndSerializeDoNotLeakSecret(): void
    {
        $created = CreateMerchantApiKeyResponse::fromArray([
            'clientId' => 'key-5',
            'clientSecret' => 'cs_live_SUPERSECRET',
        ]);

        // __debugInfo only covered var_dump; json_encode/serialize must fail
        // closed too (the minted secret is a live credential).
        foreach ([(string) json_encode($created), serialize($created)] as $output) {
            self::assertStringNotContainsString('cs_live_SUPERSECRET', $output);
        }

        $restored = unserialize(serialize($created));
        self::assertInstanceOf(CreateMerchantApiKeyResponse::class, $restored);

        // toArray() still returns the real secret for the one-time capture.
        self::assertSame('cs_live_SUPERSECRET', $created->toArray()['clientSecret']);
    }
}

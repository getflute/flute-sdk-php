<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Resources;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Models\Requests\CreateMerchantApiKeyRequest;
use Flute\Sdk\Models\Requests\ListMerchantsRequest;
use Flute\Sdk\Tests\Support\MockFluteFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MerchantsResourceTest extends TestCase
{
    public function testListMerchantsSendsFiltersAndHydrates(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'items' => [['merchantId' => 'm-1', 'companyName' => 'Peppared Street Cafe']],
                'total' => 1,
            ]),
        ]);

        $response = $flute->merchants->listMerchants(
            new ListMerchantsRequest(page: 0, pageSize: 10, search: 'cafe'),
        );

        self::assertSame(1, $response->total);
        self::assertSame('m-1', $response->items[0]->merchantId);
        self::assertStringEndsWith(
            '/pay-api/v1/merchants?page=0&pageSize=10&search=cafe',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testListMerchantsWithoutRequestSendsNoQuery(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['items' => [], 'total' => 0]),
        ]);

        $response = $flute->merchants->listMerchants();

        self::assertSame([], $response->items);
        self::assertStringEndsWith(
            '/pay-api/v1/merchants',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testListMerchantApiKeysScopesByMerchantId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'tokens' => [['merchantId' => 'm-1', 'clientId' => 'key-1', 'tokenName' => 'Cafe key']],
            ]),
        ]);

        $response = $flute->merchants->listMerchantApiKeys('m-1');

        self::assertSame('key-1', $response->keys[0]->clientId);
        self::assertStringEndsWith(
            '/pay-api/v1/merchants/tokens?merchantId=m-1',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testListMerchantApiKeysWithoutMerchantIdSendsNoQuery(): void
    {
        // merchantId is optional: the unscoped partner-audit path lists keys
        // across every accessible merchant and must not append a query string.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'tokens' => [['merchantId' => 'm-9', 'clientId' => 'key-9', 'tokenName' => 'Audit key']],
            ]),
        ]);

        $response = $flute->merchants->listMerchantApiKeys();

        self::assertSame('key-9', $response->keys[0]->clientId);
        self::assertStringEndsWith(
            '/pay-api/v1/merchants/tokens',
            (string) $factory->history[1]['request']->getUri(),
        );
        self::assertSame('', $factory->history[1]['request']->getUri()->getQuery());
    }

    public function testCreateMerchantApiKeyPostsBodyAndReturnsPair(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['clientId' => 'key-1', 'clientSecret' => 's3cret']),
        ]);

        $created = $flute->merchants->createMerchantApiKey(new CreateMerchantApiKeyRequest(
            merchantId: 'm-1',
            tokenName: 'Cafe key',
        ));

        self::assertSame('key-1', $created->clientId);
        self::assertSame('s3cret', $created->clientSecret);
        $request = $factory->history[1]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('{"merchantId":"m-1","tokenName":"Cafe key"}', (string) $request->getBody());
        self::assertStringEndsWith('/pay-api/v1/merchants/tokens', (string) $request->getUri());
    }

    public function testCreateMerchantApiKeyEmptyBodyFailsClosed(): void
    {
        // A truncated 200 must never "succeed" with a null one-time clientSecret:
        // that would burn a key slot with nothing captured and no exception.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new Response(200, [], ''),
        ]);

        try {
            $flute->merchants->createMerchantApiKey(new CreateMerchantApiKeyRequest(
                merchantId: 'm-1',
                tokenName: 'Cafe key',
            ));
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(200, $e->getStatusCode());
            self::assertStringContainsString('empty body', $e->getMessage());
        }
    }

    public function testRevokeMerchantApiKeyToleratesEmptyBody(): void
    {
        // Fire-and-forget: the void revoke call must keep accepting an empty 2xx.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new Response(200, [], ''),
        ]);

        $flute->merchants->revokeMerchantApiKey('key-1', 'm-1');

        self::assertCount(2, $factory->history);
    }

    public function testRevokeMerchantApiKeySendsDeleteWithQuery(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new Response(200, [], ''),
        ]);

        $flute->merchants->revokeMerchantApiKey('key-1', 'm-1');

        $request = $factory->history[1]['request'];
        self::assertSame('DELETE', $request->getMethod());
        self::assertStringEndsWith(
            '/pay-api/v1/merchants/tokens/key-1?merchantId=m-1',
            (string) $request->getUri(),
        );
    }

    public function testRevokeMerchantApiKeyRawUrlEncodesHostileClientId(): void
    {
        // A path-injection-shaped clientId must land fully encoded; deleting the
        // rawurlencode() call would rewrite the request path/query.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new Response(200, [], ''),
        ]);

        $flute->merchants->revokeMerchantApiKey('key/1?admin=1%2F', 'm-1');

        self::assertSame(
            'https://sandbox.api.flute.com/pay-api/v1/merchants/tokens/key%2F1%3Fadmin%3D1%252F?merchantId=m-1',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testRevokeRejectsEmptyClientId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([]); // no network call may happen

        $this->expectException(\InvalidArgumentException::class);
        $flute->merchants->revokeMerchantApiKey('', 'm-1');
    }

    public function testRevokeRejectsEmptyMerchantId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([]); // no network call may happen

        $this->expectException(\InvalidArgumentException::class);
        $flute->merchants->revokeMerchantApiKey('key-1', '');
    }

    public function testNotFoundMapsToApiException(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['title' => 'Not Found', 'errorCode' => 'I0000'], 404),
        ]);

        try {
            $flute->merchants->listMerchantApiKeys('missing');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(404, $e->getStatusCode());
            self::assertSame('I0000', $e->getErrorCode());
        }
    }
}

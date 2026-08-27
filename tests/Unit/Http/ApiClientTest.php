<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Http;

use Flute\Sdk\Auth\TokenManager;
use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Exceptions\RedactedHttpException;
use Flute\Sdk\Http\ApiClient;
use Flute\Sdk\Version;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    private static function tokenResponse(string $token = 'tok-1'): Response
    {
        return new Response(200, [], (string) json_encode([
            'access_token' => $token,
            'expires_in' => 3600,
        ]));
    }

    /**
     * @param list<Response|ConnectException> $queue First queue item must be the token
     *   response, unless $presuppliedToken is set (then the first call uses it directly).
     */
    private function client(array $queue, ?string $presuppliedToken = null): ApiClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));  // @phpstan-ignore assign.propertyType
        $http = new Client(['handler' => $stack]);

        $tokens = new TokenManager(
            httpClient: $http,
            tokenUrl: 'https://oauth.test/oauth2/token',
            clientId: 'cid-1',
            clientSecret: 'sec-1',
            refreshBufferSeconds: 60,
            timeoutSeconds: 30,
            presuppliedToken: $presuppliedToken,
        );

        return new ApiClient(
            httpClient: $http,
            tokenManager: $tokens,
            apiBaseUrl: 'https://api.test',
            timeoutSeconds: 30,
        );
    }

    public function testGetSendsBearerAndUserAgentAndDecodesJson(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(200, [], (string) json_encode(['items' => [], 'total' => 0])),
        ]);

        $result = $client->get('/pay-api/v1/transactions', ['page' => 1]);

        self::assertSame(['items' => [], 'total' => 0], $result);
        // history[0] is the token call; history[1] is the API call
        $api = $this->history[1]['request'];
        self::assertSame('Bearer tok-1', $api->getHeaderLine('Authorization'));
        self::assertSame('flute-php-sdk/' . Version::VERSION, $api->getHeaderLine('User-Agent'));
        self::assertSame('application/json', $api->getHeaderLine('Accept'));
        self::assertSame('https://api.test/pay-api/v1/transactions?page=1', (string) $api->getUri());
        // The configured timeout must reach the wire request options.
        self::assertSame(30, $this->history[1]['options'][RequestOptions::TIMEOUT]);
    }

    public function testPostSendsJsonBodyAndExtraHeaders(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(200, [], (string) json_encode(['id' => 'ps-1'])),
        ]);

        $result = $client->post(
            '/pay-int-api/payment-sessions',
            ['amount' => 12.5],
            headers: ['x-api-version' => '1'],
        );

        self::assertSame(['id' => 'ps-1'], $result);
        $api = $this->history[1]['request'];
        self::assertSame('1', $api->getHeaderLine('x-api-version'));
        self::assertSame('{"amount":12.5}', (string) $api->getBody());
        self::assertSame('application/json', $api->getHeaderLine('Content-Type'));
    }

    public function testEmptyResponseBodyReturnsNull(): void
    {
        $client = $this->client([self::tokenResponse(), new Response(200, [], '')]);

        self::assertNull($client->post('/pay-int-api/payment-sessions/ps-1/cancel'));
    }

    public function testGetJsonEmptyBodyFailsClosed(): void
    {
        // A truncated/proxy-broken 200 with no body must not hydrate an all-null DTO.
        $client = $this->client([self::tokenResponse(), new Response(200, [], '')]);

        try {
            $client->getJson('/pay-api/v1/configurations/payments');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(200, $e->getStatusCode());
            self::assertStringContainsString('empty body', $e->getMessage());
        }
    }

    public function testPostJsonEmptyBodyFailsClosed(): void
    {
        $client = $this->client([self::tokenResponse(), new Response(200, [], '')]);

        $this->expectException(FluteApiException::class);
        $client->postJson('/pay-api/v1/merchants/tokens', ['merchantId' => 'm-1']);
    }

    public function testGetJsonReturnsDecodedBody(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(200, [], (string) json_encode(['ok' => true])),
        ]);

        self::assertSame(['ok' => true], $client->getJson('/pay-api/v1/transactions'));
    }

    public function testMalformed2xxBodyFailsClosed(): void
    {
        // A non-empty 2xx body that is not JSON must not silently become an empty DTO.
        $client = $this->client([
            self::tokenResponse(),
            new Response(200, [], '<html>upstream proxy error</html>'),
        ]);

        try {
            $client->get('/pay-api/v1/transactions/tx-1');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(200, $e->getStatusCode());
            // The malformed body is never echoed into the message.
            self::assertStringNotContainsString('upstream proxy error', $e->getMessage());
        }
    }

    public function testJsonScalar2xxBodyFailsClosed(): void
    {
        // Valid JSON that is not an object/array is still not a usable payload.
        $client = $this->client([self::tokenResponse(), new Response(200, [], '"ok"')]);

        $this->expectException(FluteApiException::class);
        $client->get('/pay-api/v1/transactions/tx-1');
    }

    public function testNon2xxMapsToApiExceptionWithPascalCaseEnvelope(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode([
                'StatusCode' => 400,
                'Title' => 'Validation error',
                'Details' => 'Amount is required',
                'ErrorCode' => 'V0000',
                'CorrelationId' => 'corr-1',
                'Errors' => ['amount' => ['Amount is required']],
            ])),
        ]);

        try {
            $client->post('/pay-api/v1/transactions/sale', []);
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('V0000', $e->getErrorCode());
            self::assertSame('corr-1', $e->getCorrelationId());
            self::assertSame(['amount' => ['Amount is required']], $e->getErrorDetails());
            self::assertStringContainsString('Validation error', $e->getMessage());
            self::assertStringContainsString('Amount is required', $e->getMessage());
            // getPrevious() never carries the signed request (Authorization, body).
            self::assertInstanceOf(RedactedHttpException::class, $e->getPrevious());
            self::assertNull($e->getPrevious()->getPrevious());
        }
    }

    public function testEnvelopeDetailsWithoutTitleFormatsCleanMessage(): void
    {
        // Details with no Title must not yield "...HTTP 400.: Amount is required".
        $client = $this->client([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode(['Details' => 'Amount is required'])),
        ]);

        try {
            $client->post('/pay-api/v1/transactions/sale', []);
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame('Flute API request failed with HTTP 400: Amount is required', $e->getMessage());
        }
    }

    public function testApiErrorTextAndDetailsRedactCardNumbers(): void
    {
        // If the gateway echoes a submitted PAN, it must not survive into the
        // exception message or the structured details.
        $client = $this->client([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode([
                'Title' => 'Validation error',
                'Details' => 'Card 4111111111111111 was declined',
                'Errors' => ['cardNumber' => ['4111 1111 1111 1111 is invalid']],
            ])),
        ]);

        try {
            $client->post('/pay-api/v1/transactions/sale', []);
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertStringNotContainsString('4111111111111111', $e->getMessage());
            $detailsJson = (string) json_encode($e->getErrorDetails());
            self::assertStringNotContainsString('4111111111111111', $detailsJson);
            self::assertStringNotContainsString('4111 1111 1111 1111', $detailsJson);
        }
    }

    public function testTopLevelDetailsRedactOpaqueTokensButKeepDiagnostics(): void
    {
        // Top-level Details has no field name to key off, so the CVV-length "123"
        // stays readable (it could be an amount/order number), but an opaque
        // token echoed by the gateway must not survive into the message.
        $client = $this->client([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode([
                'Title' => 'Authorization failed',
                'Details' => 'token abc123XYZ789def0 rejected for order 84219',
            ])),
        ]);

        try {
            $client->post('/pay-api/v1/transactions/sale', []);
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            $message = $e->getMessage();
            self::assertStringNotContainsString('abc123XYZ789def0', $message);
            foreach (['abc', 'XYZ', 'def'] as $fragment) {
                self::assertStringNotContainsString($fragment, $message);
            }
            // Diagnostic numbers preserved.
            self::assertStringContainsString('84219', $message);
            self::assertStringContainsString('Authorization failed', $message);
        }
    }

    public function testCorrelationIdSurvivesEvenWhenMaskedInMessageText(): void
    {
        // The opaque-token scrub masks a UUID-shaped correlation id if it appears
        // in free-text Details — but the structured getCorrelationId() reads the
        // dedicated envelope field, which is never redacted, so the primary
        // support diagnostic is not lost.
        $correlationId = '04a9afeb-7c1d-4e2a-9b3f-1a2b3c4d5e6f';
        $client = $this->client([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode([
                'Title' => 'Request failed',
                'Details' => "Contact support with correlation ID {$correlationId}",
                'CorrelationId' => $correlationId,
            ])),
        ]);

        try {
            $client->post('/pay-api/v1/transactions/sale', []);
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            // Masked in the free-text message...
            self::assertStringNotContainsString($correlationId, $e->getMessage());
            // ...but preserved verbatim on the structured getter.
            self::assertSame($correlationId, $e->getCorrelationId());
        }
    }

    public function testRateLimitExposesRetryAfter(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(429, ['Retry-After' => '17'], (string) json_encode(['Title' => 'Rate limited'])),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertSame(17, $e->getRetryAfterSeconds());
        }
    }

    public function testRateLimitParsesHttpDateRetryAfter(): void
    {
        // RFC 7231 allows an HTTP-date form. A date ~30s out should surface as a
        // small positive delay rather than null.
        $future = gmdate('D, d M Y H:i:s \G\M\T', time() + 30);
        $client = $this->client([
            self::tokenResponse(),
            new Response(429, ['Retry-After' => $future], (string) json_encode(['Title' => 'Rate limited'])),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            $retryAfter = $e->getRetryAfterSeconds();
            self::assertNotNull($retryAfter);
            self::assertGreaterThan(20, $retryAfter);
            self::assertLessThanOrEqual(30, $retryAfter);
        }
    }

    public function testRateLimitClampsPastHttpDateToZero(): void
    {
        // A date already in the past must not produce a negative delay.
        $past = gmdate('D, d M Y H:i:s \G\M\T', time() - 120);
        $client = $this->client([
            self::tokenResponse(),
            new Response(429, ['Retry-After' => $past], (string) json_encode(['Title' => 'Rate limited'])),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(0, $e->getRetryAfterSeconds());
        }
    }

    public function testRateLimitMalformedNumericRetryAfterIsNull(): void
    {
        // Values that are almost delta-seconds but not RFC-7231-legal: strtotime
        // would misparse each into a bogus epoch (e.g. "-30" -> ~30h). The
        // documented contract is null when the header is unparseable.
        foreach (['-30', '3.5', '+120'] as $header) {
            $client = $this->client([
                self::tokenResponse(),
                new Response(429, ['Retry-After' => $header], (string) json_encode(['Title' => 'Rate limited'])),
            ]);

            try {
                $client->get('/pay-api/v1/transactions');
                self::fail('Expected FluteApiException');
            } catch (FluteApiException $e) {
                self::assertNull($e->getRetryAfterSeconds(), "Retry-After: {$header} should parse to null");
            }
        }
    }

    public function testNonJsonErrorBodyStillMapsToApiException(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(502, [], '<html>Bad gateway</html>'),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(502, $e->getStatusCode());
            self::assertNull($e->getErrorCode());
        }
    }

    public function test503ServiceUnavailableMapsToApiExceptionAndIsNotRetried(): void
    {
        // The exact live-sandbox outage shape: plaintext body, no JSON envelope.
        $client = $this->client([
            self::tokenResponse(),
            new Response(503, [], 'unconditional drop overload'),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(503, $e->getStatusCode());
            self::assertNull($e->getErrorCode());
            self::assertNull($e->getRetryAfterSeconds());
            // getPrevious() is the sanitized context, never the Guzzle exception
            // (which would retain the signed request), and the chain stops there.
            self::assertInstanceOf(RedactedHttpException::class, $e->getPrevious());
            self::assertNull($e->getPrevious()->getPrevious());
        }

        // No 5xx retry (out of scope by design): exactly one API call after the token.
        self::assertCount(2, $this->history);
    }

    public function test401RefreshesTokenAndRetriesExactlyOnce(): void
    {
        $client = $this->client([
            self::tokenResponse('tok-old'),
            new Response(401, [], ''),
            self::tokenResponse('tok-new'),
            new Response(200, [], (string) json_encode(['ok' => true])),
        ]);

        $result = $client->get('/pay-api/v1/transactions');

        self::assertSame(['ok' => true], $result);
        self::assertCount(4, $this->history);
        $retried = $this->history[3]['request'];
        self::assertSame('Bearer tok-new', $retried->getHeaderLine('Authorization'));
    }

    public function testPresuppliedStaleTokenRecoversVia401AndRetriesOnce(): void
    {
        // The ISV-managed caching path: the app supplies its own (now expired)
        // token, so no token request precedes the first API call. The 401 then
        // clears it, acquires a fresh token, and retries exactly once.
        $client = $this->client(
            [
                new Response(401, [], ''),
                self::tokenResponse('tok-new'),
                new Response(200, [], (string) json_encode(['ok' => true])),
            ],
            presuppliedToken: 'tok-expired',
        );

        $result = $client->get('/pay-api/v1/transactions');

        self::assertSame(['ok' => true], $result);
        self::assertCount(3, $this->history);

        // First call is the API request itself (no token acquisition before it),
        // signed with the pre-supplied stale token.
        $first = $this->history[0]['request'];
        self::assertSame('https://api.test/pay-api/v1/transactions', (string) $first->getUri());
        self::assertSame('Bearer tok-expired', $first->getHeaderLine('Authorization'));

        // Then the token endpoint, then the retried API call with the new token.
        self::assertSame('https://oauth.test/oauth2/token', (string) $this->history[1]['request']->getUri());
        $retried = $this->history[2]['request'];
        self::assertSame('https://api.test/pay-api/v1/transactions', (string) $retried->getUri());
        self::assertSame('Bearer tok-new', $retried->getHeaderLine('Authorization'));
    }

    public function test401RetryReplaysOriginalPostBodyQueryAndHeaders(): void
    {
        // FR-2.4: the retry must replay the *original* request, not just re-auth.
        // A bodyless GET cannot prove that — most payment ops are POSTs — so this
        // pins that the JSON body, query string, and caller headers survive the
        // retry, with only the Authorization header changing.
        $client = $this->client([
            self::tokenResponse('tok-old'),
            new Response(401, [], ''),
            self::tokenResponse('tok-new'),
            new Response(200, [], (string) json_encode(['ok' => true])),
        ]);

        $result = $client->post(
            '/pay-api/v1/transactions/sale',
            ['amount' => 12.5, 'currencyId' => 1],
            query: ['dryRun' => 'true'],
            headers: ['x-api-version' => '1'],
        );

        self::assertSame(['ok' => true], $result);
        self::assertCount(4, $this->history);

        // history[0] token, [1] first API call (401), [2] token refresh, [3] retry.
        $first = $this->history[1]['request'];
        $retried = $this->history[3]['request'];

        foreach (['first' => $first, 'retried' => $retried] as $label => $request) {
            self::assertSame('POST', $request->getMethod(), "$label method");
            self::assertSame(
                'https://api.test/pay-api/v1/transactions/sale?dryRun=true',
                (string) $request->getUri(),
                "$label URI (path + query)",
            );
            self::assertSame(
                ['amount' => 12.5, 'currencyId' => 1],
                json_decode((string) $request->getBody(), true),
                "$label JSON body",
            );
            self::assertSame('1', $request->getHeaderLine('x-api-version'), "$label custom header");
        }

        // The only difference between the two requests is the bearer token.
        self::assertSame('Bearer tok-old', $first->getHeaderLine('Authorization'));
        self::assertSame('Bearer tok-new', $retried->getHeaderLine('Authorization'));
    }

    public function testPersistent401ThrowsAuthExceptionWithSanitizedPrevious(): void
    {
        $client = $this->client([
            self::tokenResponse('tok-old'),
            new Response(401, [], ''),
            self::tokenResponse('tok-new'),
            new Response(401, [], ''),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteAuthException');
        } catch (FluteAuthException $e) {
            self::assertInstanceOf(RedactedHttpException::class, $e->getPrevious());
            self::assertNull($e->getPrevious()->getPrevious());
        }
    }

    public function testTransportFailureThrowsNetworkExceptionWithSanitizedPrevious(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new ConnectException('timeout', new Request('GET', 'https://api.test/x')),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteNetworkException');
        } catch (FluteNetworkException $e) {
            // The Guzzle ConnectException retains the signed request; the SDK must
            // not pass it through as previous.
            self::assertInstanceOf(RedactedHttpException::class, $e->getPrevious());
            self::assertNull($e->getPrevious()->getPrevious());
        }
    }

    public function testNetworkExceptionMessageExcludesGuzzleTransportDetail(): void
    {
        // A leaky Guzzle message carrying the full URI, query, a token, and a
        // PAN. The top-level FluteNetworkException message must not echo any of
        // it — only the generic public string.
        $leaky = 'cURL error 7: Failed to connect to '
            . 'https://api.test/pay-api/v1/transactions?token=secret-xyz&pan=4111111111111111';
        $client = $this->client([
            self::tokenResponse(),
            new ConnectException($leaky, new Request('GET', 'https://api.test/x')),
        ]);

        try {
            $client->get('/pay-api/v1/transactions', ['token' => 'secret-xyz']);
            self::fail('Expected FluteNetworkException');
        } catch (FluteNetworkException $e) {
            self::assertSame('HTTP request failed before a response was received.', $e->getMessage());
            self::assertStringNotContainsString('secret-xyz', $e->getMessage());
            self::assertStringNotContainsString('4111111111111111', $e->getMessage());
            self::assertStringNotContainsString('https://', $e->getMessage());
            self::assertStringNotContainsString('?', $e->getMessage());
        }
    }

    public function testErrorDetailsStringValueCoercedToList(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode([
                'Title' => 'Validation error',
                'Errors' => ['amount' => 'required'],
            ])),
        ]);

        try {
            $client->post('/pay-api/v1/transactions/sale', []);
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame(['amount' => ['required']], $e->getErrorDetails());
        }
    }

    public function testErrorDetailsIntKeysDropped(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode([
                'Title' => 'Validation error',
                'Errors' => ['a', 'b'],
            ])),
        ]);

        try {
            $client->post('/pay-api/v1/transactions/sale', []);
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame([], $e->getErrorDetails());
        }
    }

    public function testErrorDetailsNonStringListMembersFiltered(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode([
                'Title' => 'Validation error',
                'Errors' => ['amount' => ['x', 42]],
            ])),
        ]);

        try {
            $client->post('/pay-api/v1/transactions/sale', []);
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame(['amount' => ['x']], $e->getErrorDetails());
        }
    }

    public function testTokenEndpointFailureIsNotRetried(): void
    {
        // The token call itself returns 401; zero API requests should be made.
        $client = $this->client([new Response(401, [], '')]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteAuthException');
        } catch (FluteAuthException $e) {
            self::assertCount(1, $this->history);
        }
    }

    public function testRetryTokenAcquisitionFailurePropagates(): void
    {
        // API 401 triggers token refresh; the re-acquisition itself fails.
        $client = $this->client([
            self::tokenResponse('tok-old'),
            new Response(401, [], ''),
            new Response(401, [], ''),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteAuthException');
        } catch (FluteAuthException $e) {
            self::assertCount(3, $this->history);
        }
    }

    public function testRedirectThrowsApiExceptionAndIsNotFollowed(): void
    {
        $client = $this->client([
            self::tokenResponse(),
            new Response(
                302,
                ['Location' => 'https://elsewhere.test/pay-api/v1/transactions'],
                (string) json_encode(['next' => 'https://elsewhere.test']),
            ),
            // Would be consumed if the redirect were followed.
            new Response(200, [], (string) json_encode(['ok' => true])),
        ]);

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(302, $e->getStatusCode());
            self::assertCount(2, $this->history);
        }
    }

    public function testRedirectStatusMappedEvenWithoutHttpErrorsMiddleware(): void
    {
        // Bare stack: no http_errors and no redirect middleware.
        $mock = new MockHandler([
            self::tokenResponse(),
            new Response(302, ['Location' => 'https://elsewhere.test/x'], ''),
        ]);
        $this->history = [];
        $bareStack = new HandlerStack($mock);
        $bareStack->push(Middleware::history($this->history));  // @phpstan-ignore assign.propertyType
        $http = new Client(['handler' => $bareStack]);

        $tokens = new TokenManager(
            httpClient: $http,
            tokenUrl: 'https://oauth.test/oauth2/token',
            clientId: 'cid-1',
            clientSecret: 'sec-1',
            refreshBufferSeconds: 60,
            timeoutSeconds: 30,
        );

        $client = new ApiClient(
            httpClient: $http,
            tokenManager: $tokens,
            apiBaseUrl: 'https://api.test',
            timeoutSeconds: 30,
        );

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(302, $e->getStatusCode());
        }
    }

    public function testErrorStatusMappedEvenWithoutHttpErrorsMiddleware(): void
    {
        // Build a bare stack: no HandlerStack::create(), so no http_errors middleware.
        $mock = new MockHandler([
            self::tokenResponse(),
            new Response(400, [], (string) json_encode(['Title' => 'Bad request'])),
        ]);
        $this->history = [];
        $bareStack = new HandlerStack($mock);
        $bareStack->push(Middleware::history($this->history));  // @phpstan-ignore assign.propertyType
        $http = new Client(['handler' => $bareStack]);

        $tokens = new TokenManager(
            httpClient: $http,
            tokenUrl: 'https://oauth.test/oauth2/token',
            clientId: 'cid-1',
            clientSecret: 'sec-1',
            refreshBufferSeconds: 60,
            timeoutSeconds: 30,
        );

        $client = new ApiClient(
            httpClient: $http,
            tokenManager: $tokens,
            apiBaseUrl: 'https://api.test',
            timeoutSeconds: 30,
        );

        try {
            $client->get('/pay-api/v1/transactions');
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(400, $e->getStatusCode());
        }
    }

    public function testDeleteSendsQueryAndReturnsNullOnEmptyBody(): void
    {
        $client = $this->client([self::tokenResponse(), new Response(200, [], '')]);

        $result = $client->delete('/pay-api/v1/merchants/tokens/key-1', ['merchantId' => 'm-1']);

        self::assertNull($result);
        $api = $this->history[1]['request'];
        self::assertSame('DELETE', $api->getMethod());
        self::assertSame('Bearer tok-1', $api->getHeaderLine('Authorization'));
        self::assertSame(
            'https://api.test/pay-api/v1/merchants/tokens/key-1?merchantId=m-1',
            (string) $api->getUri(),
        );
    }
}

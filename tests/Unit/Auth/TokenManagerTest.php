<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Auth;

use Flute\Sdk\Auth\TokenManager;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Exceptions\RedactedHttpException;
use Flute\Sdk\Flute;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenManagerTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /**
     * @param list<Response|ConnectException> $queue
     * @param (callable(): int)|null $now clock override forwarded to the constructor
     * @param array<string, mixed> $clientConfig extra Guzzle options merged into the client (e.g. http_errors)
     */
    private function manager(
        array $queue,
        ?string $presupplied = null,
        int $bufferSeconds = 60,
        ?callable $now = null,
        array $clientConfig = [],
    ): TokenManager {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));  // @phpstan-ignore assign.propertyType

        return new TokenManager(
            httpClient: new Client(['handler' => $stack] + $clientConfig),
            tokenUrl: 'https://oauth.test/oauth2/token',
            clientId: 'cid-1',
            clientSecret: 'sec-1',
            refreshBufferSeconds: $bufferSeconds,
            timeoutSeconds: 30,
            presuppliedToken: $presupplied,
            now: $now,
        );
    }

    private static function tokenResponse(string $token, int $expiresIn = 3600): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => $token,
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ]));
    }

    public function testLazyAcquisitionSendsClientCredentials(): void
    {
        $manager = $this->manager([self::tokenResponse('tok-1')]);

        self::assertSame('tok-1', $manager->getAccessToken());
        self::assertCount(1, $this->history);

        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://oauth.test/oauth2/token', (string) $request->getUri());
        // The configured timeout must reach the wire request options.
        self::assertSame(30, $this->history[0]['options'][RequestOptions::TIMEOUT]);

        parse_str((string) $request->getBody(), $body);
        self::assertSame('client_credentials', $body['grant_type']);
        self::assertSame('cid-1', $body['client_id']);
        self::assertSame('sec-1', $body['client_secret']);
    }

    public function testTokenIsCachedAcrossCalls(): void
    {
        $manager = $this->manager([self::tokenResponse('tok-1')]);

        $manager->getAccessToken();
        $manager->getAccessToken();

        self::assertCount(1, $this->history);
    }

    public function testShortLivedTokenIsCachedDespiteOversizedBuffer(): void
    {
        // expires_in 30s with a 60s buffer previously made the token "already
        // expiring" on issue, forcing a refresh on every read. The buffer is now
        // clamped below the lifetime (to half-life), so the fresh token is cached
        // and no second acquisition happens.
        $manager = $this->manager([
            self::tokenResponse('tok-1', 30),
            self::tokenResponse('tok-2'),
        ]);

        self::assertSame('tok-1', $manager->getAccessToken());
        self::assertSame('tok-1', $manager->getAccessToken());
        self::assertCount(1, $this->history);
    }

    public function testProactiveRefreshWithinBufferWindowReacquires(): void
    {
        // Re-acquire *before* expiry rather than waiting for a reactive
        // 401. Drive a controllable clock so the buffer window is crossed without
        // a real sleep.
        $clock = 1_000_000;
        $now = function () use (&$clock): int {
            return $clock;
        };

        $manager = $this->manager(
            [
                self::tokenResponse('tok-1', 100),
                self::tokenResponse('tok-2', 100),
            ],
            now: $now,
        );

        // expires_in 100 with a 60s buffer clamps the effective buffer to the
        // half-life (50s). The fresh token is cached...
        self::assertSame('tok-1', $manager->getAccessToken());
        self::assertCount(1, $this->history);

        // ...still cached at +49s (51s of life left, just outside the 50s buffer)...
        $clock += 49;
        self::assertSame('tok-1', $manager->getAccessToken());
        self::assertCount(1, $this->history);

        // ...and proactively re-acquired once inside the buffer window (49s left),
        // without any 401 having occurred.
        $clock += 2;
        self::assertSame('tok-2', $manager->getAccessToken());
        self::assertCount(2, $this->history);
    }

    public function testPresuppliedTokenSkipsAcquisition(): void
    {
        $manager = $this->manager([], presupplied: 'tok-pre');

        self::assertSame('tok-pre', $manager->getAccessToken());
        self::assertCount(0, $this->history);
    }

    public function testRefreshForcesNewTokenEvenWhenCached(): void
    {
        $manager = $this->manager([
            self::tokenResponse('tok-1'),
            self::tokenResponse('tok-2'),
        ]);

        self::assertSame('tok-1', $manager->getAccessToken());
        self::assertSame('tok-2', $manager->refresh());
        self::assertSame('tok-2', $manager->getAccessToken());
    }

    public function testFailedRefreshDropsCachedTokenAndReacquiresNext(): void
    {
        // Fail closed: after a failed forced refresh, the old (possibly revoked)
        // token must not be served from cache — the next call re-authenticates.
        $manager = $this->manager([
            self::tokenResponse('tok-1'),
            new Response(500, [], ''),
            self::tokenResponse('tok-2'),
        ]);

        self::assertSame('tok-1', $manager->getAccessToken());

        try {
            $manager->refresh();
            self::fail('Expected FluteAuthException');
        } catch (FluteAuthException $e) {
            self::assertStringContainsString('500', $e->getMessage());
        }

        self::assertSame('tok-2', $manager->getAccessToken());
        self::assertCount(3, $this->history);
    }

    public function testClearDropsCachedToken(): void
    {
        $manager = $this->manager([
            self::tokenResponse('tok-1'),
            self::tokenResponse('tok-2'),
        ]);

        $manager->getAccessToken();
        $manager->clear();

        self::assertSame('tok-2', $manager->getAccessToken());
        self::assertCount(2, $this->history);
    }

    public function testClearDropsPresuppliedToken(): void
    {
        $manager = $this->manager([self::tokenResponse('tok-1')], presupplied: 'tok-pre');

        $manager->clear();

        self::assertSame('tok-1', $manager->getAccessToken());
    }

    public function testHttpErrorThrowsAuthExceptionWithoutLeakingSecret(): void
    {
        $manager = $this->manager([
            new Response(401, [], (string) json_encode(['error' => 'invalid_client'])),
        ]);

        try {
            $manager->getAccessToken();
            self::fail('Expected FluteAuthException');
        } catch (FluteAuthException $e) {
            self::assertStringNotContainsString('sec-1', $e->getMessage());
            self::assertStringContainsString('401', $e->getMessage());
            // The Guzzle exception holds the token request body (client_secret);
            // the SDK attaches a sanitized context instead.
            self::assertInstanceOf(RedactedHttpException::class, $e->getPrevious());
            self::assertStringNotContainsString('sec-1', $e->getPrevious()->getMessage());
            self::assertNull($e->getPrevious()->getPrevious());
        }
    }

    public function testTransportFailureThrowsNetworkException(): void
    {
        // Leaky Guzzle message carrying the token URL and the client secret.
        $leaky = 'cURL error 7: Failed to connect to '
            . 'https://oauth.test/oauth2/token?client_secret=sec-1';
        $manager = $this->manager([
            new ConnectException($leaky, new Request('POST', 'https://oauth.test/oauth2/token')),
        ]);

        try {
            $manager->getAccessToken();
            self::fail('Expected FluteNetworkException');
        } catch (FluteNetworkException $e) {
            self::assertSame('Token endpoint unreachable.', $e->getMessage());
            self::assertStringNotContainsString('sec-1', $e->getMessage());
            self::assertStringNotContainsString('oauth.test', $e->getMessage());
            self::assertStringNotContainsString('https://', $e->getMessage());
            self::assertInstanceOf(RedactedHttpException::class, $e->getPrevious());
            self::assertNull($e->getPrevious()->getPrevious());
        }
    }

    public function testHttpErrorWithoutHttpErrorsOptionStillThrowsAuthException(): void
    {
        $manager = $this->manager(
            [new Response(401, [], (string) json_encode(['error' => 'invalid_client']))],
            clientConfig: ['http_errors' => false],
        );

        try {
            $manager->getAccessToken();
            self::fail('Expected FluteAuthException');
        } catch (FluteAuthException $e) {
            self::assertStringContainsString('401', $e->getMessage());
            self::assertStringNotContainsString('sec-1', $e->getMessage());
        }
    }

    public function testRedirectResponseFailsClosed(): void
    {
        // Token-shaped bodies on a 302 must never be cached as valid tokens.
        $redirect = static fn (): Response => new Response(
            302,
            ['Location' => 'https://elsewhere.test/token', 'Content-Type' => 'application/json'],
            (string) json_encode(['access_token' => 'tok-evil', 'expires_in' => 3600]),
        );
        $manager = $this->manager([$redirect(), $redirect()]);

        try {
            $manager->getAccessToken();
            self::fail('Expected FluteAuthException');
        } catch (FluteAuthException $e) {
            self::assertCount(1, $this->history);
            self::assertStringContainsString('302', $e->getMessage());
            self::assertStringNotContainsString('sec-1', $e->getMessage());
        }

        // Nothing cached: the next call re-attempts acquisition and fails again.
        $this->expectException(FluteAuthException::class);
        $manager->getAccessToken();
    }

    #[DataProvider('malformedPayloadProvider')]
    public function testMalformedTokenPayloadThrowsAuthException(string $body): void
    {
        $manager = $this->manager([
            new Response(200, ['Content-Type' => 'application/json'], $body),
        ]);

        $this->expectException(FluteAuthException::class);
        $manager->getAccessToken();
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPayloadProvider(): iterable
    {
        yield 'not json'                 => ['not json'];
        yield 'missing access_token'     => [(string) json_encode(['unexpected' => true])];
        yield 'empty access_token'       => [(string) json_encode(['access_token' => '', 'expires_in' => 3600])];
        yield 'non-numeric expires_in'   => [(string) json_encode(['access_token' => 'tok', 'expires_in' => 'soon'])];
        yield 'empty string expires_in'  => [(string) json_encode(['access_token' => 'tok', 'expires_in' => ''])];
        // Non-positive expires_in would make the token "already stale" and force
        // a re-acquire on every request; reject it as malformed.
        yield 'zero expires_in'          => [(string) json_encode(['access_token' => 'tok', 'expires_in' => 0])];
        yield 'string zero expires_in'   => [(string) json_encode(['access_token' => 'tok', 'expires_in' => '0'])];
        yield 'negative expires_in'      => [(string) json_encode(['access_token' => 'tok', 'expires_in' => -5])];
        yield 'string negative expires_in' => [(string) json_encode(['access_token' => 'tok', 'expires_in' => '-1'])];
    }

    #[DataProvider('numericExpiresInProvider')]
    public function testNumericStringAndFloatExpiresInAccepted(string|float $expiresIn): void
    {
        // RFC-sloppy serializers send expires_in as "3600" or 3600.0; both must
        // cache with the same expiry math as the integer form.
        $clock = 1_000_000;
        $now = function () use (&$clock): int {
            return $clock;
        };

        $manager = $this->manager(
            [
                new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'access_token' => 'tok-1',
                    'expires_in' => $expiresIn,
                ])),
                self::tokenResponse('tok-2'),
            ],
            now: $now,
        );

        self::assertSame('tok-1', $manager->getAccessToken());

        // Expiry at +3600 with a 60s buffer: still cached at +3539 (61s left)...
        $clock += 3539;
        self::assertSame('tok-1', $manager->getAccessToken());
        self::assertCount(1, $this->history);

        // ...and re-acquired once inside the buffer window (59s left).
        $clock += 2;
        self::assertSame('tok-2', $manager->getAccessToken());
        self::assertCount(2, $this->history);
    }

    /** @return iterable<string, array{string|float}> */
    public static function numericExpiresInProvider(): iterable
    {
        yield 'string expires_in' => ['3600'];
        yield 'float expires_in'  => [3600.0];
    }

    public function testGenericSerializationMasksClientSecretAndToken(): void
    {
        $manager = $this->manager([self::tokenResponse('tok-SUPERSECRET')]);
        // Populate the cache so the masked view has a live token to hide.
        self::assertSame('tok-SUPERSECRET', $manager->getAccessToken());

        // Every maskable generic path — var_export has no hook (ADR 0010) and is
        // documented as unsafe.
        ob_start();
        var_dump($manager);
        $varDump = (string) ob_get_clean();

        $views = [
            'serialize' => serialize($manager),
            'print_r' => print_r($manager, true),
            'var_dump' => $varDump,
        ];
        foreach ($views as $path => $output) {
            self::assertStringNotContainsString('sec-1', $output, "clientSecret leaked via {$path}");
            self::assertStringNotContainsString('tok-SUPERSECRET', $output, "token leaked via {$path}");
        }

        // The manager still serves the real token after being dumped.
        self::assertSame('tok-SUPERSECRET', $manager->getAccessToken());
    }

    public function testUnserializeRestoresFailClosedManager(): void
    {
        $manager = $this->manager([self::tokenResponse('tok-SUPERSECRET')]);
        $manager->getAccessToken();

        // Round trip works, and no secret or usable token survives it.
        $restored = unserialize(serialize($manager));

        self::assertInstanceOf(TokenManager::class, $restored);
        $dump = print_r($restored, true);
        self::assertStringNotContainsString('tok-SUPERSECRET', $dump);
        self::assertStringNotContainsString('sec-1', $dump);
    }

    public function testDumpingFluteClientGraphDoesNotLeakSecretOrToken(): void
    {
        // The Flute facade reaches the TokenManager through every resource; the
        // most likely debugging dump must mask both credentials transitively.
        $flute = new Flute([
            'clientId' => 'cid-1',
            'clientSecret' => 'sec_SUPERSECRET',
            'environment' => 'sandbox',
            'accessToken' => 'tok_SUPERSECRET',
        ]);

        ob_start();
        var_dump($flute);
        $varDump = (string) ob_get_clean();

        foreach (['print_r' => print_r($flute, true), 'var_dump' => $varDump] as $path => $output) {
            self::assertStringNotContainsString('sec_SUPERSECRET', $output, "clientSecret leaked via {$path}");
            self::assertStringNotContainsString('tok_SUPERSECRET', $output, "accessToken leaked via {$path}");
        }
    }
}

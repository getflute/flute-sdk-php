<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Support;

use Flute\Sdk\Flute;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Builds Flute clients backed by a Guzzle MockHandler for unit tests.
 */
final class MockFluteFactory
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    public array $history = [];

    public static function tokenResponse(string $token = 'tok-1', int $expiresIn = 3600): Response
    {
        return new Response(200, [], (string) json_encode([
            'access_token' => $token,
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ]));
    }

    /** @param array<string, mixed> $data */
    public static function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    /**
     * @param list<Response|ConnectException> $queue
     * @param array<string, mixed> $configOverrides
     */
    public function flute(array $queue, array $configOverrides = []): Flute
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));  // @phpstan-ignore assign.propertyType

        return new Flute($configOverrides + [
            'clientId' => 'cid-test',
            'clientSecret' => 'sec-test',
            'environment' => 'sandbox',
            'httpClient' => new Client(['handler' => $stack]),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Resources;

use Flute\Sdk\Tests\Support\MockFluteFactory;
use PHPUnit\Framework\TestCase;

final class SessionsResourceTest extends TestCase
{
    public function testAuthenticateAcquiresAndReturnsToken(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([MockFluteFactory::tokenResponse('tok-1')]);

        self::assertSame('tok-1', $flute->sessions->authenticate());
        self::assertCount(1, $factory->history);
    }

    public function testGetAccessTokenReturnsCachedTokenWithoutSecondCall(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([MockFluteFactory::tokenResponse('tok-1')]);

        $flute->sessions->authenticate();

        self::assertSame('tok-1', $flute->sessions->getAccessToken());
        self::assertCount(1, $factory->history);
    }

    public function testPresuppliedAccessTokenReturnedWithoutNetwork(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([], ['accessToken' => 'tok-pre']);

        self::assertSame('tok-pre', $flute->sessions->getAccessToken());
        self::assertCount(0, $factory->history);
    }

    public function testRefreshAccessTokenForcesNewToken(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse('tok-1'),
            MockFluteFactory::tokenResponse('tok-2'),
        ]);

        self::assertSame('tok-1', $flute->sessions->getAccessToken());
        self::assertSame('tok-2', $flute->sessions->refreshAccessToken());
        self::assertSame('tok-2', $flute->sessions->getAccessToken());
    }

    public function testClearStoredTokenForcesReauthentication(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse('tok-1'),
            MockFluteFactory::tokenResponse('tok-2'),
        ]);

        $flute->sessions->getAccessToken();
        $flute->sessions->clearStoredToken();

        self::assertSame('tok-2', $flute->sessions->getAccessToken());
        self::assertCount(2, $factory->history);
    }
}

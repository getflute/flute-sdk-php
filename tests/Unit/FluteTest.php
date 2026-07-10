<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit;

use Flute\Sdk\Flute;
use Flute\Sdk\Resources\MerchantsResource;
use Flute\Sdk\Resources\PaymentSessionsResource;
use Flute\Sdk\Resources\SessionsResource;
use Flute\Sdk\Resources\SettingsResource;
use Flute\Sdk\Resources\TransactionsResource;
use Flute\Sdk\Tests\Support\MockFluteFactory;
use Flute\Sdk\Version;
use PHPUnit\Framework\TestCase;

final class FluteTest extends TestCase
{
    public function testConstructorWiresResourcesWithoutNetworkCalls(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([]); // empty queue: any network call would throw

        self::assertInstanceOf(SessionsResource::class, $flute->sessions);
        self::assertInstanceOf(TransactionsResource::class, $flute->transactions);
        self::assertInstanceOf(PaymentSessionsResource::class, $flute->paymentSessions);
        self::assertInstanceOf(SettingsResource::class, $flute->settings);
        self::assertInstanceOf(MerchantsResource::class, $flute->merchants);
        self::assertCount(0, $factory->history);
    }

    public function testGetVersionMatchesConstantAndMakesNoNetworkCall(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([]);

        self::assertSame(Version::VERSION, $flute->getVersion());
        self::assertSame(Flute::VERSION, $flute->getVersion());
        self::assertCount(0, $factory->history);
    }

    public function testInvalidConfigThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Flute(['clientId' => 'only-this']);
    }

    public function testTwoInstancesHaveIndependentTokenState(): void
    {
        $a = new MockFluteFactory();
        $b = new MockFluteFactory();
        $fluteA = $a->flute([MockFluteFactory::tokenResponse('tok-a')]);
        $fluteB = $b->flute([MockFluteFactory::tokenResponse('tok-b')]);

        self::assertSame('tok-a', $fluteA->sessions->getAccessToken());
        self::assertSame('tok-b', $fluteB->sessions->getAccessToken());
        self::assertSame('tok-a', $fluteA->sessions->getAccessToken());
    }

    public function testSandboxEnvironmentWiresOauthAndApiHostsOnTheWire(): void
    {
        // Resource tests assert path suffixes only; this pins the FULL URIs so a
        // constructor bug swapping apiBaseUrl/oauthTokenUrl cannot stay green.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['transactionId' => 'tx-1', 'status' => 'Settled']),
        ]);

        $flute->transactions->getTransaction('tx-1');

        self::assertSame(
            'https://sandbox.oauth.api.flute.com/oauth2/token',
            (string) $factory->history[0]['request']->getUri(),
        );
        self::assertSame(
            'https://sandbox.api.flute.com/pay-api/v1/transactions/tx-1',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testWebhooksResourceIsWired(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([]);

        self::assertInstanceOf(\Flute\Sdk\Resources\WebhooksResource::class, $flute->webhooks);
    }
}

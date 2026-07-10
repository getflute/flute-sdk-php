<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Resources;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Models\Responses\PaymentSettingsResponse;
use Flute\Sdk\Tests\Support\MockFluteFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SettingsResourceTest extends TestCase
{
    public function testGetPaymentSettingsHydratesConfiguration(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'zeroCostProcessingOptionId' => 3,
                'zeroCostProcessingOption' => 'DualPricing',
                'isTipsEnabled' => true,
                'defaultDualPricingRate' => 3.5,
                'companyName' => 'Test Merchant',
                'currencyIsoCode' => 'USD',
                'maxTransactionAmount' => 5000.0,
            ]),
        ]);

        $settings = $flute->settings->getPaymentSettings();

        self::assertSame(3, $settings->zeroCostProcessingOptionId);
        self::assertSame('DualPricing', $settings->zeroCostProcessingOption);
        self::assertTrue($settings->isTipsEnabled);
        self::assertSame(3.5, $settings->defaultDualPricingRate);
        self::assertSame('Test Merchant', $settings->companyName);
        self::assertSame('USD', $settings->currencyIsoCode);
        self::assertSame(5000.0, $settings->maxTransactionAmount);
        self::assertStringEndsWith(
            '/pay-api/v1/configurations/payments',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testGetPaymentSettingsEmptyBodyFailsClosed(): void
    {
        // A truncated 200 must not hydrate an all-null settings object.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new Response(200, [], ''),
        ]);

        $this->expectException(FluteApiException::class);
        $flute->settings->getPaymentSettings();
    }

    public function testPaymentSettingsTolerateEmptyPayload(): void
    {
        $settings = PaymentSettingsResponse::fromArray([]);

        self::assertNull($settings->zeroCostProcessingOptionId);
        self::assertNull($settings->isTipsEnabled);
        self::assertNull($settings->maxTransactionAmount);
        self::assertSame([], $settings->toArray());
    }
}

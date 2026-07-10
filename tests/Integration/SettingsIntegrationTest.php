<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Integration;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Tests\Support\LiveTestCase;

final class SettingsIntegrationTest extends LiveTestCase
{
    public function testGetPaymentSettings(): void
    {
        try {
            $settings = $this->flute()->settings->getPaymentSettings();
        } catch (FluteApiException $e) {
            // ISV tokens (no merchant scope) 500 here; merchant tokens succeed.
            if ($e->getStatusCode() === 500 && $e->getErrorCode() === 'I0000') {
                self::markTestSkipped(sprintf(
                    'Known sandbox provisioning issue: configurations/payments returns 500 '
                    . '(correlation %s). Raise with Flute support.',
                    $e->getCorrelationId() ?? 'n/a',
                ));
            }

            throw $e;
        }

        // Test account: zeroCostProcessingOptionId 1 ("None"); see live-sandbox-findings.
        self::assertNotNull($settings->zeroCostProcessingOptionId);
        self::assertNotNull($settings->zeroCostProcessingOption);
        self::assertSame('USD', $settings->currencyIsoCode);

        // Provisioned sandbox processors are stable account fixtures.
        $raw = $settings->toArray();
        self::assertIsArray($raw['availablePaymentProcessors'] ?? null);
        $types = array_column($raw['availablePaymentProcessors'], 'type');
        self::assertContains('SandboxCard', $types);
        self::assertContains('SandboxAch', $types);
    }
}

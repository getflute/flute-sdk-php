<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

use Flute\Sdk\Exceptions\FluteApiException;

/**
 * Merchant configuration scenario (H-14).
 */
final class SettingsRegressionTest extends RegressionTestCase
{
    /** @testdox H-14: getPaymentSettings returns the merchant configuration */
    public function testPaymentSettingsAreReadable(): void
    {
        try {
            $settings = $this->flute()->settings->getPaymentSettings();
        } catch (FluteApiException $e) {
            // ISV-style credentials without merchant scope get a 500 I0000
            // here — a sandbox account gap, not an SDK regression. Any other
            // error is a genuine regression.
            if ($e->getStatusCode() !== 500 || $e->getErrorCode() !== 'I0000') {
                throw $e;
            }

            self::markTestSkipped(sprintf(
                'getPaymentSettings failed: HTTP %d, errorCode %s, correlationId %s. '
                . 'Use a merchant-scoped credential (POST /pay-api/v1/merchants/tokens).',
                $e->getStatusCode(),
                $e->getErrorCode(),
                $e->getCorrelationId() ?? 'n/a',
            ));
        }

        self::assertNotEmpty($settings->toArray());

        // toArray() returns the raw payload, so a non-empty check alone cannot
        // catch typed-field schema drift. Assert the typed surface actually
        // hydrated: at least one typed property is populated, and the zero-cost
        // option id (when present) stays inside the documented 1-4 enum range.
        $typed = [
            $settings->zeroCostProcessingOptionId,
            $settings->zeroCostProcessingOption,
            $settings->currencyIsoCode,
            $settings->currencyId,
            $settings->companyName,
            $settings->maxTransactionAmount,
            $settings->isTipsEnabled,
        ];
        self::assertNotEmpty(
            array_filter($typed, static fn (mixed $v): bool => $v !== null),
            'No typed PaymentSettingsResponse field hydrated; response schema may have drifted.',
        );

        if ($settings->zeroCostProcessingOptionId !== null) {
            self::assertContains(
                $settings->zeroCostProcessingOptionId,
                [1, 2, 3, 4],
                'zeroCostProcessingOptionId outside the documented None/CashDiscount/DualPricing/Surcharge range.',
            );
        }

        if ($settings->currencyIsoCode !== null) {
            self::assertSame(3, strlen($settings->currencyIsoCode), 'currencyIsoCode should be a 3-letter ISO code.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Flute;
use Flute\Sdk\Models\Responses\AmountBreakdown;
use Flute\Sdk\Tests\Support\SandboxFixtures;

/**
 * Zero-cost processing scenarios (H-22, H-23, H-24). Each requires the
 * merchant account to be configured in the matching mode
 * (zeroCostProcessingOptionId: 2 = CashDiscount, 3 = DualPricing,
 * 4 = Surcharge); the scenario skips with the account's actual mode
 * otherwise. The engagement sandbox account is in mode "None" (id 1) —
 * switching it is an open item with Flute.
 */
final class ZeroCostRegressionTest extends RegressionTestCase
{
    /** @testdox H-22: dual-pricing sale carries base and total receipt amounts */
    public function testDualPricingSale(): void
    {
        $flute = $this->requireMode(3, 'DualPricing');

        $receipt = $this->receiptOfSaleInCurrentMode($flute);

        self::assertNotNull($receipt->baseAmount);
        self::assertNotNull($receipt->totalAmount);
        // Card price embeds the dual-pricing rate.
        self::assertGreaterThanOrEqual($receipt->baseAmount, $receipt->totalAmount);
    }

    /** @testdox H-23: cash-discount sale carries a cash discount receipt amount */
    public function testCashDiscountSale(): void
    {
        $flute = $this->requireMode(2, 'CashDiscount');

        $receipt = $this->receiptOfSaleInCurrentMode($flute);

        self::assertNotNull($receipt->cashDiscountAmount);
        self::assertNotNull($receipt->totalAmount);
    }

    /** @testdox H-24: surcharge sale carries a surcharge receipt amount */
    public function testSurchargeSale(): void
    {
        $flute = $this->requireMode(4, 'Surcharge');

        $receipt = $this->receiptOfSaleInCurrentMode($flute);

        self::assertNotNull($receipt->surchargeAmount);
        self::assertNotNull($receipt->totalAmount);
    }

    /** Skips unless the account's zero-cost mode matches; explains how to proceed. */
    private function requireMode(int $modeId, string $modeName): Flute
    {
        $flute = $this->flute();

        try {
            $settings = $flute->settings->getPaymentSettings();
        } catch (FluteApiException $e) {
            self::markTestSkipped(sprintf(
                'getPaymentSettings failed (HTTP %d, errorCode %s) — the zero-cost mode '
                . 'cannot be determined. Use a merchant-scoped credential.',
                $e->getStatusCode(),
                $e->getErrorCode() ?? 'n/a',
            ));
        }

        if ($settings->zeroCostProcessingOptionId !== $modeId) {
            self::markTestSkipped(sprintf(
                'account mode is %s (id %s); %s (id %d) required — switch the merchant '
                . 'configuration and re-run.',
                $settings->zeroCostProcessingOption ?? 'unknown',
                $settings->zeroCostProcessingOptionId !== null
                    ? (string) $settings->zeroCostProcessingOptionId
                    : 'null',
                $modeName,
                $modeId,
            ));
        }

        return $flute;
    }

    /** Approved card sale at the card price; returns its receipt amount breakdown. */
    private function receiptOfSaleInCurrentMode(Flute $flute): AmountBreakdown
    {
        $sale = $flute->transactions->saleTransaction(
            SandboxFixtures::approvedCardSale(10.00, self::REFERENCE_PREFIX, ['useCardPrice' => true]),
        );

        self::assertTransactionStatus('Captured', $sale);
        self::assertNotNull($sale->receiptAmount);

        return $sale->receiptAmount;
    }
}

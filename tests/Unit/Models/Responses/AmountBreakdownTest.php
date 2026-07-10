<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Responses;

use Flute\Sdk\Models\Responses\AmountBreakdown;
use PHPUnit\Framework\TestCase;

final class AmountBreakdownTest extends TestCase
{
    public function testHydratesTaxAndCashDiscountPercentageFields(): void
    {
        $breakdown = AmountBreakdown::fromArray([
            'baseAmount' => 100.0,
            'cashDiscountAmount' => 1.5,
            'cashDiscountPercentage' => 1.5,
            'taxAmount' => 8.25,
            'taxRate' => 8.25,
            'totalAmount' => 109.75,
        ]);

        self::assertSame(1.5, $breakdown->cashDiscountAmount);
        self::assertSame(1.5, $breakdown->cashDiscountPercentage);
        self::assertSame(8.25, $breakdown->taxAmount);
        self::assertSame(8.25, $breakdown->taxRate);
        self::assertSame(109.75, $breakdown->totalAmount);
    }

    public function testCashDiscountRateFallsBackToPercentageAlias(): void
    {
        // Payload populates only `cashDiscountPercentage`; the `cashDiscountRate`
        // accessor mirrors it so callers can read either alias.
        $breakdown = AmountBreakdown::fromArray(['cashDiscountPercentage' => 2.0]);

        self::assertSame(2.0, $breakdown->cashDiscountPercentage);
        self::assertSame(2.0, $breakdown->cashDiscountRate);
    }

    public function testCashDiscountPercentageFallsBackToRateAlias(): void
    {
        // The reverse: payload populates only `cashDiscountRate`.
        $breakdown = AmountBreakdown::fromArray(['cashDiscountRate' => 3.0]);

        self::assertSame(3.0, $breakdown->cashDiscountRate);
        self::assertSame(3.0, $breakdown->cashDiscountPercentage);
    }

    public function testMissingFieldsHydrateNullAndRawIsPreserved(): void
    {
        $raw = ['baseAmount' => 10.0];
        $breakdown = AmountBreakdown::fromArray($raw);

        self::assertNull($breakdown->taxAmount);
        self::assertNull($breakdown->taxRate);
        self::assertNull($breakdown->cashDiscountRate);
        self::assertNull($breakdown->cashDiscountPercentage);
        self::assertSame($raw, $breakdown->toArray());
    }
}

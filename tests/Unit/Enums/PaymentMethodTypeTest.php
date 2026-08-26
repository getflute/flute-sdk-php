<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Enums;

use Flute\Sdk\Enums\PaymentMethodType;
use PHPUnit\Framework\TestCase;

final class PaymentMethodTypeTest extends TestCase
{
    public function testCardBackedValue(): void
    {
        self::assertSame('card', PaymentMethodType::Card->value);
    }

    public function testAchBackedValue(): void
    {
        self::assertSame('ach', PaymentMethodType::Ach->value);
    }

    public function testFromRoundTripsWireValues(): void
    {
        self::assertSame(PaymentMethodType::Card, PaymentMethodType::from('card'));
        self::assertSame(PaymentMethodType::Ach, PaymentMethodType::from('ach'));
    }

    public function testWireValuesAreExactlyCardAndAch(): void
    {
        $values = array_map(
            static fn (PaymentMethodType $type): string => $type->value,
            PaymentMethodType::cases(),
        );

        self::assertSame(['card', 'ach'], $values);
    }
}

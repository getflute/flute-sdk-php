<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Requests;

use Flute\Sdk\Models\Requests\CreatePaymentSessionRequest;
use PHPUnit\Framework\TestCase;

final class PaymentSessionRequestsTest extends TestCase
{
    public function testSerializesEveryField(): void
    {
        $request = new CreatePaymentSessionRequest(
            amount: 25.5,
            customerId: 'cust-1',
            mode: 3,
            skipAddressVerification: true,
            referenceId: 'ref-9',
            tipAmount: 1.25,
        );

        self::assertSame([
            'amount' => 25.5,
            'customerId' => 'cust-1',
            'mode' => 3,
            'skipAddressVerification' => true,
            'referenceId' => 'ref-9',
            'tipAmount' => 1.25,
        ], $request->toArray());
    }

    public function testOmitsNullFields(): void
    {
        // API owns validation: an empty request must serialize without error.
        self::assertSame([], (new CreatePaymentSessionRequest())->toArray());

        self::assertSame(
            ['amount' => 10.0, 'referenceId' => 'ref-1'],
            (new CreatePaymentSessionRequest(amount: 10.0, referenceId: 'ref-1'))->toArray(),
        );
    }

    public function testFalsyValuesSurviveNullFiltering(): void
    {
        // Data::filterNull drops only null: false and 0.0 are real wire values.
        $payload = (new CreatePaymentSessionRequest(
            amount: 0.0,
            skipAddressVerification: false,
            tipAmount: 0.0,
        ))->toArray();

        self::assertSame(0.0, $payload['amount']);
        self::assertFalse($payload['skipAddressVerification']);
        self::assertSame(0.0, $payload['tipAmount']);
        self::assertArrayNotHasKey('customerId', $payload);
        self::assertArrayNotHasKey('mode', $payload);
        self::assertArrayNotHasKey('referenceId', $payload);
    }
}

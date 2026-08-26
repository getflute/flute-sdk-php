<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Requests;

use Flute\Sdk\Enums\PaymentMethodType;
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
            returnUrl: 'https://merchant.example/checkout/return?order=ref-9',
            paymentMethodTypes: [PaymentMethodType::Card, PaymentMethodType::Ach],
            metadata: ['orderId' => 'wc-1042', 'attempt' => '2'],
            expiresAt: '2026-08-24T00:00:00Z',
            pageName: 'Merchant Checkout',
            paymentNotes: 'Order wc-1042',
            afterCompletionMessage: 'Thanks — your order is confirmed.',
            isMultiUse: false,
        );

        self::assertSame([
            'amount' => 25.5,
            'customerId' => 'cust-1',
            'mode' => 3,
            'skipAddressVerification' => true,
            'referenceId' => 'ref-9',
            'tipAmount' => 1.25,
            'returnUrl' => 'https://merchant.example/checkout/return?order=ref-9',
            'paymentMethodTypes' => ['card', 'ach'],
            'metadata' => ['orderId' => 'wc-1042', 'attempt' => '2'],
            'expiresAt' => '2026-08-24T00:00:00Z',
            'pageName' => 'Merchant Checkout',
            'paymentNotes' => 'Order wc-1042',
            'afterCompletionMessage' => 'Thanks — your order is confirmed.',
            'isMultiUse' => false,
        ], $request->toArray());
    }

    public function testPaymentMethodTypesAcceptEnumsAndStrings(): void
    {
        // Strings pass through untouched: a method type Flute introduces later
        // must not require an SDK release to send.
        $payload = (new CreatePaymentSessionRequest(
            paymentMethodTypes: [PaymentMethodType::Card, 'ach', 'flutepay'],
        ))->toArray();

        self::assertSame(['card', 'ach', 'flutepay'], $payload['paymentMethodTypes']);
    }

    public function testEmptyListsAndFalseSurviveNullFiltering(): void
    {
        // filterNull drops only null; it doesn't validate what's left, so an
        // empty metadata array serializes here even though the API rejects it.
        $payload = (new CreatePaymentSessionRequest(
            paymentMethodTypes: [],
            metadata: [],
            isMultiUse: false,
        ))->toArray();

        self::assertSame([], $payload['paymentMethodTypes']);
        self::assertSame([], $payload['metadata']);
        self::assertFalse($payload['isMultiUse']);
        self::assertArrayNotHasKey('returnUrl', $payload);
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

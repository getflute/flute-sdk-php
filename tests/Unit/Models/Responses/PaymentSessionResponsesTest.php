<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Responses;

use Flute\Sdk\Models\Responses\CreatePaymentSessionResponse;
use Flute\Sdk\Models\Responses\PaymentSessionResponse;
use PHPUnit\Framework\TestCase;

final class PaymentSessionResponsesTest extends TestCase
{
    public function testCreateResponseExposesCheckoutUrls(): void
    {
        // Create returns {id, checkoutUrl, checkoutUrlShort, ...} (verified against the live sandbox).
        $raw = [
            'id' => 'ps-1',
            'checkoutUrl' => 'https://checkout.flute.com/s/ps-1',
            'checkoutUrlShort' => 'https://flute.to/abc123',
            'expiresAt' => '2026-06-15T12:00:00Z',
        ];

        $response = CreatePaymentSessionResponse::fromArray($raw);

        self::assertSame('ps-1', $response->id);
        self::assertSame('https://checkout.flute.com/s/ps-1', $response->checkoutUrl);
        self::assertSame('https://flute.to/abc123', $response->checkoutUrlShort);
        // Raw escape hatch still carries everything, including untyped extras.
        self::assertSame($raw, $response->toArray());
    }

    public function testCreateResponseToleratesMissingFields(): void
    {
        $response = CreatePaymentSessionResponse::fromArray(['id' => 'ps-2']);

        self::assertSame('ps-2', $response->id);
        self::assertNull($response->checkoutUrl);
        self::assertNull($response->checkoutUrlShort);
    }

    public function testCreateResponseIgnoresNonStringUrls(): void
    {
        $response = CreatePaymentSessionResponse::fromArray([
            'id' => 'ps-3',
            'checkoutUrl' => 123,
            'checkoutUrlShort' => ['nope'],
        ]);

        self::assertNull($response->checkoutUrl);
        self::assertNull($response->checkoutUrlShort);
    }

    public function testGetSessionResponseHydratesTypedFields(): void
    {
        $raw = [
            'statusId' => 1,
            'status' => 'Created',
            'customerId' => 'cust-1',
            'mode' => 1,
            'skipAddressVerification' => false,
            'referenceId' => 'ref-1',
            'vaultedPaymentMethodId' => null,
            'transactionDetails' => null,
        ];

        $response = PaymentSessionResponse::fromArray($raw);

        self::assertSame(1, $response->statusId);
        self::assertSame('Created', $response->status);
        self::assertSame('cust-1', $response->customerId);
        self::assertFalse($response->skipAddressVerification);
        self::assertSame('ref-1', $response->referenceId);
        self::assertNull($response->transactionDetails);
        self::assertSame($raw, $response->toArray());
    }
}

<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Integration;

use Flute\Sdk\Models\Requests\CreatePaymentSessionRequest;
use Flute\Sdk\Tests\Support\LiveTestCase;

final class PaymentSessionsIntegrationTest extends LiveTestCase
{
    public function testCreateGetCancelLifecycle(): void
    {
        $flute = $this->flute();
        $referenceId = 'it-ps-' . uniqid('', true);

        $created = $flute->paymentSessions->createPaymentSession(
            new CreatePaymentSessionRequest(amount: 5.00, referenceId: $referenceId),
        );
        self::assertNotNull($created->id);
        // Create response carries the hosted checkout URLs.
        self::assertNotEmpty($created->toArray()['checkoutUrl'] ?? null);

        $fetched = $flute->paymentSessions->getPaymentSession($created->id);
        self::assertSame(1, $fetched->statusId);
        self::assertSame('Created', $fetched->status);
        self::assertSame($referenceId, $fetched->referenceId);

        $flute->paymentSessions->cancelPaymentSession($created->id);

        $afterCancel = $flute->paymentSessions->getPaymentSession($created->id);
        self::assertSame(2, $afterCancel->statusId);
        self::assertSame('Cancelled', $afterCancel->status);
    }
}

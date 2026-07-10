<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\CreatePaymentSessionRequest;
use Flute\Sdk\Models\Responses\CreatePaymentSessionResponse;
use Flute\Sdk\Tests\Support\SandboxFixtures;

/**
 * Payment session lifecycle scenarios (H-11, H-12, H-13).
 * statusId map: 1 = Created, 2 = Cancelled, 3 = Completed.
 */
final class PaymentSessionsRegressionTest extends RegressionTestCase
{
    /** @testdox H-11: creating a payment session returns a non-empty id */
    public function testCreateReturnsId(): void
    {
        $created = $this->createSession($this->flute(), 9.00);

        self::assertNotNull($created->id);
        self::assertNotSame('', $created->id);
    }

    /** @testdox H-12: a freshly created payment session fetches as Created (statusId 1) */
    public function testFreshSessionIsCreated(): void
    {
        $flute = $this->flute();
        $created = $this->createSession($flute, 9.10);
        self::assertNotNull($created->id);

        $fetched = $flute->paymentSessions->getPaymentSession($created->id);

        self::assertSame(1, $fetched->statusId);
        self::assertSame('Created', $fetched->status);
    }

    /** @testdox H-13: a cancelled payment session re-fetches as Cancelled (statusId 2) */
    public function testCancelledSessionIsCancelled(): void
    {
        $flute = $this->flute();
        $created = $this->createSession($flute, 9.20);
        self::assertNotNull($created->id);

        $flute->paymentSessions->cancelPaymentSession($created->id);
        $fetched = $flute->paymentSessions->getPaymentSession($created->id);

        self::assertSame(2, $fetched->statusId);
        self::assertSame('Cancelled', $fetched->status);
    }

    private function createSession(Flute $flute, float $amount): CreatePaymentSessionResponse
    {
        return $flute->paymentSessions->createPaymentSession(new CreatePaymentSessionRequest(
            amount: $amount,
            referenceId: SandboxFixtures::uniqueReferenceId(self::REFERENCE_PREFIX . 'ps-'),
        ));
    }
}

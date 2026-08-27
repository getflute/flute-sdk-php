<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

use Flute\Sdk\Enums\PaymentMethodType;
use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\CreatePaymentSessionRequest;
use Flute\Sdk\Models\Responses\CreatePaymentSessionResponse;
use Flute\Sdk\Tests\Support\SandboxFixtures;

/**
 * Payment session lifecycle scenarios (H-11, H-12, H-13, H-26, H-27).
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

    /** @testdox H-26: a session created with the full Checkout field set echoes it back on get */
    public function testFullCheckoutFieldSetEchoesOnGet(): void
    {
        $flute = $this->flute();
        $referenceId = SandboxFixtures::uniqueReferenceId(self::REFERENCE_PREFIX . 'ps-');
        $returnUrl = 'https://example.test/flute/return?order=' . $referenceId;

        // expiresAt is omitted: time-sensitive; covered by unit serialization tests.
        // isMultiUse is sent but not echoed in the 2026-08-23 verification run — no assertion.
        $created = $flute->paymentSessions->createPaymentSession(new CreatePaymentSessionRequest(
            amount: 9.30,
            referenceId: $referenceId,
            returnUrl: $returnUrl,
            paymentMethodTypes: [PaymentMethodType::Card, PaymentMethodType::Ach],
            metadata: ['orderId' => $referenceId],
            pageName: 'Harness Checkout',
            paymentNotes: 'Harness H-26',
            afterCompletionMessage: 'Thank you.',
            isMultiUse: false,
        ));
        self::assertNotNull($created->id);

        $raw = $flute->paymentSessions->getPaymentSession($created->id)->toArray();
        $rawDump = var_export($raw, true);

        self::assertSame($returnUrl, $raw['returnUrl'] ?? null, "returnUrl mismatch.\n$rawDump");
        self::assertSame(
            ['card', 'ach'],
            $raw['paymentMethodTypes'] ?? null,
            "paymentMethodTypes mismatch.\n$rawDump",
        );
        self::assertSame(['orderId' => $referenceId], $raw['metadata'] ?? null, "metadata mismatch.\n$rawDump");
        self::assertSame('Harness Checkout', $raw['pageName'] ?? null, "pageName mismatch.\n$rawDump");
        self::assertSame('Harness H-26', $raw['paymentNotes'] ?? null, "paymentNotes mismatch.\n$rawDump");
        self::assertSame(
            'Thank you.',
            $raw['afterCompletionMessage'] ?? null,
            "afterCompletionMessage mismatch.\n$rawDump",
        );

        $flute->paymentSessions->cancelPaymentSession($created->id);
    }

    /** @testdox H-27: typed read-back matches the sent values and the raw payload for every Checkout field */
    public function testTypedReadBackMatchesRawPayload(): void
    {
        $flute = $this->flute();
        $referenceId = SandboxFixtures::uniqueReferenceId(self::REFERENCE_PREFIX . 'ps-');
        $returnUrl = 'https://example.test/flute/return?order=' . $referenceId;
        // One hour ahead, computed at run time, so the scenario is never stale.
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);

        $created = $flute->paymentSessions->createPaymentSession(new CreatePaymentSessionRequest(
            amount: 9.40,
            referenceId: $referenceId,
            returnUrl: $returnUrl,
            paymentMethodTypes: [PaymentMethodType::Card, PaymentMethodType::Ach],
            // Single key on purpose: the server does not preserve map key order.
            metadata: ['orderId' => $referenceId],
            expiresAt: $expiresAt,
            pageName: 'Harness Checkout',
        ));
        self::assertNotNull($created->id);

        $fetched = $flute->paymentSessions->getPaymentSession($created->id);
        $raw = $fetched->toArray();
        $rawDump = var_export($raw, true);

        // Settable fields: the typed property reads back what was sent.
        self::assertSame($returnUrl, $fetched->returnUrl, "returnUrl mismatch.\n$rawDump");
        self::assertSame(['orderId' => $referenceId], $fetched->metadata, "metadata mismatch.\n$rawDump");
        self::assertSame($expiresAt, $fetched->expiresAt, "expiresAt mismatch.\n$rawDump");
        self::assertSame('Harness Checkout', $fetched->pageName, "pageName mismatch.\n$rawDump");
        self::assertSame(['card', 'ach'], $fetched->paymentMethodTypes, "paymentMethodTypes mismatch.\n$rawDump");

        // Fields the request cannot set: a value on the wire must hydrate, and an
        // absent or null key must read null, so an echo change fails here instead
        // of reading back as a silent null (an int ACH fragment reads null: fails too).
        foreach (['checkoutUrl', 'surchargeAmount', 'achAccountLast2', 'achRoutingLast2'] as $key) {
            if (($raw[$key] ?? null) === null) {
                self::assertNull($fetched->{$key}, "$key absent or null on the wire but hydrated.\n$rawDump");
            } else {
                self::assertNotNull($fetched->{$key}, "$key present on the wire but not hydrated.\n$rawDump");
            }
        }

        $flute->paymentSessions->cancelPaymentSession($created->id);
    }

    private function createSession(Flute $flute, float $amount): CreatePaymentSessionResponse
    {
        return $flute->paymentSessions->createPaymentSession(new CreatePaymentSessionRequest(
            amount: $amount,
            referenceId: SandboxFixtures::uniqueReferenceId(self::REFERENCE_PREFIX . 'ps-'),
        ));
    }
}

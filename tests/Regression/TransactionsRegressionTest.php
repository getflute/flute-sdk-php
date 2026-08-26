<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Models\Requests\CalculateAmountRequest;
use Flute\Sdk\Models\Requests\CaptureTransactionRequest;
use Flute\Sdk\Models\Requests\RefundTransactionRequest;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;
use Flute\Sdk\Models\Requests\VoidTransactionRequest;

/**
 * Card transaction scenarios (H-3 .. H-10, H-21). All wire literals below
 * were verified against the live sandbox.
 */
final class TransactionsRegressionTest extends RegressionTestCase
{
    /** @testdox H-3: an approved card sale reports Captured with a transaction id */
    public function testApprovedSale(): void
    {
        $sale = $this->flute()->transactions->saleTransaction(self::approvedCard(3.01));

        self::assertTransactionStatus('Captured', $sale);
        self::assertNotNull($sale->transactionId);
        self::assertNotSame('', $sale->transactionId);
    }

    /** @testdox H-4: a sale missing required fields raises FluteApiException with a 4xx status */
    public function testMissingRequiredFieldIs4xx(): void
    {
        try {
            $this->flute()->transactions->saleTransaction(new SaleTransactionRequest(amount: 1.00));
            self::fail('Expected FluteApiException for a sale carrying only an amount');
        } catch (FluteApiException $e) {
            self::assertGreaterThanOrEqual(400, $e->getStatusCode());
            self::assertLessThan(500, $e->getStatusCode());
        }
    }

    /** @testdox H-5: authorize reports Authorized; capture by transaction id reports Captured */
    public function testAuthorizeThenCapture(): void
    {
        $flute = $this->flute();

        $auth = $flute->transactions->authorizeTransaction(self::approvedAuthorize(5.01));
        self::assertTransactionStatus('Authorized', $auth);
        self::assertNotNull($auth->transactionId);

        $captured = $flute->transactions->captureTransaction(new CaptureTransactionRequest(
            transactionId: $auth->transactionId,
            amount: 5.01,
        ));
        self::assertTransactionStatus('Captured', $captured);
        self::assertSame(2, $captured->statusId);
        self::assertSame('Capture', $captured->type);
    }

    /** @testdox H-6: authorize then void reports Voided */
    public function testAuthorizeThenVoid(): void
    {
        $flute = $this->flute();

        $auth = $flute->transactions->authorizeTransaction(self::approvedAuthorize(6.01));
        self::assertTransactionStatus('Authorized', $auth);
        self::assertNotNull($auth->transactionId);

        $voided = $flute->transactions->voidTransaction(
            new VoidTransactionRequest(transactionId: $auth->transactionId),
        );
        self::assertTransactionStatus('Voided', $voided);
        self::assertSame(3, $voided->statusId);
    }

    /** @testdox H-7: a settled sale refunds as Refunded */
    public function testRefundAfterSettlement(): void
    {
        $flute = $this->flute();

        $sale = $flute->transactions->saleTransaction(self::approvedCard(7.01));
        self::assertTransactionStatus('Captured', $sale);
        self::assertNotNull($sale->transactionId);

        // Refunds need a settled parent; settle the open card batch directly.
        $this->settleCardBatch($flute);

        $refund = $flute->transactions->refundTransaction(new RefundTransactionRequest(
            transactionId: $sale->transactionId,
            amount: 7.01,
        ));
        self::assertTransactionStatus('Refunded', $refund);
        self::assertSame(4, $refund->statusId);
        self::assertSame('Refund', $refund->type);
    }

    /** @testdox H-8: listing transactions without filters reports a total */
    public function testListWithoutFilters(): void
    {
        $list = $this->flute()->transactions->listTransactions();

        self::assertNotNull($list->total);
    }

    /** @testdox H-9: a sale fetched by id matches the created transaction */
    public function testGetMatchesSale(): void
    {
        $flute = $this->flute();

        $sale = $flute->transactions->saleTransaction(self::approvedCard(9.01));
        self::assertTransactionStatus('Captured', $sale);
        self::assertNotNull($sale->transactionId);

        $fetched = $flute->transactions->getTransaction($sale->transactionId);
        self::assertSame($sale->transactionId, $fetched->transactionId);
        self::assertSame('Captured', $fetched->status);
    }

    /** @testdox H-10: calculateAmount hydrates a typed breakdown, not just a raw payload */
    public function testCalculateAmount(): void
    {
        $calculated = $this->flute()->transactions->calculateAmount(
            // useCardPrice: required since the account flipped to DualPricing.
            new CalculateAmountRequest(amount: 100.00, currencyId: 1, useCardPrice: true),
        );

        // Prove live data reached the typed SDK surface, not merely that the raw
        // payload is non-empty (toArray() returns the raw wire body). In mode
        // "None" all four method breakdowns return with zeroed rates, so at least
        // one must hydrate to a positive typed total/base amount.
        $raw = var_export($calculated->toArray(), true);
        $breakdowns = array_filter([
            'cash' => $calculated->cash,
            'creditCard' => $calculated->creditCard,
            'debitCard' => $calculated->debitCard,
            'ach' => $calculated->ach,
        ]);
        self::assertNotEmpty($breakdowns, "No typed payment-method breakdown hydrated.\n$raw");

        $positive = false;
        foreach ($breakdowns as $breakdown) {
            $total = $breakdown->totalAmount ?? $breakdown->baseAmount;
            if ($total !== null && $total > 0.0) {
                $positive = true;
            }
        }
        self::assertTrue($positive, "No breakdown hydrated a positive typed total/base amount.\n$raw");

        // currencyId echoes the request when the payload carries it (stable typed field).
        if ($calculated->currencyId !== null) {
            self::assertSame(1, $calculated->currencyId, "Unexpected currencyId.\n$raw");
        }
    }

    /** @testdox H-21: the decline card surfaces a Declined response, not an exception */
    public function testDeclineCardSurfacesDeclinedStatus(): void
    {
        // Declines are HTTP 200 envelopes, not FluteApiException (that stays
        // the contract for transport-level 4xx/5xx). The AVS-passing address
        // isolates the card decline.
        $declined = $this->flute()->transactions->saleTransaction(self::declineCard(1.21));

        self::assertTransactionStatus('Declined', $declined);
        self::assertSame(91, $declined->statusId);
        self::assertNotNull($declined->details);
        self::assertSame('Decline', $declined->details->code);
        self::assertSame('Do not honor', $declined->details->message);
    }
}

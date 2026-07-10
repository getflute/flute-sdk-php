<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Integration;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;
use Flute\Sdk\Models\Requests\VoidTransactionRequest;
use Flute\Sdk\Tests\Support\LiveTestCase;
use Flute\Sdk\Tests\Support\SandboxFixtures;

/**
 * Live card-transaction coverage. Sales route to the merchant's default
 * processor (no paymentProcessorId needed with a merchant-scoped token).
 * Request construction (AVS full-match address, unique referenceId per money
 * request) lives in SandboxFixtures.
 */
final class TransactionsIntegrationTest extends LiveTestCase
{
    private const REFERENCE_PREFIX = 'it2-';

    private static function approvedCardSale(float $amount): SaleTransactionRequest
    {
        return SandboxFixtures::approvedCardSale($amount, self::REFERENCE_PREFIX);
    }

    public function testSaleApprovesAndHydrates(): void
    {
        $response = $this->flute()->transactions->saleTransaction(self::approvedCardSale(1.00));

        // Approved sales report status Captured (statusId 2) on the wire.
        self::assertNotNull($response->transactionId);
        self::assertTransactionStatus('Captured', $response);
        self::assertSame(2, $response->statusId);
        self::assertSame(1.0, $response->processedAmount);
        $details = $response->details;
        self::assertNotNull($details);
        self::assertSame('Approve', $details->code);
        self::assertNotNull($details->authCode);
    }

    public function testDeclineCardSurfacesDeclinedStatus(): void
    {
        // Card declines are HTTP 200 + Declined status, not FluteApiException.
        // AVS-passing address ensures the card decline fires, not an AVS deny.
        $response = $this->flute()->transactions->saleTransaction(
            SandboxFixtures::declineCardSale(1.00, self::REFERENCE_PREFIX),
        );

        self::assertTransactionStatus('Declined', $response);
        self::assertSame(91, $response->statusId);
        $details = $response->details;
        self::assertNotNull($details);
        self::assertSame('Decline', $details->code);
        self::assertSame('Do not honor', $details->message);
        self::assertNull($details->authCode);
    }

    public function testMissingRequiredFieldIs4xx(): void
    {
        try {
            $this->flute()->transactions->saleTransaction(new SaleTransactionRequest(amount: 1.00));
            self::fail('Expected FluteApiException for incomplete sale');
        } catch (FluteApiException $e) {
            self::assertGreaterThanOrEqual(400, $e->getStatusCode());
            self::assertLessThan(500, $e->getStatusCode());
        }
    }

    public function testListAndGetRoundTrip(): void
    {
        $flute = $this->flute();
        $first = $flute->transactions->saleTransaction(self::approvedCardSale(1.50));
        $second = $flute->transactions->saleTransaction(self::approvedCardSale(1.75));
        self::assertNotNull($first->transactionId);
        self::assertNotNull($second->transactionId);

        $fetched = $flute->transactions->getTransaction($first->transactionId);
        self::assertSame($first->transactionId, $fetched->transactionId);
        self::assertSame('Captured', $fetched->status);

        // page is ZERO-based on the wire (skip = page * pageSize). List rows key
        // their identifier as "id"; the DTO fallback-maps it into transactionId,
        // so transactionId is populated for list items against live data too.
        $page0 = $flute->transactions->listTransactions(new ListTransactionsRequest(page: 0, pageSize: 1));
        $page1 = $flute->transactions->listTransactions(new ListTransactionsRequest(page: 1, pageSize: 1));

        self::assertCount(1, $page0->items);
        self::assertCount(1, $page1->items);
        self::assertNotNull($page0->total);
        self::assertGreaterThanOrEqual(2, $page0->total);
        // Concurrent sandbox activity may add records between the two calls.
        self::assertGreaterThanOrEqual($page0->total, $page1->total);

        $idOnPage0 = $page0->items[0]->transactionId;
        $idOnPage1 = $page1->items[0]->transactionId;
        self::assertIsString($idOnPage0);
        self::assertIsString($idOnPage1);
        self::assertNotSame($idOnPage0, $idOnPage1);
        // The fallback also keeps the raw payload reachable.
        self::assertSame($idOnPage0, $page0->items[0]->toArray()['id'] ?? null);
    }

    public function testVoidAfterSale(): void
    {
        $flute = $this->flute();
        $sale = $flute->transactions->saleTransaction(self::approvedCardSale(2.00));
        self::assertNotNull($sale->transactionId);
        self::assertTransactionStatus('Captured', $sale);

        $voided = $flute->transactions->voidTransaction(
            new VoidTransactionRequest(transactionId: $sale->transactionId),
        );

        self::assertTransactionStatus('Voided', $voided);
        self::assertSame(3, $voided->statusId);
        self::assertSame('Void', $voided->type);
    }
}

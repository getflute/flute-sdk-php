<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Resources;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Models\Requests\CalculateAmountRequest;
use Flute\Sdk\Models\Requests\CaptureTransactionRequest;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;
use Flute\Sdk\Models\Requests\RefundTransactionRequest;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;
use Flute\Sdk\Models\Requests\VoidTransactionRequest;
use Flute\Sdk\Tests\Support\MockFluteFactory;
use PHPUnit\Framework\TestCase;

final class TransactionsResourceTest extends TestCase
{
    public function testSaleTransactionPostsPayloadAndHydratesResponse(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'transactionId' => 'tx-1',
                'status' => 'Approved',
                'details' => ['authCode' => 'A1'],
            ]),
        ]);

        $response = $flute->transactions->saleTransaction(new SaleTransactionRequest(
            amount: 100.0,
            accountNumber: '4111111111111111',
            currencyId: 1,
            expirationMonth: 12,
            expirationYear: 2030,
        ));

        self::assertSame('tx-1', $response->transactionId);
        self::assertSame('Approved', $response->status);

        $request = $factory->history[1]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('Bearer tok-1', $request->getHeaderLine('Authorization'));
        self::assertStringEndsWith('/pay-api/v1/transactions/sale', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(100.0, (float) $body['amount']);
        self::assertSame(1, $body['cardDataSource']);
    }

    public function testSaleTransactionEmptyBodyFailsClosed(): void
    {
        // A truncated 200 must not hydrate an all-null transaction response.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new \GuzzleHttp\Psr7\Response(200, [], ''),
        ]);

        $this->expectException(FluteApiException::class);
        $flute->transactions->saleTransaction(new SaleTransactionRequest(
            amount: 100.0,
            accountNumber: '4111111111111111',
            currencyId: 1,
            expirationMonth: 12,
            expirationYear: 2030,
        ));
    }

    public function testDeclinedSaleSurfacesAsDeclinedStatusNotException(): void
    {
        // Real card declines come back HTTP 200 with status "Declined" and
        // processor details — NOT a 4xx exception (observed live 2026-06-10;
        // FluteApiException stays the contract for transport-level 4xx/5xx only).
        // The SDK must hydrate the declined envelope so callers
        // read $response->status rather than catching an exception.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'transactionId' => 'tx-declined',
                'statusId' => 91,
                'status' => 'Declined',
                'details' => ['code' => 'Decline', 'message' => 'Do not honor'],
            ]),
        ]);

        $response = $flute->transactions->saleTransaction(new SaleTransactionRequest(amount: 1.0));

        self::assertSame('Declined', $response->status);
        self::assertSame(91, $response->statusId);
        self::assertNotNull($response->details);
        self::assertSame('Decline', $response->details->code);
        self::assertSame('Do not honor', $response->details->message);
    }

    public function testTransportErrorStillSurfacesAsApiException(): void
    {
        // Transport-level 4xx/5xx (validation, auth, duplicate controls) remain
        // the FluteApiException contract — only card declines moved to HTTP 200.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'Title' => 'Validation error',
                'Details' => 'Amount is required',
                'ErrorCode' => 'V0000',
            ], 400),
        ]);

        try {
            $flute->transactions->saleTransaction(new SaleTransactionRequest(amount: 1.0));
            self::fail('Expected FluteApiException');
        } catch (FluteApiException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('V0000', $e->getErrorCode());
        }
    }

    public function testAuthorizeCaptureFlowHitsCorrectEndpoints(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['transactionId' => 'tx-a', 'status' => 'Authorized']),
            MockFluteFactory::jsonResponse(['transactionId' => 'tx-a', 'status' => 'Captured']),
        ]);

        $auth = $flute->transactions->authorizeTransaction(
            new \Flute\Sdk\Models\Requests\AuthorizeTransactionRequest(amount: 10.0),
        );
        $capture = $flute->transactions->captureTransaction(
            new CaptureTransactionRequest(transactionId: 'tx-a', amount: 10.0),
        );

        self::assertSame('Authorized', $auth->status);
        self::assertSame('Captured', $capture->status);
        self::assertStringEndsWith('/transactions/auth', (string) $factory->history[1]['request']->getUri());
        self::assertStringEndsWith('/transactions/capture', (string) $factory->history[2]['request']->getUri());
    }

    public function testVoidOnAlreadyVoidedSurfacesApiException(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'Title' => 'Invalid operation',
                'Details' => 'Transaction is already voided',
                'ErrorCode' => 'V0102',
            ], 400),
        ]);

        $this->expectException(FluteApiException::class);
        $flute->transactions->voidTransaction(new VoidTransactionRequest(transactionId: 'tx-v'));
    }

    public function testRefundExceedingOriginalSurfacesApiException(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'Title' => 'Invalid amount',
                'Details' => 'Refund amount exceeds the original transaction amount',
                'ErrorCode' => 'V0201',
            ], 400),
        ]);

        $this->expectException(FluteApiException::class);
        $flute->transactions->refundTransaction(
            new RefundTransactionRequest(transactionId: 'tx-r', amount: 9999.0),
        );
    }

    public function testVoidTransactionPostsToVoidPathAndHydrates(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['transactionId' => 'tx-v1', 'status' => 'Voided']),
        ]);

        $response = $flute->transactions->voidTransaction(new VoidTransactionRequest(transactionId: 'tx-v1'));

        self::assertSame('tx-v1', $response->transactionId);
        self::assertSame('Voided', $response->status);
        $request = $factory->history[1]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/pay-api/v1/transactions/void', (string) $request->getUri());
    }

    public function testRefundTransactionPostsToReturnPathAndHydrates(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['transactionId' => 'tx-r1', 'status' => 'Refunded']),
        ]);

        $response = $flute->transactions->refundTransaction(
            new RefundTransactionRequest(transactionId: 'tx-r1', amount: 10.0),
        );

        self::assertSame('tx-r1', $response->transactionId);
        self::assertSame('Refunded', $response->status);
        $request = $factory->history[1]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/pay-api/v1/transactions/return', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(1, $body['cardDataSource']);
    }

    public function testGetTransactionHitsPathWithId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['transactionId' => 'tx-9', 'status' => 'Settled']),
        ]);

        $details = $flute->transactions->getTransaction('tx-9');

        self::assertSame('tx-9', $details->transactionId);
        self::assertStringEndsWith('/pay-api/v1/transactions/tx-9', (string) $factory->history[1]['request']->getUri());
    }

    public function testGetTransactionRawUrlEncodesHostileId(): void
    {
        // A path-injection-shaped ID must land fully encoded; deleting the
        // rawurlencode() call would rewrite the request path/query.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['transactionId' => 'tx-9', 'status' => 'Settled']),
        ]);

        $flute->transactions->getTransaction('tx/1?admin=1%2F');

        self::assertSame(
            'https://sandbox.api.flute.com/pay-api/v1/transactions/tx%2F1%3Fadmin%3D1%252F',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testGetTransactionRejectsEmptyId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([MockFluteFactory::tokenResponse()]);

        $this->expectException(\InvalidArgumentException::class);
        $flute->transactions->getTransaction('');
    }

    public function testListTransactionsSendsPaginationQuery(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['items' => [['transactionId' => 'tx-1']], 'total' => 1]),
        ]);

        $list = $flute->transactions->listTransactions(new ListTransactionsRequest(page: 1, pageSize: 10));

        self::assertSame(1, $list->total);
        self::assertCount(1, $list->items);
        $uri = (string) $factory->history[1]['request']->getUri();
        self::assertStringContainsString('/pay-api/v1/transactions?', $uri);
        self::assertStringContainsString('page=1', $uri);
        self::assertStringContainsString('pageSize=10', $uri);
    }

    public function testListTransactionsWithoutFilters(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['items' => [], 'total' => 0]),
        ]);

        $list = $flute->transactions->listTransactions();

        self::assertSame(0, $list->total);
        self::assertSame([], $list->items);
    }

    public function testCalculateAmountSendsQueryAndHydrates(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'currency' => 'USD',
                'cash' => ['totalAmount' => 100.0],
                'creditCard' => ['totalAmount' => 103.5],
            ]),
        ]);

        $calc = $flute->transactions->calculateAmount(new CalculateAmountRequest(amount: 100.0));

        self::assertSame(103.5, $calc->creditCard?->totalAmount);
        $uri = (string) $factory->history[1]['request']->getUri();
        self::assertStringContainsString('/pay-api/v1/transactions/calculate-amount?', $uri);
        self::assertStringContainsString('amount=100', $uri);
    }
}

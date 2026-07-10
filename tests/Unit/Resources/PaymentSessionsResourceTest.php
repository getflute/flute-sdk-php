<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Resources;

use Flute\Sdk\Models\Requests\CreatePaymentSessionRequest;
use Flute\Sdk\Models\Responses\CreatePaymentSessionResponse;
use Flute\Sdk\Models\Responses\PaymentSessionResponse;
use Flute\Sdk\Tests\Support\MockFluteFactory;
use PHPUnit\Framework\TestCase;

final class PaymentSessionsResourceTest extends TestCase
{
    public function testCreateSendsApiVersionHeaderAndReturnsId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['id' => 'ps-1']),
        ]);

        $created = $flute->paymentSessions->createPaymentSession(
            new CreatePaymentSessionRequest(amount: 25.0, referenceId: 'ref-9'),
        );

        self::assertSame('ps-1', $created->id);
        $request = $factory->history[1]['request'];
        self::assertStringEndsWith('/pay-int-api/payment-sessions', (string) $request->getUri());
        self::assertSame('1', $request->getHeaderLine('x-api-version'));
        $body = json_decode((string) $request->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(25.0, (float) $body['amount']);
        self::assertSame('ref-9', $body['referenceId']);
    }

    public function testGetHydratesSessionState(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse([
                'statusId' => 1,
                'status' => 'Created',
                'referenceId' => 'ref-9',
                'mode' => 1,
                'transactionDetails' => ['transactionId' => 'tx-77'],
            ]),
        ]);

        $session = $flute->paymentSessions->getPaymentSession('ps-1');

        self::assertSame(1, $session->statusId);
        self::assertSame('Created', $session->status);
        self::assertSame('ref-9', $session->referenceId);
        self::assertSame(['transactionId' => 'tx-77'], $session->transactionDetails);
        $request = $factory->history[1]['request'];
        self::assertStringEndsWith('/pay-int-api/payment-sessions/ps-1', (string) $request->getUri());
        self::assertSame('1', $request->getHeaderLine('x-api-version'));
    }

    public function testCancelPostsToCancelPath(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new \GuzzleHttp\Psr7\Response(200, [], ''),
        ]);

        // Fire-and-forget: cancel tolerates the empty 2xx body it gets in practice.
        $flute->paymentSessions->cancelPaymentSession('ps-1');

        $request = $factory->history[1]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/pay-int-api/payment-sessions/ps-1/cancel', (string) $request->getUri());
    }

    public function testGetPaymentSessionRawUrlEncodesHostileId(): void
    {
        // A path-injection-shaped ID must land fully encoded; deleting the
        // rawurlencode() call would rewrite the request path/query.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            MockFluteFactory::jsonResponse(['statusId' => 1, 'status' => 'Created']),
        ]);

        $flute->paymentSessions->getPaymentSession('ps/1?admin=1%2F');

        self::assertSame(
            'https://sandbox.api.flute.com/pay-int-api/payment-sessions/ps%2F1%3Fadmin%3D1%252F',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testCancelPaymentSessionRawUrlEncodesHostileId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new \GuzzleHttp\Psr7\Response(200, [], ''),
        ]);

        $flute->paymentSessions->cancelPaymentSession('ps/1?admin=1%2F');

        self::assertSame(
            'https://sandbox.api.flute.com/pay-int-api/payment-sessions/ps%2F1%3Fadmin%3D1%252F/cancel',
            (string) $factory->history[1]['request']->getUri(),
        );
    }

    public function testGetPaymentSessionEmptyBodyFailsClosed(): void
    {
        // A truncated 200 must not hydrate an all-null session.
        $factory = new MockFluteFactory();
        $flute = $factory->flute([
            MockFluteFactory::tokenResponse(),
            new \GuzzleHttp\Psr7\Response(200, [], ''),
        ]);

        $this->expectException(\Flute\Sdk\Exceptions\FluteApiException::class);
        $flute->paymentSessions->getPaymentSession('ps-1');
    }

    public function testGetPaymentSessionRejectsEmptyId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([MockFluteFactory::tokenResponse()]);

        $this->expectException(\InvalidArgumentException::class);
        $flute->paymentSessions->getPaymentSession('');
    }

    public function testCancelPaymentSessionRejectsEmptyId(): void
    {
        $factory = new MockFluteFactory();
        $flute = $factory->flute([MockFluteFactory::tokenResponse()]);

        $this->expectException(\InvalidArgumentException::class);
        $flute->paymentSessions->cancelPaymentSession('');
    }

    public function testSessionResponsesTolerateEmptyAndMistypedPayloads(): void
    {
        $empty = PaymentSessionResponse::fromArray([]);
        self::assertNull($empty->statusId);
        self::assertNull($empty->status);
        self::assertNull($empty->transactionDetails);
        self::assertSame([], $empty->toArray());

        $mistyped = PaymentSessionResponse::fromArray(['transactionDetails' => 'nope']);
        self::assertNull($mistyped->transactionDetails);

        $created = CreatePaymentSessionResponse::fromArray([]);
        self::assertNull($created->id);
        self::assertSame([], $created->toArray());
    }
}

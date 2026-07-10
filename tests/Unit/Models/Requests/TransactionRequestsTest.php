<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Requests;

use Flute\Sdk\Models\Requests\Address;
use Flute\Sdk\Models\Requests\AuthorizeTransactionRequest;
use Flute\Sdk\Models\Requests\CalculateAmountRequest;
use Flute\Sdk\Models\Requests\CaptureTransactionRequest;
use Flute\Sdk\Models\Requests\ContactInfo;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;
use Flute\Sdk\Models\Requests\RefundTransactionRequest;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;
use Flute\Sdk\Models\Requests\VoidTransactionRequest;
use PHPUnit\Framework\TestCase;

final class TransactionRequestsTest extends TestCase
{
    public function testSaleSerializesFieldsAndOmitsNulls(): void
    {
        $request = new SaleTransactionRequest(
            amount: 100.0,
            accountNumber: '4111111111111111',
            currencyId: 1,
            expirationMonth: 12,
            expirationYear: 2030,
            securityCode: '123',
            billingAddress: new Address(line1: '123 Test St', postalCode: '10001'),
            referenceId: 'ref-1',
        );

        $payload = $request->toArray();

        self::assertSame(100.0, $payload['amount']);
        self::assertSame('4111111111111111', $payload['accountNumber']);
        self::assertSame(1, $payload['currencyId']);
        self::assertSame(1, $payload['cardDataSource']); // Internet default
        self::assertSame(['line1' => '123 Test St', 'postalCode' => '10001'], $payload['billingAddress']);
        self::assertArrayNotHasKey('tipAmount', $payload);
        self::assertArrayNotHasKey('contactInfo', $payload);
    }

    public function testDebugInfoMasksPanAndCvvWithoutBreakingWirePayload(): void
    {
        $request = new SaleTransactionRequest(
            amount: 100.0,
            accountNumber: '4111111111111111',
            securityCode: '987',
        );

        // __debugInfo() (what var_dump/VarDumper/Xdebug consult) masks the SAD.
        $debug = $request->__debugInfo();
        self::assertSame('************1111', $debug['accountNumber']);
        self::assertSame('***', $debug['securityCode']);

        ob_start();
        var_dump($request);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('4111111111111111', $dump);
        self::assertStringNotContainsString('987', $dump);

        // The wire contract (toArray) is untouched and still carries real values.
        $payload = $request->toArray();
        self::assertSame('4111111111111111', $payload['accountNumber']);
        self::assertSame('987', $payload['securityCode']);
    }

    public function testJsonEncodeAndSerializeDoNotLeakCardData(): void
    {
        $request = new SaleTransactionRequest(accountNumber: '4111111111111111', securityCode: '987');

        foreach ([(string) json_encode($request), serialize($request)] as $output) {
            self::assertStringNotContainsString('4111111111111111', $output);
            self::assertStringNotContainsString('987', $output);
        }

        // serialize() round-trips to a fail-closed (masked) object without error.
        $restored = unserialize(serialize($request));
        self::assertInstanceOf(SaleTransactionRequest::class, $restored);

        // toArray() remains the explicit wire path carrying the real values.
        self::assertSame('4111111111111111', $request->toArray()['accountNumber']);
        self::assertSame('987', $request->toArray()['securityCode']);
    }

    /**
     * Build a serialize()-compatible SaleTransactionRequest payload from an
     * arbitrary key => value map, so tests can inject unknown keys and drop or
     * retype known ones (matches the wire format serialize() emits for
     * __serialize).
     *
     * @param array<string, mixed> $data
     */
    private static function serializedSale(array $data): string
    {
        $inner = serialize($data);
        $inner = substr($inner, (int) strpos($inner, '{'));

        return sprintf(
            'O:%d:"%s":%d:%s',
            strlen(SaleTransactionRequest::class),
            SaleTransactionRequest::class,
            count($data),
            $inner,
        );
    }

    public function testUnserializeIgnoresUnknownKeys(): void
    {
        // A hand-edited/legacy payload with an extra key must not create a
        // deprecated dynamic property; known keys still hydrate.
        $data = (new SaleTransactionRequest(amount: 10.0, referenceId: 'ref-9'))->__serialize();
        $data['rogue'] = 'boom';

        $restored = unserialize(self::serializedSale($data));

        self::assertInstanceOf(SaleTransactionRequest::class, $restored);
        self::assertFalse(property_exists($restored, 'rogue'));
        self::assertSame(10.0, $restored->amount);
        self::assertSame('ref-9', $restored->referenceId);
    }

    public function testUnserializeFailsClosedOnWrongTypedAndMissingValues(): void
    {
        // Wrong-typed values fall back to null/default instead of throwing an
        // uncaught TypeError, and missing keys hydrate immediately instead of
        // deferring an uninitialized-property error to first access.
        $data = (new SaleTransactionRequest(amount: 10.0, currencyId: 1))->__serialize();
        $data['amount'] = 'lots';
        $data['billingAddress'] = 'not-an-address';
        unset($data['currencyId'], $data['cardDataSource']);

        $restored = unserialize(self::serializedSale($data));

        self::assertInstanceOf(SaleTransactionRequest::class, $restored);
        self::assertNull($restored->amount);
        self::assertNull($restored->billingAddress);
        self::assertNull($restored->currencyId);
        self::assertSame(1, $restored->cardDataSource); // Internet default
    }

    public function testUnserializeRoundTripStaysMasked(): void
    {
        // A round-tripped request keeps the fail-closed masked card data.
        $request = new SaleTransactionRequest(accountNumber: '4111111111111111', securityCode: '987');

        $restored = unserialize(serialize($request));

        self::assertInstanceOf(SaleTransactionRequest::class, $restored);
        self::assertSame('************1111', $restored->accountNumber);
        self::assertSame('***', $restored->securityCode);
    }

    public function testAuthorizeMatchesSaleWireContract(): void
    {
        self::assertSame((new SaleTransactionRequest())->toArray(), (new AuthorizeTransactionRequest())->toArray());
        $args = [
            'amount' => 100.0,
            'accountNumber' => '4111111111111111',
            'currencyId' => 1,
            'expirationMonth' => 12,
            'expirationYear' => 2030,
            'securityCode' => '123',
            'customerId' => 'cust-1',
            'paymentMethodId' => 'pm-1',
            'deviceId' => 'dev-1',
            'paymentProcessorId' => 'proc-1',
            'tipAmount' => 1.25,
            'tipRate' => 0.0,
            'percentageOffRate' => 2.0,
            'surchargeRate' => 3.0,
            'useCardPrice' => false,
            'billingAddress' => new Address(line1: '123 Test St'),
            'shippingAddress' => new Address(city: 'Testville'),
            'contactInfo' => new ContactInfo(firstName: 'Ada'),
            'referenceId' => 'ref-eq',
            'customerInitiatedTransaction' => true,
            'cardDataSource' => 5,
        ];

        $sale = (new SaleTransactionRequest(...$args))->toArray();
        $authorize = (new AuthorizeTransactionRequest(...$args))->toArray();

        self::assertSame($sale, $authorize);
        self::assertSame(0.0, $authorize['tipRate']);
        self::assertFalse($authorize['useCardPrice']);
        self::assertSame(5, $authorize['cardDataSource']);
        self::assertSame('123', $authorize['securityCode']);
    }

    public function testIncompleteSaleIsConstructible(): void
    {
        // API owns validation: an empty request must serialize without error.
        $payload = (new SaleTransactionRequest(amount: 10.0))->toArray();

        self::assertSame(['amount' => 10.0, 'cardDataSource' => 1], $payload);
    }

    public function testCaptureVoidRefundSerialize(): void
    {
        self::assertSame(
            ['amount' => 25.0, 'transactionId' => 'tx-1'],
            (new CaptureTransactionRequest(transactionId: 'tx-1', amount: 25.0))->toArray(),
        );
        self::assertSame(
            ['transactionId' => 'tx-2'],
            (new VoidTransactionRequest(transactionId: 'tx-2'))->toArray(),
        );
        self::assertSame(
            ['transactionId' => 'tx-3', 'amount' => 10.0, 'cardDataSource' => 1],
            (new RefundTransactionRequest(transactionId: 'tx-3', amount: 10.0))->toArray(),
        );
    }

    public function testCalculateAmountBuildsQueryParams(): void
    {
        $request = new CalculateAmountRequest(amount: 100.0, tipRate: 10.0, currencyId: 1);

        self::assertSame(
            ['amount' => 100.0, 'tipRate' => 10.0, 'currencyId' => 1],
            $request->toQuery(),
        );
    }

    public function testCalculateAmountSerializesUseCardPriceAsStringBool(): void
    {
        // The query builder renders raw PHP bools as 1/0; useCardPrice must go
        // over the wire as the literal "true"/"false" (matches ListMerchantsRequest).
        self::assertSame(
            ['useCardPrice' => 'true'],
            (new CalculateAmountRequest(useCardPrice: true))->toQuery(),
        );
        self::assertSame(
            ['useCardPrice' => 'false'],
            (new CalculateAmountRequest(useCardPrice: false))->toQuery(),
        );
        // Null stays absent.
        self::assertSame([], (new CalculateAmountRequest())->toQuery());
    }

    public function testListTransactionsBuildsQueryParams(): void
    {
        self::assertSame(
            ['page' => 2, 'pageSize' => 50],
            (new ListTransactionsRequest(page: 2, pageSize: 50))->toQuery(),
        );
        self::assertSame([], (new ListTransactionsRequest())->toQuery());
        // page is zero-based, so 0 is valid (the first page).
        self::assertSame(['page' => 0], (new ListTransactionsRequest(page: 0))->toQuery());
    }

    public function testListTransactionsRejectsNegativePage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListTransactionsRequest(page: -1);
    }

    public function testListTransactionsRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListTransactionsRequest(pageSize: 0);
    }
}

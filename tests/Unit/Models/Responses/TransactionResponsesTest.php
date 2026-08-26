<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Responses;

use Flute\Sdk\Models\Responses\CalculateAmountResponse;
use Flute\Sdk\Models\Responses\ListTransactionsResponse;
use Flute\Sdk\Models\Responses\TransactionDetailsResponse;
use Flute\Sdk\Models\Responses\TransactionOutcomeDetails;
use Flute\Sdk\Models\Responses\TransactionResponse;
use PHPUnit\Framework\TestCase;

final class TransactionResponsesTest extends TestCase
{
    public function testTransactionResponseHydratesTypedFields(): void
    {
        $raw = [
            'transactionId' => 'tx-1',
            'transactionDateTime' => '2026-06-10T12:00:00Z',
            'typeId' => 1,
            'type' => 'Sale',
            'statusId' => 2,
            'status' => 'Approved',
            'processedAmount' => 103.5,
            'details' => [
                'code' => '00',
                'message' => 'Approved',
                'authCode' => 'A1B2C3',
                'maskedPan' => '************1111',
            ],
            'transactionReceipt' => [
                'amount' => [
                    'baseAmount' => 100.0,
                    'surchargeAmount' => 3.5,
                    'surchargeRate' => 3.5,
                    'totalAmount' => 103.5,
                ],
            ],
        ];

        $response = TransactionResponse::fromArray($raw);

        self::assertSame('tx-1', $response->transactionId);
        self::assertSame('Approved', $response->status);
        self::assertSame(2, $response->statusId);
        self::assertSame(103.5, $response->processedAmount);
        self::assertNotNull($response->details);
        self::assertSame('A1B2C3', $response->details->authCode);
        self::assertSame('************1111', $response->details->maskedPan);
        self::assertNotNull($response->receiptAmount);
        self::assertSame(100.0, $response->receiptAmount->baseAmount);
        self::assertSame(3.5, $response->receiptAmount->surchargeAmount);
        self::assertSame(103.5, $response->receiptAmount->totalAmount);
        self::assertSame($raw, $response->toArray());
        self::assertInstanceOf(\DateTimeImmutable::class, $response->transactionDateTimeAsObject());
    }

    public function testTransactionResponseToleratesMissingFields(): void
    {
        $response = TransactionResponse::fromArray(['transactionId' => 'tx-2']);

        self::assertSame('tx-2', $response->transactionId);
        self::assertNull($response->status);
        self::assertNull($response->details);
        self::assertNull($response->receiptAmount);
        self::assertNull($response->transactionDateTimeAsObject());
    }

    public function testMalformedPayloadsHydrateTolerantly(): void
    {
        $response = TransactionResponse::fromArray([
            'transactionDateTime' => ' ',
            'details' => 'not-an-array',
            'transactionReceipt' => 7,
        ]);

        self::assertNull($response->transactionDateTimeAsObject());
        self::assertNull($response->details);
        self::assertNull($response->receiptAmount);

        $garbage = TransactionResponse::fromArray(['transactionDateTime' => 'not-a-date']);
        self::assertNull($garbage->transactionDateTimeAsObject());

        $utc = TransactionResponse::fromArray(['transactionDateTime' => '2026-06-10T12:00:00Z']);
        self::assertNotNull($utc->transactionDateTimeAsObject());
        self::assertSame('2026-06-10T12:00:00+00:00', $utc->transactionDateTimeAsObject()->format(DATE_ATOM));

        $list = ListTransactionsResponse::fromArray(['items' => ['junk', 42, ['transactionId' => 'tx-ok']]]);
        self::assertCount(1, $list->items);
        self::assertSame('tx-ok', $list->items[0]->transactionId);
    }

    public function testTransactionDetailsResponseHydrates(): void
    {
        $raw = [
            'transactionId' => 'tx-3',
            'status' => 'Settled',
            'statusId' => 4,
            'baseAmount' => 50.0,
            'totalAmount' => 51.5,
            'currency' => 'USD',
            'customerPan' => '************1111',
            'authCode' => 'Z9Y8',
            'responseCode' => '00',
            'responseDescription' => 'Approved',
            'orderNumber' => 'ord-1',
        ];

        $details = TransactionDetailsResponse::fromArray($raw);

        self::assertSame('tx-3', $details->transactionId);
        self::assertSame('Settled', $details->status);
        self::assertSame(50.0, $details->baseAmount);
        self::assertSame(51.5, $details->totalAmount);
        self::assertSame('USD', $details->currency);
        self::assertSame($raw, $details->toArray());
    }

    public function testTransactionDetailsReadsNestedAmountForGetById(): void
    {
        // GET /transactions/{id} nests amounts under `amount` with no top-level
        // baseAmount/totalAmount (verified against the live sandbox).
        $details = TransactionDetailsResponse::fromArray([
            'transactionId' => 'tx-9',
            'status' => 'Settled',
            'amount' => [
                'baseAmount' => 100.0,
                'surchargeAmount' => 3.5,
                'totalAmount' => 103.5,
            ],
        ]);

        self::assertSame(100.0, $details->baseAmount);
        self::assertSame(103.5, $details->totalAmount);
        self::assertNotNull($details->amount);
        self::assertSame(100.0, $details->amount->baseAmount);
        self::assertSame(3.5, $details->amount->surchargeAmount);
        self::assertSame(103.5, $details->amount->totalAmount);
    }

    public function testTransactionDetailsPrefersTopLevelAmountOverNested(): void
    {
        // List items carry both shapes; the top-level values win, and the nested
        // breakdown is still exposed.
        $details = TransactionDetailsResponse::fromArray([
            'transactionId' => 'tx-10',
            'baseAmount' => 50.0,
            'totalAmount' => 51.5,
            'amount' => ['baseAmount' => 999.0, 'totalAmount' => 999.0],
        ]);

        self::assertSame(50.0, $details->baseAmount);
        self::assertSame(51.5, $details->totalAmount);
        self::assertNotNull($details->amount);
        self::assertSame(999.0, $details->amount->totalAmount);
    }

    public function testTransactionDetailsAmountNullWhenAbsent(): void
    {
        $details = TransactionDetailsResponse::fromArray(['transactionId' => 'tx-11']);

        self::assertNull($details->amount);
        self::assertNull($details->baseAmount);
        self::assertNull($details->totalAmount);
    }

    public function testTransactionDetailsFallbackMapsListRowIdAndDate(): void
    {
        // List rows key the identifier as `id` and the timestamp as `date`.
        // These fall back into transactionId/transactionDateTime so callers
        // get a reliable ID without reaching into toArray()['id'].
        $details = TransactionDetailsResponse::fromArray([
            'id' => 'tx-list-1',
            'date' => '2026-06-16T12:00:00Z',
            'status' => 'Approved',
        ]);

        self::assertSame('tx-list-1', $details->transactionId);
        self::assertSame('2026-06-16T12:00:00Z', $details->transactionDateTime);
        // Exercise the shared trait on its second consumer: the
        // fallback-mapped wire string parses to an immutable date. The first
        // consumer (TransactionResponse) is covered in testMalformedPayloads.
        self::assertInstanceOf(\DateTimeImmutable::class, $details->transactionDateTimeAsObject());
        self::assertSame(
            '2026-06-16T12:00:00+00:00',
            $details->transactionDateTimeAsObject()->format(DATE_ATOM),
        );
    }

    public function testTransactionDetailsFallsBackToCurrencyCodeForListRows(): void
    {
        // List rows expose the currency as `currencyCode`; get-by-id uses
        // `currency`. The DTO hydrates either so list-row currency is not lost.
        $listRow = TransactionDetailsResponse::fromArray([
            'id' => 'tx-list-2',
            'currencyCode' => 'USD',
        ]);
        self::assertSame('USD', $listRow->currency);

        // `currency` still wins when both are present.
        $both = TransactionDetailsResponse::fromArray([
            'currency' => 'EUR',
            'currencyCode' => 'USD',
        ]);
        self::assertSame('EUR', $both->currency);
    }

    public function testTransactionDetailsPrefersCanonicalIdOverListRowId(): void
    {
        // When both shapes are present, the get-by-id keys win.
        $details = TransactionDetailsResponse::fromArray([
            'transactionId' => 'tx-canonical',
            'id' => 'tx-list',
            'transactionDateTime' => '2026-06-16T01:00:00Z',
            'date' => '2026-06-16T02:00:00Z',
        ]);

        self::assertSame('tx-canonical', $details->transactionId);
        self::assertSame('2026-06-16T01:00:00Z', $details->transactionDateTime);
    }

    public function testTransactionDetailsDebugInfoMasksPan(): void
    {
        // Flute returns customerPan pre-masked, but the DTO hedges against a
        // server echoing a fuller value: __debugInfo() applies the card-style
        // scrub to the typed property and Redact::payload() to the retained raw
        // (mirrors the CreateMerchantApiKeyResponse masking).
        $details = TransactionDetailsResponse::fromArray([
            'transactionId' => 'tx-12',
            'customerPan' => '4111111111111111',
        ]);

        $debug = $details->__debugInfo();
        self::assertSame('************1111', $debug['customerPan']);
        self::assertStringNotContainsString('4111111111111111', (string) json_encode($debug['raw']));

        ob_start();
        var_dump($details);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('4111111111111111', $dump);

        // toArray() remains the explicit raw path.
        self::assertSame('4111111111111111', $details->toArray()['customerPan']);
    }

    public function testTransactionDetailsDebugInfoKeepsServerMaskedPanReadable(): void
    {
        // The wire value is already masked to its last four; the debug scrub
        // must not destroy it (last four are the maximum PCI permits showing).
        $details = TransactionDetailsResponse::fromArray([
            'customerPan' => '************4512',
        ]);

        self::assertSame('************4512', $details->__debugInfo()['customerPan']);
    }

    public function testOutcomeDetailsDebugInfoMasksPan(): void
    {
        $details = TransactionOutcomeDetails::fromArray([
            'code' => '00',
            'message' => 'Approved',
            'maskedPan' => '4111111111111111',
        ]);

        $debug = $details->__debugInfo();
        self::assertSame('************1111', $debug['maskedPan']);
        self::assertStringNotContainsString('4111111111111111', (string) json_encode($debug['raw']));
        // Diagnostics stay readable.
        self::assertSame('Approved', $debug['message']);

        ob_start();
        var_dump($details);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('4111111111111111', $dump);

        // toArray() remains the explicit raw path; a pre-masked value survives.
        self::assertSame('4111111111111111', $details->toArray()['maskedPan']);
        self::assertSame(
            '************1111',
            TransactionOutcomeDetails::fromArray(['maskedPan' => '************1111'])
                ->__debugInfo()['maskedPan'],
        );
    }

    public function testListResponseHydratesItems(): void
    {
        $list = ListTransactionsResponse::fromArray([
            'items' => [
                ['transactionId' => 'tx-1', 'status' => 'Approved'],
                ['transactionId' => 'tx-2', 'status' => 'Voided'],
            ],
            'total' => 2,
        ]);

        self::assertSame(2, $list->total);
        self::assertCount(2, $list->items);
        self::assertSame('tx-1', $list->items[0]->transactionId);
        self::assertSame('Voided', $list->items[1]->status);
    }

    public function testListResponseToleratesEmptyPayload(): void
    {
        $list = ListTransactionsResponse::fromArray([]);

        self::assertSame([], $list->items);
        self::assertNull($list->total);
    }

    public function testCalculateAmountResponseExposesPricingBreakdowns(): void
    {
        $response = CalculateAmountResponse::fromArray([
            'currency' => 'USD',
            'zeroCostProcessingOption' => 'DualPricing',
            'zeroCostProcessingOptionId' => 3,
            'useCardPrice' => true,
            'cash' => ['baseAmount' => 100.0, 'totalAmount' => 100.0],
            'creditCard' => ['baseAmount' => 100.0, 'totalAmount' => 103.5],
        ]);

        self::assertSame('DualPricing', $response->zeroCostProcessingOption);
        self::assertSame(3, $response->zeroCostProcessingOptionId);
        self::assertTrue($response->useCardPrice);
        self::assertSame(100.0, $response->cash?->totalAmount);
        self::assertSame(103.5, $response->creditCard?->totalAmount);
        self::assertNull($response->debitCard);
    }
}

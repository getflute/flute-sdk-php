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

    public function testGetSessionResponseHydratesCheckoutReadBackFields(): void
    {
        // Get body shape from the live sandbox (2026-08-23 verification run):
        // same keys and order, wire types as observed. checkoutUrl is not in the
        // get body (create response only) and surchargeAmount is null on a Created
        // session. The ACH fragments are synthetic — the sandbox cannot complete
        // an ACH payment.
        $raw = [
            'statusId' => 1,
            'status' => 'Created',
            'mode' => 1,
            'skipAddressVerification' => false,
            'customerId' => null,
            'vaultedPaymentMethodId' => null,
            'referenceId' => 'ref-1',
            'tipAmount' => null,
            'returnUrl' => 'https://merchant.example/checkout/return?order=ref-1',
            'paymentMethodTypes' => ['card', 'ach'],
            'metadata' => ['attempt' => '2', 'orderId' => 'wc-1042'],
            'afterCompletionMessage' => null,
            'pageName' => 'Merchant Checkout',
            'expiresAt' => '2026-08-24T00:00:00Z',
            'paymentNotes' => null,
            'achAccountLast2' => '34',
            'achRoutingLast2' => '21',
            'surchargeAmount' => null,
            'transactionDetails' => null,
        ];

        $response = PaymentSessionResponse::fromArray($raw);

        self::assertSame('https://merchant.example/checkout/return?order=ref-1', $response->returnUrl);
        self::assertSame(['attempt' => '2', 'orderId' => 'wc-1042'], $response->metadata);
        self::assertSame('2026-08-24T00:00:00Z', $response->expiresAt);
        self::assertSame('Merchant Checkout', $response->pageName);
        self::assertSame(['card', 'ach'], $response->paymentMethodTypes);
        self::assertNull($response->checkoutUrl);
        self::assertNull($response->surchargeAmount);
        self::assertSame('34', $response->achAccountLast2);
        self::assertSame('21', $response->achRoutingLast2);
        // Existing typed fields are unaffected.
        self::assertSame(1, $response->statusId);
        self::assertSame('ref-1', $response->referenceId);
        // Raw escape hatch still carries everything.
        self::assertSame($raw, $response->toArray());
    }

    public function testGetSessionResponseReadBackFieldsTolerateMissingAndMistypedValues(): void
    {
        $response = PaymentSessionResponse::fromArray([
            'metadata' => ['orderId' => 'wc-1', 'count' => 2],
            'paymentMethodTypes' => ['card', 3, 'ach'],
            'surchargeAmount' => '2.25',
            'achAccountLast2' => 34,
        ]);

        // Non-string map values and list entries are dropped, not laundered.
        self::assertSame(['orderId' => 'wc-1'], $response->metadata);
        self::assertSame(['card', 'ach'], $response->paymentMethodTypes);
        // Numeric strings hydrate as float, like every other amount.
        self::assertSame(2.25, $response->surchargeAmount);
        // An int fragment is not a string: null, still reachable via toArray().
        self::assertNull($response->achAccountLast2);
        self::assertSame(34, $response->toArray()['achAccountLast2']);
        self::assertNull($response->returnUrl);
        self::assertNull($response->expiresAt);
        self::assertNull($response->pageName);
        self::assertNull($response->checkoutUrl);
        self::assertNull($response->achRoutingLast2);

        // Zero is a real amount, not an absent one; a wire float passes through.
        self::assertSame(0.0, PaymentSessionResponse::fromArray(['surchargeAmount' => 0])->surchargeAmount);
        self::assertSame(1.5, PaymentSessionResponse::fromArray(['surchargeAmount' => 1.5])->surchargeAmount);
        // checkoutUrl is not on the get body today; a present value must still hydrate.
        self::assertSame(
            'https://checkout.example/s/ps-1',
            PaymentSessionResponse::fromArray(['checkoutUrl' => 'https://checkout.example/s/ps-1'])->checkoutUrl,
        );
        // A list-shaped metadata is not a map; an object-shaped method list is not a list.
        $mistyped = PaymentSessionResponse::fromArray([
            'metadata' => ['a', 'b'],
            'paymentMethodTypes' => ['primary' => 'card'],
        ]);
        self::assertNull($mistyped->metadata);
        self::assertNull($mistyped->paymentMethodTypes);
    }

    public function testGetSessionResponseDebugInfoScrubsAchFragmentsOnlyWhenFuller(): void
    {
        // Two-digit display fragments stay readable: the scrub masks runs of
        // three or more digits, so it fires only on a fuller echo.
        $short = PaymentSessionResponse::fromArray(['achAccountLast2' => '34', 'achRoutingLast2' => '21']);
        $debug = $short->__debugInfo();
        self::assertSame('34', $debug['achAccountLast2']);
        self::assertSame('21', $debug['achRoutingLast2']);
        self::assertStringContainsString('"achAccountLast2":"34"', (string) json_encode($debug['raw']));

        // A server echoing a fuller account or routing number is masked in the
        // typed view and in the retained raw.
        $fuller = PaymentSessionResponse::fromArray([
            'achAccountLast2' => '123456789012',
            'achRoutingLast2' => '021000021',
            'metadata' => ['orderId' => 'wc-1042'],
            'transactionDetails' => ['customerPan' => '4111111111111111'],
        ]);
        $debug = $fuller->__debugInfo();
        self::assertSame('************', $debug['achAccountLast2']);
        self::assertSame('*********', $debug['achRoutingLast2']);
        self::assertStringNotContainsString(
            '4111111111111111',
            (string) json_encode($debug['transactionDetails']),
        );
        $rawJson = (string) json_encode($debug['raw']);
        self::assertStringNotContainsString('123456789012', $rawJson);
        self::assertStringNotContainsString('021000021', $rawJson);
        self::assertStringNotContainsString('4111111111111111', $rawJson);
        // Diagnostics stay readable.
        self::assertStringContainsString('wc-1042', $rawJson);

        ob_start();
        var_dump($fuller);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('123456789012', $dump);
        self::assertStringNotContainsString('021000021', $dump);
        self::assertStringNotContainsString('4111111111111111', $dump);

        // toArray() remains the explicit raw path.
        self::assertSame('123456789012', $fuller->toArray()['achAccountLast2']);

        // An int echo under the ACH keys: not a string, so the typed property is
        // null, but the raw view still gets scrubbed — short stays readable, a
        // fuller run is masked.
        $intEcho = PaymentSessionResponse::fromArray([
            'achAccountLast2' => 34,
            'achRoutingLast2' => 21000021,
        ]);
        $debug = $intEcho->__debugInfo();
        self::assertNull($intEcho->achAccountLast2);
        self::assertNull($intEcho->achRoutingLast2);
        self::assertSame('34', $debug['raw']['achAccountLast2']);
        $rawJson = (string) json_encode($debug['raw']);
        self::assertStringNotContainsString('21000021', $rawJson);

        ob_start();
        var_dump($intEcho);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('21000021', $dump);

        self::assertSame(21000021, $intEcho->toArray()['achRoutingLast2']);
    }
}

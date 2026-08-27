<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Internal;

use Flute\Sdk\Internal\Redact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedactTest extends TestCase
{
    public function testMasksLuhnValidPanToLastFour(): void
    {
        // 4111111111111111 is the canonical Luhn-valid Visa test PAN.
        $out = Redact::text('Card 4111111111111111 was declined');

        self::assertStringNotContainsString('4111111111111111', $out);
        self::assertStringContainsString('************1111', $out);
    }

    public function testMasksPanWithSeparators(): void
    {
        $out = Redact::text('PAN 4111 1111 1111 1111 invalid');

        self::assertStringNotContainsString('4111 1111 1111 1111', $out);
        self::assertStringContainsString('************1111', $out);
    }

    public function testMasksFifteenDigitAmex(): void
    {
        // 378282246310005 is the canonical Luhn-valid Amex test PAN.
        $out = Redact::text('378282246310005');

        self::assertStringNotContainsString('378282246310005', $out);
        self::assertStringContainsString('0005', $out);
    }

    public function testMasksNonLuhnCardRun(): void
    {
        // A 16-digit run that fails Luhn (mistyped PAN) is still masked: the
        // Luhn gate was removed so card-like values cannot slip through.
        $out = Redact::text('reference 4111111111111112 ok');

        self::assertStringNotContainsString('4111111111111112', $out);
        self::assertStringContainsString('************1112', $out);
    }

    public function testLeavesShortNumbersUntouched(): void
    {
        $value = 'amount 100 status 91 correlation corr-12345';

        self::assertSame($value, Redact::text($value));
    }

    public function testDetailsMasksEachMessage(): void
    {
        $masked = Redact::details([
            'cardNumber' => ['4111111111111111 is invalid', 'try again'],
            'amount' => ['must be positive'],
        ]);

        self::assertStringNotContainsString('4111111111111111', $masked['cardNumber'][0]);
        self::assertSame('try again', $masked['cardNumber'][1]);
        self::assertSame(['must be positive'], $masked['amount']);
    }

    public function testDetailsMasksCvvUnderSensitiveKey(): void
    {
        // A CVV is far below the 13-digit PAN floor; only key-aware scrubbing
        // catches it when echoed under a sensitive field name.
        $masked = Redact::details([
            'securityCode' => ['123 is not a valid security code'],
        ]);

        self::assertStringNotContainsString('123', $masked['securityCode'][0]);
        self::assertStringContainsString('not a valid security code', $masked['securityCode'][0]);
    }

    public function testDetailsLeavesCvvLengthNumbersUnderNonSensitiveKey(): void
    {
        // The same short number under a non-sensitive key stays untouched so
        // amounts, counts, and status codes are not destroyed.
        $masked = Redact::details([
            'attempts' => ['123 attempts recorded'],
        ]);

        self::assertSame('123 attempts recorded', $masked['attempts'][0]);
    }

    public function testDetailsMasksOpaqueTokenUnderNonSensitiveKey(): void
    {
        // Non-sensitive keys get the same conservative treatment as free text,
        // which includes opaque-token masking. "apiKey" normalizes to
        // "apikey" — not a substring of any sensitive key (in particular not
        // "token") — so it takes the non-sensitive branch, which must still mask
        // an opaque secret the gateway echoes back. (Mirrors the free-text token
        // test; previously text()-only let this through unmasked.)
        $masked = Redact::details([
            'apiKey' => ['The key sk_live_abc123XYZ456def789 is invalid'],
        ]);

        self::assertStringNotContainsString('sk_live_abc123XYZ456def789', $masked['apiKey'][0]);
        foreach (['abc', 'XYZ', 'def'] as $fragment) {
            self::assertStringNotContainsString($fragment, $masked['apiKey'][0]);
        }
        self::assertStringContainsString('is invalid', $masked['apiKey'][0]);
    }

    public function testDetailsRedactsCredentialKeysWholesale(): void
    {
        // Credential keys are redacted WHOLESALE: a secret can be alphabetic or
        // mostly-alphabetic (no digits), which content masking would leave intact,
        // so the field name alone triggers full replacement. The surrounding prose
        // ("rejected", "expired") is sacrificed on purpose.
        $masked = Redact::details([
            'clientSecret' => ['secret flute_ab12cd34ef56gh78 rejected'],
            'accessToken' => ['token abc123XYZ789def0 expired'],
        ]);

        self::assertStringNotContainsString('flute_ab12cd34ef56gh78', $masked['clientSecret'][0]);
        self::assertStringNotContainsString('rejected', $masked['clientSecret'][0]);
        self::assertStringNotContainsString('abc123XYZ789def0', $masked['accessToken'][0]);
        self::assertStringNotContainsString('expired', $masked['accessToken'][0]);
    }

    public function testDetailsRedactsAlphabeticCredentialsWholesale(): void
    {
        // Regression: letter-only / no-digit secrets survived the
        // partial content scrub because they are neither digit runs nor mixed
        // alphanumeric tokens. Wholesale credential redaction must remove them.
        $masked = Redact::details([
            'clientSecret' => ['client secret cs_live_SUPERSECRET rejected'],
            'accessToken' => ['token tok_SUPERSECRET rejected'],
            'password' => ['password CORRECTHORSEBATTERYSTAPLE rejected'],
        ]);

        self::assertStringNotContainsString('SUPERSECRET', $masked['clientSecret'][0]);
        self::assertStringNotContainsString('SUPERSECRET', $masked['accessToken'][0]);
        self::assertStringNotContainsString('CORRECTHORSEBATTERYSTAPLE', $masked['password'][0]);
    }

    public function testSensitiveScrubLeavesNoTokenFragments(): void
    {
        // Regression: masking digit runs before opaque tokens used to split a
        // mixed token into "abc***XYZ***def0", leaving readable alpha fragments.
        // Exercised through a CARD key, which still uses the partial sensitive()
        // scrub (credential keys are now redacted wholesale instead).
        $masked = Redact::details([
            'accountNumber' => ['card abc123XYZ789def0 invalid'],
        ])['accountNumber'][0];

        foreach (['abc', 'XYZ', 'def'] as $fragment) {
            self::assertStringNotContainsString($fragment, $masked);
        }
        self::assertStringContainsString('invalid', $masked);
    }

    public function testDetailsMasksNonLuhnPanUnderKeyedErrors(): void
    {
        $masked = Redact::details([
            'accountNumber' => ['4111111111111112 was rejected'],
        ]);

        self::assertStringNotContainsString('4111111111111112', $masked['accountNumber'][0]);
        self::assertStringContainsString('was rejected', $masked['accountNumber'][0]);
    }

    public function testSensitiveKeyMatchingIgnoresCaseAndPunctuation(): void
    {
        $masked = Redact::details([
            'Security_Code' => ['999 invalid'],
        ]);

        self::assertStringNotContainsString('999', $masked['Security_Code'][0]);
    }

    public function testDetailsMasksPinAndTrackData(): void
    {
        // PIN and magnetic-stripe track data are prohibited SAD: their error
        // text is redacted WHOLESALE, not content-masked, because track/PIN
        // strings can carry alphabetic fragments (cardholder names, discretionary
        // data) that digit/token masking would leave intact. The surrounding
        // prose ("is not valid", "rejected") is sacrificed on purpose.
        $masked = Redact::details([
            'pin' => ['1234 is not valid'],
            'track2' => ['4111111111111111=22120000000000000000 rejected'],
        ]);

        self::assertStringNotContainsString('1234', $masked['pin'][0]);
        self::assertStringNotContainsString('is not valid', $masked['pin'][0]);
        self::assertStringNotContainsString('4111111111111111', $masked['track2'][0]);
        self::assertStringNotContainsString('rejected', $masked['track2'][0]);
    }

    public function testDetailsRedactsTrack1CardholderNameWholesale(): void
    {
        // Regression: a track1 sentinel preserves an alphabetic cardholder name
        // ("DOE/JOHN") through content-based masking — it is neither a digit run
        // nor a mixed-alnum token. Wholesale SAD redaction must remove it.
        $masked = Redact::details([
            'track1' => ['%B4111111111111111^DOE/JOHN^25051010000000000000? rejected'],
        ]);

        self::assertStringNotContainsString('DOE', $masked['track1'][0]);
        self::assertStringNotContainsString('JOHN', $masked['track1'][0]);
        self::assertStringNotContainsString('4111', $masked['track1'][0]);
    }

    public function testDetailsRedactsLongPinBlockWholesale(): void
    {
        $masked = Redact::details([
            'pinBlock' => ['0123456789ABCDEF rejected by HSM'],
        ]);

        self::assertStringNotContainsString('0123456789', $masked['pinBlock'][0]);
        self::assertStringNotContainsString('HSM', $masked['pinBlock'][0]);
    }

    /** @return iterable<string, array{string}> */
    public static function pinTrackKeyVariants(): iterable
    {
        yield 'pin' => ['pin'];
        yield 'PIN upper' => ['PIN'];
        yield 'pinBlock' => ['pinBlock'];
        yield 'pin_block punctuated' => ['pin_block'];
        yield 'track1' => ['track1'];
        yield 'track2' => ['track2'];
        yield 'trackData' => ['trackData'];
    }

    #[DataProvider('pinTrackKeyVariants')]
    public function testDetailsTreatsPinTrackVariantsAsSensitive(string $key): void
    {
        $masked = Redact::details([$key => ['999 bad']]);

        self::assertStringNotContainsString('999', $masked[$key][0], $key . ' should be sensitive');
    }

    public function testDetailsTreatsBarePanKeyAsCardData(): void
    {
        // A gateway validation error echoed under a bare "pan" key gets the
        // partial card scrub — digits masked, diagnostic prose preserved — not
        // wholesale redaction. Matched case-insensitively like every other key.
        $masked = Redact::details([
            'pan' => ['4111111111111111 was rejected', '123 is too short'],
            'PAN' => ['999 invalid'],
        ]);

        self::assertStringNotContainsString('4111111111111111', $masked['pan'][0]);
        self::assertStringContainsString('was rejected', $masked['pan'][0]);
        self::assertStringNotContainsString('123', $masked['pan'][1]);
        self::assertStringContainsString('is too short', $masked['pan'][1]);
        self::assertStringNotContainsString('999', $masked['PAN'][0]);
    }

    public function testDetailsDoesNotOvermatchBenignPanSubstrings(): void
    {
        // "company"/"companyName" contain "pan"; exact-match handling must keep
        // them non-sensitive so their short numbers survive.
        $masked = Redact::details([
            'company' => ['suite 123 rejected'],
            'companyName' => ['485 characters max'],
        ]);

        self::assertStringContainsString('123', $masked['company'][0]);
        self::assertStringContainsString('485', $masked['companyName'][0]);
    }

    public function testPayloadMasksBarePanKeyButNotCompany(): void
    {
        // payload() shares the key classification with details(): a bare "pan"
        // key is card data, "company" is not.
        $out = Redact::payload([
            'pan' => '4111111111111111',
            'company' => 'Suite 123',
        ]);

        self::assertSame('************1111', $out['pan']);
        self::assertSame('Suite 123', $out['company']);
    }

    public function testDetailsDoesNotOvermatchBenignPinTrackSubstrings(): void
    {
        // "shipping" contains "pin"; "trackingNumber" contains "track". Exact-match
        // handling must keep these non-sensitive so their short numbers survive.
        $masked = Redact::details([
            'shipping' => ['123 Main Street'],
            'trackingNumber' => ['order 456 shipped'],
        ]);

        self::assertStringContainsString('123', $masked['shipping'][0]);
        self::assertStringContainsString('456', $masked['trackingNumber'][0]);
    }

    public function testMessageMasksOpaqueTokenInFreeText(): void
    {
        // Top-level gateway Details could echo a submitted secret/token with no
        // sensitive field name to key off; message() masks it anyway.
        $out = Redact::message('Authorization failed for token abc123XYZ789def0 today');

        self::assertStringNotContainsString('abc123XYZ789def0', $out);
        foreach (['abc', 'XYZ', 'def'] as $fragment) {
            self::assertStringNotContainsString($fragment, $out);
        }
        self::assertStringContainsString('Authorization failed for token', $out);
    }

    public function testMessageMasksPanInFreeText(): void
    {
        $out = Redact::message('Card 4111111111111111 was declined');

        self::assertStringNotContainsString('4111111111111111', $out);
        self::assertStringContainsString('************1111', $out);
    }

    public function testMessageLeavesShortNumbersReadable(): void
    {
        // Amounts, status codes, and numeric order numbers in free text stay
        // visible: there is no field name to prove a short number is a CVV, and
        // destroying diagnostics is the wrong trade for top-level messages.
        $value = 'Declined for 500 on order 84219 with code 05';

        self::assertSame($value, Redact::message($value));
    }

    public function testMessageMasksHighEntropyReferenceIdsByDesign(): void
    {
        // Deliberate trade-off: the opaque-token scrub cannot tell a
        // leaked secret from a UUID-shaped correlation id or an alphanumeric
        // order/idempotency reference, so it masks all of them in free text. The
        // canonical correlation id is preserved separately via
        // FluteApiException::getCorrelationId() (pinned in ApiClientTest).
        $uuid = Redact::message('correlation ID 04a9afeb-7c1d-4e2a-9b3f-1a2b3c4d5e6f');
        self::assertStringNotContainsString('04a9afeb', $uuid);

        $ref = Redact::message('order ORD20260617XYZ rejected');
        self::assertStringNotContainsString('ORD20260617XYZ', $ref);
        self::assertStringContainsString('rejected', $ref);
    }

    public function testOpaqueTokenScrubStaysLinearOnLargeServerControlledBody(): void
    {
        // Regression: the opaque-token scrub matched 12+ char runs
        // with in-pattern lookaheads that rescanned the forward run at every
        // start position. On a long letterless dash run (each dash a fresh start
        // that never finds a letter) that was O(n^2) and did not trip PCRE's
        // backtrack limit, so a multi-KB gateway error body burned multi-second
        // CPU on the request thread. The single-match rewrite is linear.
        //
        // A pure-dash run has no letter+digit mix, so the scrub leaves it
        // readable and text() ignores it (no digits) -- output is unchanged and
        // the assertion is purely the time bound. With the quadratic regex this
        // input took ~4s; linear it is sub-millisecond.
        $body = str_repeat('-', 90000);

        $start = hrtime(true);
        $out = Redact::message($body);
        $elapsedSeconds = (hrtime(true) - $start) / 1e9;

        self::assertSame($body, $out);
        self::assertLessThan(2.0, $elapsedSeconds, 'opaque-token redaction must stay linear-time');
    }
}

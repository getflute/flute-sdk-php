<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Examples;

use Flute\Sdk\Tests\Support\LiveTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Offline guard over the example-output marker contract itself (the live suites
 * that consume it skip without credentials). Proves requireAll is genuinely
 * all-of, requireOneOf treats each branch group as all-of, and forbid catches
 * wrong-outcome strings — so a weakened contract is caught in `composer check`,
 * not only against the sandbox.
 */
final class ExampleMarkersTest extends TestCase
{
    public function testRequireAllFailsWhenOnlyOneMarkerPresent(): void
    {
        $markers = ['requireAll' => ['Authorized', 'Voided']];

        self::assertNotNull(
            LiveTestCase::markerViolation("Authorized tx-1: Authorized", $markers),
            'requireAll must fail when only one of its markers is present.',
        );
        self::assertNull(
            LiveTestCase::markerViolation("Authorized tx-1: Authorized\nVoided tx-1: Voided", $markers),
        );
    }

    public function testRequireOneOfPassesWhenAnyGroupMatchesInFull(): void
    {
        // The partner-lifecycle shape: a short-circuit branch, or the full flow.
        $markers = ['requireOneOf' => [
            ['No merchants visible'],
            ['Keys before rotation', 'Minted replacement', 'Revoked demo key', '(net zero)'],
        ]];

        // Short-circuit branch (single-string group) matches.
        self::assertNull(LiveTestCase::markerViolation('No merchants visible to this partner.', $markers));

        // Full lifecycle branch matches when every string in the group is present.
        $full = "Keys before rotation: 3\nMinted replacement: k-9\nRevoked demo key: k-9\n"
            . 'Keys after demo: 3 (net zero)';
        self::assertNull(LiveTestCase::markerViolation($full, $markers));
    }

    public function testRequireOneOfFailsOnAPartialLifecycleGroup(): void
    {
        $markers = ['requireOneOf' => [
            ['No merchants visible'],
            ['Keys before rotation', 'Minted replacement', 'Revoked demo key', '(net zero)'],
        ]];

        // Minted a key but never demonstrated revoke/net-zero: no group matches fully.
        $partial = "Keys before rotation: 3\nMinted replacement: k-9";
        self::assertNotNull(
            LiveTestCase::markerViolation($partial, $markers),
            'requireOneOf must reject a partially-matched lifecycle group.',
        );
    }

    public function testForbidFailsOnWrongOutcomeEvenWhenRequiredMarkerPresent(): void
    {
        $markers = [
            'requireAll' => ['Genuine payload: true'],
            'forbid' => ['Tampered payload: true'],
        ];

        self::assertNotNull(
            LiveTestCase::markerViolation("Genuine payload: true\nTampered payload: true", $markers),
            'forbid must fail even when the required marker is also present.',
        );
        self::assertNull(
            LiveTestCase::markerViolation("Genuine payload: true\nTampered payload: false", $markers),
        );
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        self::assertNull(
            LiveTestCase::markerViolation('TOTAL TRANSACTIONS: 5', ['requireAll' => ['Total transactions']]),
        );
    }

    public function testEmptyContractIsRejectedSoShallowOutputCannotPass(): void
    {
        self::assertNotNull(
            LiveTestCase::markerViolation('shallow success', []),
            'An empty marker contract must not pass on any non-empty stdout.',
        );
    }

    public function testForbidOnlyContractIsRejected(): void
    {
        // A contract that only forbids has no positive requirement, so it would
        // accept arbitrary output that merely avoids the forbidden string.
        self::assertNotNull(
            LiveTestCase::markerViolation('anything at all', ['forbid' => ['Unexpected:']]),
            'A forbid-only marker contract must be rejected.',
        );
    }
}

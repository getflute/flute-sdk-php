<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Examples;

use Flute\Sdk\Tests\Integration\PartnerExamplesTest;
use Flute\Sdk\Tests\Integration\ReadmeSamplesTest;
use PHPUnit\Framework\TestCase;

/**
 * Offline guard over the runnable example scripts, so a coverage gap is caught
 * in `composer check` rather than only when live credentials happen to be set:
 * the discovered example set must match the integration marker maps exactly, so
 * a deleted (or added) example cannot silently shrink/skip live coverage.
 *
 * Note: the partner onboarding example deliberately prints its once-only
 * clientSecret (sandbox-scoped, revoked in-script) — see ADR 0007 — so there is
 * intentionally no "examples must not print secrets" assertion here.
 */
final class ExampleCoverageTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testTopLevelExamplesMatchMarkerMap(): void
    {
        self::assertDiscoveredMatchesExpected(
            self::repoRoot() . '/examples/*.php',
            ReadmeSamplesTest::expectedExampleNames(),
        );
    }

    public function testPartnerExamplesMatchMarkerMap(): void
    {
        self::assertDiscoveredMatchesExpected(
            self::repoRoot() . '/examples/partner/*.php',
            PartnerExamplesTest::expectedExampleNames(),
        );
    }

    /** @param list<string> $expected */
    private static function assertDiscoveredMatchesExpected(string $glob, array $expected): void
    {
        $discovered = array_map('basename', glob($glob) ?: []);
        sort($discovered);
        sort($expected);

        self::assertSame(
            $expected,
            $discovered,
            'Discovered example scripts do not match the integration marker map. '
            . 'Add/remove the marker entry alongside the example file.',
        );
    }
}

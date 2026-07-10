<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Integration;

use Flute\Sdk\Tests\Support\LiveTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Executes the partner example scripts end-to-end (the same run-against-sandbox
 * pattern applied to the partner surface). The mutating examples are
 * self-cleaning, so the
 * suite is safe to run repeatedly. Each run is deadline-bounded and asserts an
 * expected output marker.
 */
final class PartnerExamplesTest extends LiveTestCase
{
    /**
     * Per-example marker contract (same shape as ReadmeSamplesTest). Each example
     * legitimately branches between the "no merchants visible" short-circuit and a
     * multi-step lifecycle, so each uses `requireOneOf`: a list of all-of groups
     * where at least one branch must match in full. That stops a partial lifecycle
     * (e.g. minting a key but never demonstrating the cleanup) from passing. These
     * examples report failure on STDERR with a non-zero exit (caught by the
     * exit-code assertion), so they have no wrong-outcome STDOUT strings to forbid.
     *
     * @var array<string, array{requireAll?: list<string>, requireOneOf?: list<list<string>>, forbid?: list<string>}>
     */
    private const MARKERS = [
        '01-list-merchants.php' => [
            'requireOneOf' => [['No merchants visible'], ['Total merchants']],
        ],
        '02-onboard-merchant.php' => [
            'requireOneOf' => [
                ['No merchants visible'],
                ['Client ID', 'Client secret', '(shown once', 'Demo cleanup:  key revoked.'],
            ],
        ],
        '03-rotate-merchant-key.php' => [
            'requireOneOf' => [
                ['No merchants visible'],
                ['Keys before rotation', 'Minted replacement', 'Revoked demo key', 'Keys after demo', '(net zero)'],
            ],
        ],
    ];

    #[DataProvider('exampleProvider')]
    public function testPartnerExampleRunsCleanly(string $script): void
    {
        $this->requirePartnerCredentials();

        // No marker fallback: an example without a marker entry must fail the
        // contract here too, not just in the offline ExampleCoverageTest — this
        // suite is documented as independently runnable.
        self::assertArrayHasKey(basename($script), self::MARKERS, 'No marker contract for ' . basename($script));

        $result = self::runScript($script);
        self::assertExampleOutput($result, self::MARKERS[basename($script)], basename($script));
    }

    /** @return iterable<string, array{string}> */
    public static function exampleProvider(): iterable
    {
        foreach (glob(self::repoRoot() . '/examples/partner/*.php') ?: [] as $script) {
            yield basename($script) => [$script];
        }
    }

    /**
     * Basenames every partner example is expected to have. Exposed so an offline
     * test can assert the discovered set matches the marker map.
     *
     * @return list<string>
     */
    public static function expectedExampleNames(): array
    {
        return array_keys(self::MARKERS);
    }
}

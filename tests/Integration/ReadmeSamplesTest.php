<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Integration;

use Flute\Sdk\Tests\Support\LiveTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Executes the runnable example scripts (which mirror the README samples)
 * against the sandbox, proving the samples run unmodified. Each run is
 * bounded by a deadline and asserts an expected output marker so an example
 * cannot regress to shallow output while still exiting 0.
 */
final class ReadmeSamplesTest extends LiveTestCase
{
    /**
     * Per-example marker contract. `requireAll` pins a single deterministic
     * branch to every intended outcome; `requireOneOf` is the branch-aware form
     * (a list of all-of groups — at least one branch must match fully) for
     * samples that legitimately short-circuit on sandbox data; `forbid` lists the
     * explicit wrong-outcome strings a sample prints on failure. Together they
     * stop a sample exiting 0 with partial or wrong output while still passing.
     *
     * @var array<string, array{requireAll?: list<string>, requireOneOf?: list<list<string>>, forbid?: list<string>}>
     */
    private const MARKERS = [
        // Deterministic approved-card sale: must report the captured status, not just any line.
        '01-sale.php' => ['requireAll' => ['Transaction', ': Captured']],
        '02-token-caching.php' => [
            'requireAll' => ['Token reused without re-authentication'],
            'forbid' => ['Unexpected:'],
        ],
        // Deterministic: must show both the genuine-true and tampered-false demonstrations.
        '03-webhook-verification.php' => [
            'requireAll' => ['Genuine payload: true', 'Tampered payload: false'],
            'forbid' => ['Genuine payload: false', 'Tampered payload: true'],
        ],
        // Branches on whether the sandbox has any transactions yet.
        '04-list-transactions.php' => [
            'requireOneOf' => [['No transactions yet'], ['Total transactions']],
        ],
        // Deterministic authorize-then-void: both steps must print.
        '05-void-transaction.php' => ['requireAll' => ['Authorized', 'Voided']],
        // Branches on FLUTE_REFUND_TX_ID: the refund itself, or the skip notice
        // when no settled transaction id is supplied (settlement is outside the
        // SDK surface, so the sample cannot mint one inline — see the script).
        '06-refund-transaction.php' => [
            'requireOneOf' => [[': Refunded'], ['Set FLUTE_REFUND_TX_ID']],
        ],
        // Deterministic: connectivity/retry demo, the handled 4xx, and the closing line.
        '07-handling-errors.php' => [
            'requireAll' => ['Connectivity OK', 'Handled expected API error for a bad id', 'Done.'],
            'forbid' => ['Unexpected:'],
        ],
    ];

    #[DataProvider('exampleProvider')]
    public function testExampleRunsCleanly(string $script): void
    {
        $this->requireCredentials();

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
        foreach (glob(self::repoRoot() . '/examples/*.php') ?: [] as $script) {
            yield basename($script) => [$script];
        }
    }

    /**
     * Basenames every top-level example is expected to have. Exposed so an
     * offline test can assert the discovered set matches (examples cannot vanish
     * or appear without updating the marker map).
     *
     * @return list<string>
     */
    public static function expectedExampleNames(): array
    {
        return array_keys(self::MARKERS);
    }
}

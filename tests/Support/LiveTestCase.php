<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Support;

use Flute\Sdk\Flute;
use PHPUnit\Framework\TestCase;

/**
 * Base for suites that hit the live Flute sandbox. Skips cleanly when
 * credentials are not configured. Each test builds its own Flute instance —
 * no state is shared between cases.
 *
 * FLUTE_CLIENT_ID/FLUTE_CLIENT_SECRET must be a merchant-scoped API token (minted via
 * POST /pay-api/v1/merchants/tokens). An ISV token without merchant context
 * fails transaction calls with 400 V0000 "MerchantId is required".
 *
 * FLUTE_PARTNER_CLIENT_ID/FLUTE_PARTNER_CLIENT_SECRET is the partner (ISV) credential;
 * it drives the $flute->merchants partner endpoints.
 */
abstract class LiveTestCase extends TestCase
{
    use TransactionAssertions;

    protected function requireCredentials(): void
    {
        if (self::env('FLUTE_CLIENT_ID') === null || self::env('FLUTE_CLIENT_SECRET') === null) {
            self::markTestSkipped(
                'Set FLUTE_CLIENT_ID and FLUTE_CLIENT_SECRET to run live sandbox tests.',
            );
        }
    }

    protected function requirePartnerCredentials(): void
    {
        if (self::env('FLUTE_PARTNER_CLIENT_ID') === null || self::env('FLUTE_PARTNER_CLIENT_SECRET') === null) {
            self::markTestSkipped(
                'Set FLUTE_PARTNER_CLIENT_ID and FLUTE_PARTNER_CLIENT_SECRET to run partner sandbox tests.',
            );
        }
    }

    /** @param array<string, mixed> $overrides */
    protected function flute(array $overrides = []): Flute
    {
        $this->requireCredentials();

        return new Flute($overrides + self::configFromEnv('FLUTE_CLIENT_ID', 'FLUTE_CLIENT_SECRET'));
    }

    /**
     * Client on the partner (ISV) credential for $flute->merchants calls.
     *
     * @param array<string, mixed> $overrides
     */
    protected function flutePartner(array $overrides = []): Flute
    {
        $this->requirePartnerCredentials();

        return new Flute($overrides + self::configFromEnv('FLUTE_PARTNER_CLIENT_ID', 'FLUTE_PARTNER_CLIENT_SECRET'));
    }

    /** @return array<string, mixed> */
    private static function configFromEnv(string $idVar, string $secretVar): array
    {
        $config = [
            'clientId' => (string) self::env($idVar),
            'clientSecret' => (string) self::env($secretVar),
            'environment' => 'sandbox',
        ];
        $baseUrl = self::env('FLUTE_API_BASE_URL');
        if ($baseUrl !== null) {
            $config['baseUrl'] = $baseUrl;
        }
        $oauthBaseUrl = self::env('FLUTE_OAUTH_BASE_URL');
        if ($oauthBaseUrl !== null) {
            $config['oauthBaseUrl'] = $oauthBaseUrl;
        }

        return $config;
    }

    protected static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }

    protected static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Run an example script as a child process with a hard deadline and
     * non-blocking pipe reads, so a hung example fails fast instead of stalling
     * CI. Returns the exit code and captured streams.
     *
     * Public so the exit-code/stream/timeout contract is unit-testable offline
     * (the live example suites that consume it skip without credentials).
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function runScript(string $script, int $timeoutSeconds = 60): array
    {
        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::repoRoot(),
            null, // inherit environment (credentials already exported)
        );
        if (!is_resource($process)) {
            self::fail('Failed to launch ' . $script);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $exitCode = -1;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if ($status['running'] === false) {
                // The first status read after exit holds the real code; once the
                // child is reaped here, proc_close() can only return -1, so the
                // exit code must be taken from this snapshot.
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(50_000); // 50ms between polls
        }

        // Drain anything buffered after the process exited.
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            self::fail(sprintf('%s did not finish within %ds (killed).', $script, $timeoutSeconds));
        }

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Assert an example exited cleanly, produced output, and matched its marker
     * contract — so an example cannot silently regress to shallow output, nor
     * print an explicit failure outcome, while still "passing".
     *
     * @param array{exitCode: int, stdout: string, stderr: string} $result
     * @param array{requireAll?: list<string>, requireOneOf?: list<list<string>>, forbid?: list<string>} $markers
     */
    protected static function assertExampleOutput(array $result, array $markers, string $script): void
    {
        ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr] = $result;

        self::assertSame(0, $exitCode, "Example $script failed.\nstdout: $stdout\nstderr: $stderr");
        self::assertNotSame('', trim($stdout), "Example $script produced no stdout.\nstderr: $stderr");

        $violation = self::markerViolation($stdout, $markers);
        self::assertNull(
            $violation,
            sprintf("Example %s %s.\nstdout: %s", $script, (string) $violation, $stdout),
        );
    }

    /**
     * Pure marker check (no PHPUnit assertions), so the contract is unit-testable
     * offline. Returns a human-readable violation, or null when $stdout satisfies
     * the contract. All matching is case-insensitive.
     *
     *  - forbid:       stdout must contain none (the explicit wrong-outcome strings).
     *  - requireAll:   stdout must contain every one (a single deterministic branch).
     *  - requireOneOf: a list of groups; stdout must fully match at least one group
     *                  (every string in that group present). This is the branch-aware
     *                  form: each branch a sample can legitimately take — e.g. the
     *                  "no merchants visible" short-circuit vs. the full lifecycle —
     *                  is one all-of group, so a partial branch does not pass.
     *
     * A contract with no positive requirement (empty, or `forbid`-only) is itself a
     * violation: it would let an example pass on any non-empty stdout, which is the
     * shallow/fake-green regression this whole contract exists to prevent.
     *
     * @param array{requireAll?: list<string>, requireOneOf?: list<list<string>>, forbid?: list<string>} $markers
     */
    public static function markerViolation(string $stdout, array $markers): ?string
    {
        if (($markers['requireAll'] ?? []) === [] && ($markers['requireOneOf'] ?? []) === []) {
            return 'has no positive marker requirement (requireAll/requireOneOf)';
        }

        foreach ($markers['forbid'] ?? [] as $forbidden) {
            if (stripos($stdout, $forbidden) !== false) {
                return "emitted a forbidden marker [$forbidden] (wrong outcome)";
            }
        }

        foreach ($markers['requireAll'] ?? [] as $marker) {
            if (stripos($stdout, $marker) === false) {
                return "missing required marker [$marker]";
            }
        }

        $groups = $markers['requireOneOf'] ?? [];
        if ($groups !== []) {
            foreach ($groups as $group) {
                $missing = array_filter($group, static fn (string $m): bool => stripos($stdout, $m) === false);
                if ($missing === []) {
                    return null; // this branch fully matched
                }
            }

            $rendered = implode(' | ', array_map(
                static fn (array $group): string => '[' . implode(', ', $group) . ']',
                $groups,
            ));

            return "matched no complete branch group, one of: $rendered";
        }

        return null;
    }
}

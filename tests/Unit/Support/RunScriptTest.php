<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Support;

use Flute\Sdk\Tests\Support\LiveTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Offline guard over LiveTestCase::runScript() — the child-process runner the
 * live example suites depend on. Without this, the runner is only exercised when
 * credentials are present; the proc_close()/proc_get_status() exit-code bug was
 * latent precisely because offline runs skipped it. Each case drives a tiny
 * generated PHP script, so it needs no Flute credentials or sandbox access.
 */
final class RunScriptTest extends TestCase
{
    /** @var list<string> */
    private array $scripts = [];

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
        $this->scripts = [];
    }

    public function testExitZeroIsReportedExactly(): void
    {
        $script = $this->writeScript("<?php echo 'hello';");

        $result = LiveTestCase::runScript($script);

        self::assertSame(0, $result['exitCode']);
        self::assertSame('hello', $result['stdout']);
    }

    public function testNonZeroExitCodeIsPreservedNotCollapsedToMinusOne(): void
    {
        // The regression guard: proc_close() would return -1 here after the child
        // is reaped; the real code (7) must come from the status snapshot instead.
        $script = $this->writeScript("<?php exit(7);");

        $result = LiveTestCase::runScript($script);

        self::assertSame(7, $result['exitCode']);
    }

    public function testStdoutAndStderrAreBothDrained(): void
    {
        $script = $this->writeScript(
            "<?php fwrite(STDOUT, 'out-stream'); fwrite(STDERR, 'err-stream'); exit(0);",
        );

        $result = LiveTestCase::runScript($script);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('out-stream', $result['stdout']);
        self::assertStringContainsString('err-stream', $result['stderr']);
    }

    public function testTimeoutFailsWithKilledMessage(): void
    {
        $script = $this->writeScript("<?php sleep(30);");

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('did not finish within 1s (killed)');

        LiveTestCase::runScript($script, 1);
    }

    private function writeScript(string $php): string
    {
        $path = tempnam(sys_get_temp_dir(), 'runscript_') ?: self::fail('Could not create temp script.');
        file_put_contents($path, $php);
        $this->scripts[] = $path;

        return $path;
    }
}

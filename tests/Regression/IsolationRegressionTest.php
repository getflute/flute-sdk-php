<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

/**
 * Multi-instance isolation scenario (H-25).
 */
final class IsolationRegressionTest extends RegressionTestCase
{
    /** @testdox H-25: two SDK instances with different credentials keep separate tokens */
    public function testInstancesKeepSeparateTokens(): void
    {
        $clientId2 = self::env('FLUTE_PARTNER_CLIENT_ID');
        $clientSecret2 = self::env('FLUTE_PARTNER_CLIENT_SECRET');
        if ($clientId2 === null || $clientSecret2 === null) {
            self::markTestSkipped(
                'Set FLUTE_PARTNER_CLIENT_ID and FLUTE_PARTNER_CLIENT_SECRET (a second sandbox '
                . 'credential, e.g. the ISV pair) to run the instance-isolation scenario.',
            );
        }

        $first = $this->flute();
        $second = $this->flute(['clientId' => $clientId2, 'clientSecret' => $clientSecret2]);

        $tokenA = $first->sessions->getAccessToken();
        $tokenB = $second->sessions->getAccessToken();

        self::assertNotSame('', $tokenA);
        self::assertNotSame('', $tokenB);
        self::assertNotSame($tokenA, $tokenB);
        // Each instance keeps returning its own cached token verbatim.
        self::assertSame($tokenA, $first->sessions->getAccessToken());
        self::assertSame($tokenB, $second->sessions->getAccessToken());
    }
}

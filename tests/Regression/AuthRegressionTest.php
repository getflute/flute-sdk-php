<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;

/**
 * Authentication lifecycle scenarios (H-1, H-2, H-18, H-19, H-20).
 */
final class AuthRegressionTest extends RegressionTestCase
{
    /** @testdox H-1: valid credentials yield a non-empty bearer token */
    public function testValidCredentialsYieldToken(): void
    {
        $token = $this->flute()->sessions->getAccessToken();

        self::assertNotSame('', $token);
    }

    /** @testdox H-2: an invalid clientSecret raises FluteAuthException on the first API call */
    public function testInvalidSecretFailsOnFirstApiCall(): void
    {
        $flute = $this->flute(['clientSecret' => 'definitely-wrong-secret']);

        $this->expectException(FluteAuthException::class);
        $flute->transactions->listTransactions();
    }

    /** @testdox H-18: a pre-supplied accessToken is returned verbatim */
    public function testPresuppliedTokenReturnedVerbatim(): void
    {
        $token = $this->flute()->sessions->getAccessToken();
        $second = $this->flute(['accessToken' => $token]);

        self::assertSame($token, $second->sessions->getAccessToken());
    }

    /** @testdox H-19: refreshAccessToken returns a usable token */
    public function testRefreshReturnsUsableToken(): void
    {
        $flute = $this->flute();

        $refreshed = $flute->sessions->refreshAccessToken();

        self::assertNotSame('', $refreshed);
        // The refreshed token authorizes API calls (page is zero-based).
        $page = $flute->transactions->listTransactions(new ListTransactionsRequest(page: 0, pageSize: 1));
        self::assertNotNull($page->total);
    }

    /** @testdox H-20: clearStoredToken forces transparent re-authentication */
    public function testClearedTokenReauthenticatesTransparently(): void
    {
        $flute = $this->flute();
        self::assertNotSame('', $flute->sessions->getAccessToken());

        $flute->sessions->clearStoredToken();

        $page = $flute->transactions->listTransactions(new ListTransactionsRequest(page: 0, pageSize: 1));
        self::assertNotNull($page->total);
        self::assertNotSame('', $flute->sessions->getAccessToken());
    }
}

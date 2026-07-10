<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Integration;

use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Tests\Support\LiveTestCase;

final class AuthIntegrationTest extends LiveTestCase
{
    public function testTokenAcquisitionReturnsBearer(): void
    {
        $token = $this->flute()->sessions->getAccessToken();

        self::assertNotSame('', $token);
    }

    public function testInvalidSecretThrowsAuthException(): void
    {
        $flute = $this->flute(['clientSecret' => 'definitely-wrong-secret']);

        $this->expectException(FluteAuthException::class);
        $flute->sessions->authenticate();
    }

    public function testPresuppliedTokenIsReused(): void
    {
        $token = $this->flute()->sessions->getAccessToken();
        $second = $this->flute(['accessToken' => $token]);

        self::assertSame($token, $second->sessions->getAccessToken());
    }
}

<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Enums;

use Flute\Sdk\Enums\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testSandboxHosts(): void
    {
        $env = Environment::SANDBOX;

        self::assertSame('sandbox', $env->value);
        self::assertSame('https://sandbox.api.flute.com', $env->apiBaseUrl());
        self::assertSame('https://sandbox.oauth.api.flute.com', $env->oauthBaseUrl());
    }

    public function testProductionHosts(): void
    {
        $env = Environment::PRODUCTION;

        self::assertSame('production', $env->value);
        self::assertSame('https://api.flute.com', $env->apiBaseUrl());
        self::assertSame('https://oauth.api.flute.com', $env->oauthBaseUrl());
    }

    public function testFromStringAcceptsLowercaseNames(): void
    {
        self::assertSame(Environment::SANDBOX, Environment::from('sandbox'));
        self::assertSame(Environment::PRODUCTION, Environment::from('production'));
    }
}

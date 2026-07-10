<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Exceptions;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Exceptions\FluteSdkException;
use Flute\Sdk\Exceptions\FluteWebhookException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function testAllExceptionsExtendBase(): void
    {
        self::assertInstanceOf(FluteSdkException::class, new FluteApiException('x', 400));
        self::assertInstanceOf(FluteSdkException::class, new FluteAuthException('x'));
        self::assertInstanceOf(FluteSdkException::class, new FluteNetworkException('x'));
        self::assertInstanceOf(FluteSdkException::class, new FluteWebhookException('x'));
        self::assertInstanceOf(\RuntimeException::class, new FluteAuthException('x'));
    }

    public function testApiExceptionExposesStructuredFields(): void
    {
        $e = new FluteApiException(
            message: 'Validation failed: amount is required',
            statusCode: 400,
            errorCode: 'V0000',
            correlationId: 'abc-123',
            retryAfterSeconds: null,
            errorDetails: ['amount' => ['Amount is required']],
        );

        self::assertSame(400, $e->getStatusCode());
        self::assertSame('V0000', $e->getErrorCode());
        self::assertSame('abc-123', $e->getCorrelationId());
        self::assertNull($e->getRetryAfterSeconds());
        self::assertSame(['amount' => ['Amount is required']], $e->getErrorDetails());
    }

    public function testApiExceptionRetryAfterFor429(): void
    {
        $e = new FluteApiException('Rate limited', 429, 'RATE_LIMITED', null, 30);

        self::assertSame(429, $e->getStatusCode());
        self::assertSame(30, $e->getRetryAfterSeconds());
    }
}

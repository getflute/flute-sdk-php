<?php

declare(strict_types=1);

namespace Flute\Sdk\Exceptions;

/**
 * A non-2xx API response. Carries HTTP status, the Flute error code,
 * correlation id, field-level validation errors, and (on 429) Retry-After. The
 * SDK never retries 429 itself — the delay is surfaced for the caller to act on.
 */
final class FluteApiException extends FluteSdkException
{
    /**
     * @param array<string, list<string>> $errorDetails Field-level validation errors.
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $errorCode = null,
        private readonly ?string $correlationId = null,
        private readonly ?int $retryAfterSeconds = null,
        private readonly array $errorDetails = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Seconds from the Retry-After header on 429 responses; null when absent.
     * The SDK never retries 429 itself.
     */
    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getErrorDetails(): array
    {
        return $this->errorDetails;
    }
}

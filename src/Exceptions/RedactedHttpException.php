<?php

declare(strict_types=1);

namespace Flute\Sdk\Exceptions;

/**
 * Sanitized stand-in for the underlying HTTP-client exception, attached as
 * getPrevious() on SDK exceptions. Carries only non-sensitive request context
 * (method, path, HTTP status) — never headers, bodies, credentials, or card
 * data. This keeps the getPrevious() chain safe to log.
 *
 * Note this covers the getPrevious() reference and the exception messages, not
 * the owning exception's getTrace()['args'], which PHP captures independently
 * unless zend.exception_ignore_args=On; the card-data/credential parameters are
 * separately marked #[\SensitiveParameter] (active PHP 8.2+) to redact those.
 *
 * Deliberately not a FluteSdkException: it is a diagnostic breadcrumb, not a
 * failure mode you catch.
 */
final class RedactedHttpException extends \RuntimeException
{
    public static function from(string $method, string $path, ?int $status): self
    {
        $target = trim(strtoupper($method) . ' ' . $path);
        $message = $status !== null
            ? sprintf('Underlying HTTP request failed: %s responded HTTP %d.', $target, $status)
            : sprintf('Underlying HTTP request failed: %s did not complete.', $target);

        return new self($message);
    }
}

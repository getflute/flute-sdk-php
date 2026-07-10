<?php

declare(strict_types=1);

namespace Flute\Sdk\Resources;

use Flute\Sdk\Exceptions\FluteWebhookException;

/**
 * Webhook signature verification. Framework-agnostic: accepts plain
 * strings, never a framework request object.
 */
final class WebhooksResource
{
    private const SCHEME = 'v1';
    private const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verifies the HMAC-SHA256 signature of an incoming webhook request.
     *
     * The signed content is "{id}.{timestamp}.{rawBody}". Pass the exact raw
     * request body bytes — re-serializing parsed JSON breaks the HMAC.
     *
     * Returns false on signature mismatch, unknown scheme, or undecodable
     * signature. Throws only when a parameter is empty, so callers can
     * distinguish client mistakes (400) from failed verification (401).
     *
     * Note: this method does not check timestamp freshness; pair it with
     * isTimestampFresh() to reject replayed deliveries.
     *
     * @param string $signatureHeader Value of Flute-Webhook-Signature ("v1,<base64>")
     * @param string $idHeader Value of Flute-Webhook-Id
     * @param string $timestampHeader Value of Flute-Webhook-Timestamp (unix seconds)
     * @param string $rawRequestBody Exact raw request body, before any parsing
     * @param string $signatureSecret HMAC secret returned when the endpoint was created
     *
     * @throws FluteWebhookException when any parameter is an empty string
     */
    public function verifySignature(
        string $signatureHeader,
        string $idHeader,
        string $timestampHeader,
        #[\SensitiveParameter] string $rawRequestBody,
        #[\SensitiveParameter] string $signatureSecret,
    ): bool {
        $this->assertNotEmpty($signatureHeader, 'signatureHeader');
        $this->assertNotEmpty($idHeader, 'idHeader');
        $this->assertNotEmpty($timestampHeader, 'timestampHeader');
        $this->assertNotEmpty($rawRequestBody, 'rawRequestBody');
        $this->assertNotEmpty($signatureSecret, 'signatureSecret');

        $expected = $this->parseSignatureHeader($signatureHeader);
        if ($expected === null) {
            return false;
        }

        $signedContent = $idHeader . '.' . $timestampHeader . '.' . $rawRequestBody;
        $computed = hash_hmac('sha256', $signedContent, $signatureSecret, true);

        return hash_equals($computed, $expected);
    }

    /**
     * Replay-safe verification: HMAC signature AND timestamp freshness in one
     * call. Prefer this over verifySignature() unless you have a specific reason
     * to validate the HMAC alone — it makes replay protection the default rather
     * than an extra step a caller can forget.
     *
     * Returns true only when the signature is valid and the timestamp is within
     * $toleranceSeconds of the current clock. Note that the HMAC covers the
     * timestamp, so a forged-fresh timestamp also fails the signature check.
     *
     * Persisting the Flute-Webhook-Id and skipping already-seen ids remains the
     * caller's responsibility for full at-least-once dedupe within the window.
     *
     * @throws FluteWebhookException when any signature parameter is empty
     */
    public function verify(
        string $signatureHeader,
        string $idHeader,
        string $timestampHeader,
        #[\SensitiveParameter] string $rawRequestBody,
        #[\SensitiveParameter] string $signatureSecret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        return $this->verifySignature(
            $signatureHeader,
            $idHeader,
            $timestampHeader,
            $rawRequestBody,
            $signatureSecret,
        ) && $this->isTimestampFresh($timestampHeader, $toleranceSeconds);
    }

    /**
     * Opt-in replay protection: whether the timestamp header is within
     * $toleranceSeconds of the current clock.
     */
    public function isTimestampFresh(
        string $timestampHeader,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        if (!is_numeric($timestampHeader)) {
            return false;
        }

        /*
         * abs() rejects timestamps too far in the future as well as the past,
         * guarding against clock skew and spoofed forward-dated timestamps.
         */
        return abs(time() - (int) $timestampHeader) <= $toleranceSeconds;
    }

    private function assertNotEmpty(string $value, string $name): void
    {
        if ($value === '') {
            throw new FluteWebhookException(
                sprintf('Webhook verification: "%s" is required and must be a non-empty string.', $name),
            );
        }
    }

    /** Decoded signature bytes, or null for an unknown scheme / invalid base64. */
    private function parseSignatureHeader(string $header): ?string
    {
        $separator = strpos($header, ',');
        if ($separator === false || $separator === 0) {
            return null;
        }
        if (substr($header, 0, $separator) !== self::SCHEME) {
            return null;
        }

        $encoded = substr($header, $separator + 1);
        if ($encoded === '') {
            return null;
        }

        // Strict mode rejects characters outside the base64 alphabet (whitespace is still skipped).
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }
}

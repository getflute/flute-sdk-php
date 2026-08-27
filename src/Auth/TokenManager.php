<?php

declare(strict_types=1);

namespace Flute\Sdk\Auth;

use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Exceptions\RedactedHttpException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Per-instance OAuth2 client-credentials token lifecycle.
 *
 * Tokens are cached in memory for this instance only. A pre-supplied token is
 * used as-is with unknown expiry; expiry recovers through the 401 retry path.
 * The SDK never persists tokens.
 *
 * @internal
 */
final class TokenManager
{
    /** Mask for the client secret and cached bearer token in generic views. */
    private const SECRET_PLACEHOLDER = '***redacted***';

    private ?string $token;

    /** Unix timestamp; null for pre-supplied tokens with unknown expiry. */
    private ?int $expiresAt = null;

    /**
     * Refresh buffer actually applied to the current token: the configured
     * buffer clamped below the token's lifetime (set on acquisition). Defaults
     * to the configured value until the first acquire().
     */
    private int $effectiveBufferSeconds;

    /**
     * Clock seam: returns the current Unix time. Defaults to time(); injectable
     * so the proactive-refresh window is testable without a real sleep.
     *
     * @var \Closure(): int
     */
    private readonly \Closure $now;

    /**
     * @param (callable(): int)|null $now Clock override (testing); defaults to time()
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly int $refreshBufferSeconds,
        private readonly int $timeoutSeconds,
        #[\SensitiveParameter] ?string $presuppliedToken = null,
        ?callable $now = null,
    ) {
        $this->token = $presuppliedToken;
        $this->effectiveBufferSeconds = $refreshBufferSeconds;
        $this->now = $now !== null
            ? \Closure::fromCallable($now)
            : static fn (): int => time();
    }

    /**
     * Return a usable token, acquiring or refreshing when needed (lazy
     * acquisition on first need).
     *
     * @throws FluteAuthException when the token endpoint rejects the request
     * @throws FluteNetworkException when the token endpoint is unreachable
     */
    public function getAccessToken(): string
    {
        if ($this->token !== null && !$this->expiresSoon()) {
            return $this->token;
        }

        return $this->acquire();
    }

    /**
     * Force immediate re-acquisition regardless of expiry state.
     *
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function refresh(): string
    {
        /*
         * Drop the cache first so a failed refresh is fail-closed: a caller that
         * forced a refresh (e.g. knowing the token was revoked) must never fall
         * back to the old cached token.
         */
        $this->clear();

        return $this->acquire();
    }

    /** Drop the cached token; the next call re-authenticates. */
    public function clear(): void
    {
        $this->token = null;
        $this->expiresAt = null;
    }

    /**
     * Fail closed for the generic serialization paths — var_dump()/print_r()/
     * VarDumper (__debugInfo) and serialize() (__serialize) — so dumping a Flute
     * client (which reaches this manager through every resource) cannot leak the
     * OAuth clientSecret or the cached bearer token. The http client is reduced
     * to its class name (a custom client can carry its own secrets) and the
     * clock closure to its type (closures are unserializable). var_export() has
     * no maskable hook and stays unsafe by design.
     *
     * @return array<string, mixed>
     */
    private function maskedView(): array
    {
        return [
            // isset(): the client is left uninitialized by __unserialize().
            // @phpstan-ignore isset.property
            'httpClient' => isset($this->httpClient) ? get_debug_type($this->httpClient) : null,
            'tokenUrl' => $this->tokenUrl,
            'clientId' => $this->clientId,
            'clientSecret' => self::SECRET_PLACEHOLDER,
            'refreshBufferSeconds' => $this->refreshBufferSeconds,
            'timeoutSeconds' => $this->timeoutSeconds,
            'token' => $this->token === null ? null : self::SECRET_PLACEHOLDER,
            'expiresAt' => $this->expiresAt,
            'effectiveBufferSeconds' => $this->effectiveBufferSeconds,
            'now' => get_debug_type($this->now),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->maskedView();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->maskedView();
    }

    /**
     * Restore from the redacted __serialize() payload (fail closed: no secret,
     * token, http client, or clock survives the round trip, so a restored
     * manager cannot authenticate). Managers are not meant to be serialized;
     * this only neutralizes accidental serialization.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // $httpClient stays uninitialized: only its class name was serialized.
        $this->tokenUrl = is_string($data['tokenUrl'] ?? null) ? $data['tokenUrl'] : '';
        $this->clientId = is_string($data['clientId'] ?? null) ? $data['clientId'] : '';
        $this->clientSecret = self::SECRET_PLACEHOLDER;
        $this->refreshBufferSeconds = is_int($data['refreshBufferSeconds'] ?? null)
            ? $data['refreshBufferSeconds']
            : 0;
        $this->timeoutSeconds = is_int($data['timeoutSeconds'] ?? null) ? $data['timeoutSeconds'] : 0;
        $this->token = null;
        $this->expiresAt = null;
        $this->effectiveBufferSeconds = is_int($data['effectiveBufferSeconds'] ?? null)
            ? $data['effectiveBufferSeconds']
            : 0;
        $this->now = static fn (): int => time();
    }

    /** Whether the cached token is within the refresh buffer of expiry. */
    private function expiresSoon(): bool
    {
        /*
         * A pre-supplied token has unknown expiry, so we never proactively
         * refresh it — lean on the 401 retry path to recover if it has in fact
         * expired.
         */
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt - ($this->now)() <= $this->effectiveBufferSeconds;
    }

    private function acquire(): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                RequestOptions::FORM_PARAMS => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                RequestOptions::HEADERS  => ['Accept' => 'application/json'],
                RequestOptions::TIMEOUT  => $this->timeoutSeconds,
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);
        } catch (BadResponseException $e) {
            $status = $e->getResponse()->getStatusCode();
            throw self::authError($status, $this->redactedTokenContext($status));
        } catch (GuzzleException $e) {
            /*
             * Never echo the raw Guzzle message: it can include the token URL and
             * transport details. Path-only context lives on the redacted previous.
             */
            throw new FluteNetworkException(
                'Token endpoint unreachable.',
                0,
                $this->redactedTokenContext(null),
            );
        }

        // Any non-2xx fails closed: a 3xx must never yield a cacheable token.
        if ($response->getStatusCode() >= 300) {
            throw self::authError($response->getStatusCode());
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        // RFC-sloppy serializers send expires_in as a string or float; accept any
        // numeric form, rejecting non-positive values after the cast.
        $expiresIn = $decoded['expires_in'] ?? null;
        if (
            !is_array($decoded)
            || !is_string($decoded['access_token'] ?? null)
            || $decoded['access_token'] === ''
            || !is_numeric($expiresIn)
            || (int) $expiresIn <= 0
        ) {
            /*
             * A non-positive expires_in would put expiresAt at/before now and
             * drive effectiveBufferSeconds to <= 0, making expiresSoon() always
             * true — re-acquiring a token on every request (the thrash the
             * buffer clamp exists to prevent). Treat it as a malformed payload.
             */
            throw new FluteAuthException('Token endpoint returned an unexpected payload.');
        }
        $expiresIn = (int) $expiresIn;

        $token = $decoded['access_token'];
        $this->token = $token;
        $this->expiresAt = ($this->now)() + $expiresIn;
        /*
         * Clamp the effective buffer below the token's lifetime so a short-lived
         * token (expires_in <= configured buffer) is still cached for part of its
         * life instead of being treated as already stale on every read.
         */
        $this->effectiveBufferSeconds = min(
            $this->refreshBufferSeconds,
            (int) ($expiresIn / 2),
        );

        return $token;
    }

    /**
     * Sanitized previous for token-call failures: method, path, and status only.
     * The token request body (client credentials) is never retained.
     */
    private function redactedTokenContext(?int $status): RedactedHttpException
    {
        $path = parse_url($this->tokenUrl, PHP_URL_PATH);

        return RedactedHttpException::from('POST', is_string($path) ? $path : $this->tokenUrl, $status);
    }

    /** Build an auth failure whose message never includes credentials. */
    private static function authError(int $status, ?\Throwable $previous = null): FluteAuthException
    {
        return new FluteAuthException(
            sprintf(
                'Token acquisition failed with HTTP %d. Verify clientId/clientSecret and environment.',
                $status,
            ),
            0,
            $previous,
        );
    }
}

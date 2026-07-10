<?php

declare(strict_types=1);

namespace Flute\Sdk;

use Flute\Sdk\Enums\Environment;
use GuzzleHttp\ClientInterface;

/**
 * Immutable, validated SDK configuration.
 *
 * Built from the array passed to the Flute constructor. The SDK never reads
 * environment variables; the host application owns credential sourcing.
 */
final class FluteConfig implements \JsonSerializable
{
    private const TOKEN_PATH = '/oauth2/token';

    /** Mask for the OAuth client secret and bearer token in generic views. */
    private const SECRET_PLACEHOLDER = '***redacted***';

    private const KNOWN_KEYS = [
        'clientId',
        'clientSecret',
        'environment',
        'baseUrl',
        'oauthBaseUrl',
        'tokenRefreshBufferSeconds',
        'httpTimeoutSeconds',
        'accessToken',
        'httpClient',
    ];

    private function __construct(
        public readonly string $clientId,
        #[\SensitiveParameter] public readonly string $clientSecret,
        public readonly Environment $environment,
        public readonly string $apiBaseUrl,
        public readonly string $oauthTokenUrl,
        public readonly int $tokenRefreshBufferSeconds,
        public readonly int $httpTimeoutSeconds,
        #[\SensitiveParameter] public readonly ?string $accessToken,
        public readonly ?ClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws \InvalidArgumentException on missing/invalid configuration
     */
    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown config key "%s".', $key));
            }
        }

        $clientId = self::requireNonEmptyString($config, 'clientId');
        $clientSecret = self::requireNonEmptyString($config, 'clientSecret');
        $environment = self::parseEnvironment($config['environment'] ?? null);

        $apiBaseUrl = self::normalizeBaseUrl(
            $config['baseUrl'] ?? $environment->apiBaseUrl(),
            'baseUrl',
        );
        $oauthBaseUrl = self::normalizeBaseUrl(
            $config['oauthBaseUrl'] ?? $environment->oauthBaseUrl(),
            'oauthBaseUrl',
        );

        $accessToken = $config['accessToken'] ?? null;
        if ($accessToken !== null && (!is_string($accessToken) || $accessToken === '')) {
            throw new \InvalidArgumentException('"accessToken" must be a non-empty string.');
        }

        $httpClient = $config['httpClient'] ?? null;
        if ($httpClient !== null && !$httpClient instanceof ClientInterface) {
            throw new \InvalidArgumentException(
                '"httpClient" must implement GuzzleHttp\ClientInterface.',
            );
        }

        return new self(
            clientId: $clientId,
            clientSecret: $clientSecret,
            environment: $environment,
            apiBaseUrl: $apiBaseUrl,
            oauthTokenUrl: $oauthBaseUrl . self::TOKEN_PATH,
            tokenRefreshBufferSeconds: self::positiveInt($config, 'tokenRefreshBufferSeconds', 60),
            httpTimeoutSeconds: self::positiveInt($config, 'httpTimeoutSeconds', 30),
            accessToken: $accessToken,
            httpClient: $httpClient,
        );
    }

    /**
     * Fail closed for every generic serialization path — var_dump()/VarDumper
     * (__debugInfo), json_encode() (JsonSerializable), and serialize()
     * (__serialize) — so a config dumped while debugging setup cannot leak the
     * OAuth clientSecret or the bearer accessToken into logs/error trackers.
     * var_export() has no maskable hook (PHP exposes none) and stays unsafe by
     * design — never var_export() a config object.
     *
     * An injected httpClient is reduced to its class name: a custom client can
     * carry its own secrets (proxy auth, API keys, bearer tokens, middleware
     * state) in default headers, which var_dump()/print_r() would otherwise
     * recurse into and print. Only the type is shown, never the client's state.
     *
     * @return array<string, mixed>
     */
    private function maskedView(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => self::SECRET_PLACEHOLDER,
            'environment' => $this->environment,
            'apiBaseUrl' => $this->apiBaseUrl,
            'oauthTokenUrl' => $this->oauthTokenUrl,
            'tokenRefreshBufferSeconds' => $this->tokenRefreshBufferSeconds,
            'httpTimeoutSeconds' => $this->httpTimeoutSeconds,
            'accessToken' => $this->accessToken === null ? null : self::SECRET_PLACEHOLDER,
            'httpClient' => $this->httpClient === null ? null : get_debug_type($this->httpClient),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->maskedView();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->maskedView();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->maskedView();
    }

    /**
     * Restore from the redacted __serialize() payload (fail closed: a
     * round-tripped config carries a masked secret/token). Config objects are not
     * meant to be serialized; this only neutralizes accidental serialization.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        /*
         * Explicit per-property assignment with type guards (the
         * CreateMerchantApiKeyResponse pattern): unknown keys are ignored so no
         * deprecated dynamic property is created, a wrong-typed value falls back
         * to a fail-closed default instead of throwing an uncaught TypeError, and
         * a missing key hydrates here rather than deferring failure to first
         * property access. readonly promoted properties may be initialized once
         * because unserialize() builds the instance without the constructor.
         */
        $this->clientId = is_string($data['clientId'] ?? null) ? $data['clientId'] : '';
        // maskedView() serialized the placeholder; anything else stays masked too.
        $this->clientSecret = is_string($data['clientSecret'] ?? null)
            ? $data['clientSecret']
            : self::SECRET_PLACEHOLDER;
        $environment = $data['environment'] ?? null;
        $this->environment = $environment instanceof Environment ? $environment : Environment::SANDBOX;
        $this->apiBaseUrl = is_string($data['apiBaseUrl'] ?? null) ? $data['apiBaseUrl'] : '';
        $this->oauthTokenUrl = is_string($data['oauthTokenUrl'] ?? null) ? $data['oauthTokenUrl'] : '';
        $this->tokenRefreshBufferSeconds = is_int($data['tokenRefreshBufferSeconds'] ?? null)
            ? $data['tokenRefreshBufferSeconds']
            : 60;
        $this->httpTimeoutSeconds = is_int($data['httpTimeoutSeconds'] ?? null)
            ? $data['httpTimeoutSeconds']
            : 30;
        $this->accessToken = is_string($data['accessToken'] ?? null) ? $data['accessToken'] : null;
        /*
         * maskedView() reduces httpClient to a class-name string; coerce any
         * non-client value back to null so the typed readonly property is not
         * assigned a string (a round-tripped config is fail-closed anyway).
         */
        $httpClient = $data['httpClient'] ?? null;
        $this->httpClient = $httpClient instanceof ClientInterface ? $httpClient : null;
    }

    /** @param array<string, mixed> $config */
    private static function requireNonEmptyString(#[\SensitiveParameter] array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('"%s" is required and must be a non-empty string.', $key));
        }

        return $value;
    }

    private static function parseEnvironment(mixed $value): Environment
    {
        if ($value instanceof Environment) {
            return $value;
        }
        if (is_string($value)) {
            $environment = Environment::tryFrom(strtolower($value));
            if ($environment !== null) {
                return $environment;
            }
        }

        throw new \InvalidArgumentException(
            '"environment" is required and must be "sandbox", "production", or an Environment case.',
        );
    }

    private static function normalizeBaseUrl(#[\SensitiveParameter] mixed $value, string $key): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('"%s" must be a non-empty string.', $key));
        }

        $url = rtrim($value, '/');
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($host === '') {
            throw new \InvalidArgumentException(sprintf('"%s" must include a host.', $key));
        }

        // Userinfo would surface in cURL error messages via FluteNetworkException.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(sprintf('"%s" must not contain userinfo.', $key));
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException(
                sprintf('"%s" must not contain a query string or fragment.', $key),
            );
        }

        if ($scheme === 'https') {
            return $url;
        }
        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return $url;
        }

        throw new \InvalidArgumentException(
            sprintf('"%s" must use HTTPS (plain HTTP is allowed only for loopback hosts).', $key),
        );
    }

    /** @param array<string, mixed> $config */
    private static function positiveInt(#[\SensitiveParameter] array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a positive integer.', $key));
        }

        return $value;
    }
}

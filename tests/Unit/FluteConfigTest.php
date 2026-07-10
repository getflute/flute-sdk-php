<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit;

use Flute\Sdk\Enums\Environment;
use Flute\Sdk\FluteConfig;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FluteConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'clientId' => 'cid-1',
            'clientSecret' => 'sec-1',
            'environment' => 'sandbox',
        ];
    }

    public function testDefaultsForSandbox(): void
    {
        $config = FluteConfig::fromArray($this->valid());

        self::assertSame('cid-1', $config->clientId);
        self::assertSame('sec-1', $config->clientSecret);
        self::assertSame(Environment::SANDBOX, $config->environment);
        self::assertSame('https://sandbox.api.flute.com', $config->apiBaseUrl);
        self::assertSame(
            'https://sandbox.oauth.api.flute.com/oauth2/token',
            $config->oauthTokenUrl,
        );
        self::assertSame(60, $config->tokenRefreshBufferSeconds);
        self::assertSame(30, $config->httpTimeoutSeconds);
        self::assertNull($config->accessToken);
        self::assertNull($config->httpClient);
    }

    public function testAcceptsEnvironmentEnum(): void
    {
        $config = FluteConfig::fromArray(
            ['environment' => Environment::PRODUCTION] + $this->valid(),
        );

        self::assertSame(Environment::PRODUCTION, $config->environment);
        self::assertSame('https://api.flute.com', $config->apiBaseUrl);
    }

    public function testOverrides(): void
    {
        $http = new Client();
        $config = FluteConfig::fromArray($this->valid() + [
            'baseUrl' => 'https://proxy.example.com/',
            'oauthBaseUrl' => 'https://oauth-proxy.example.com',
            'tokenRefreshBufferSeconds' => 120,
            'httpTimeoutSeconds' => 5,
            'accessToken' => 'tok-presupplied',
            'httpClient' => $http,
        ]);

        self::assertSame('https://proxy.example.com', $config->apiBaseUrl);
        self::assertSame('https://oauth-proxy.example.com/oauth2/token', $config->oauthTokenUrl);
        self::assertSame(120, $config->tokenRefreshBufferSeconds);
        self::assertSame(5, $config->httpTimeoutSeconds);
        self::assertSame('tok-presupplied', $config->accessToken);
        self::assertSame($http, $config->httpClient);
    }

    #[DataProvider('missingFieldProvider')]
    public function testMissingRequiredFieldThrows(string $field): void
    {
        $input = $this->valid();
        unset($input[$field]);

        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray($input);
    }

    /** @return iterable<string, array{string}> */
    public static function missingFieldProvider(): iterable
    {
        yield 'clientId' => ['clientId'];
        yield 'clientSecret' => ['clientSecret'];
        yield 'environment' => ['environment'];
    }

    public function testInvalidEnvironmentStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray(['environment' => 'staging'] + $this->valid());
    }

    public function testPlainHttpBaseUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray($this->valid() + ['baseUrl' => 'http://api.example.com']);
    }

    public function testLoopbackHttpBaseUrlAllowed(): void
    {
        $config = FluteConfig::fromArray($this->valid() + [
            'baseUrl' => 'http://127.0.0.1:8080',
            'oauthBaseUrl' => 'http://localhost:8081',
        ]);

        self::assertSame('http://127.0.0.1:8080', $config->apiBaseUrl);
        self::assertSame('http://localhost:8081/oauth2/token', $config->oauthTokenUrl);
    }

    public function testNonPositiveTimeoutThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray($this->valid() + ['httpTimeoutSeconds' => 0]);
    }

    public function testUnknownConfigKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray($this->valid() + ['timeout' => 10]);
    }

    public function testHostlessBaseUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray($this->valid() + ['baseUrl' => 'https://']);
    }

    public function testUppercaseSchemeBaseUrlAccepted(): void
    {
        $config = FluteConfig::fromArray($this->valid() + ['baseUrl' => 'HTTPS://proxy.example.com']);

        self::assertSame('HTTPS://proxy.example.com', $config->apiBaseUrl);
    }

    public function testUppercaseLoopbackHostHttpAccepted(): void
    {
        $config = FluteConfig::fromArray($this->valid() + ['baseUrl' => 'http://LOCALHOST:8080']);

        self::assertSame('http://LOCALHOST:8080', $config->apiBaseUrl);
    }

    public function testBaseUrlWithQueryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray($this->valid() + ['baseUrl' => 'https://api.example.com?tenant=1']);
    }

    public function testOauthBaseUrlWithFragmentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray($this->valid() + ['oauthBaseUrl' => 'https://oauth.example.com#section']);
    }

    public function testBaseUrlWithUserinfoThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FluteConfig::fromArray($this->valid() + ['baseUrl' => 'https://user:pass@api.example.com']);
    }

    public function testGenericSerializationMasksClientSecretAndAccessToken(): void
    {
        $config = FluteConfig::fromArray([
            'clientId' => 'cid-1',
            'clientSecret' => 'sec_SUPERSECRET',
            'environment' => 'sandbox',
            'accessToken' => 'tok_SUPERSECRET',
        ]);

        // Every generic path a debugging integrator might hit — except var_export,
        // which has no maskable hook (ADR 0010) and is documented as unsafe.
        ob_start();
        var_dump($config);
        $varDump = (string) ob_get_clean();

        $views = [
            'json_encode' => (string) json_encode($config),
            'serialize' => serialize($config),
            'print_r' => print_r($config, true),
            'var_dump' => $varDump,
        ];
        foreach ($views as $path => $output) {
            self::assertStringNotContainsString('sec_SUPERSECRET', $output, "clientSecret leaked via {$path}");
            self::assertStringNotContainsString('tok_SUPERSECRET', $output, "accessToken leaked via {$path}");
        }

        // Direct property access still returns the real credential for SDK use.
        self::assertSame('sec_SUPERSECRET', $config->clientSecret);
        self::assertSame('tok_SUPERSECRET', $config->accessToken);
    }

    /**
     * Build a serialize()-compatible FluteConfig payload from an arbitrary
     * key => value map, so tests can inject unknown keys and drop or retype
     * known ones (matches the wire format serialize() emits for __serialize).
     *
     * @param array<string, mixed> $data
     */
    private static function serializedConfig(array $data): string
    {
        $inner = serialize($data);
        $inner = substr($inner, (int) strpos($inner, '{'));

        return sprintf('O:%d:"%s":%d:%s', strlen(FluteConfig::class), FluteConfig::class, count($data), $inner);
    }

    public function testUnserializeIgnoresUnknownKeys(): void
    {
        // A hand-edited/legacy payload with an extra key must not create a
        // deprecated dynamic property; known keys still hydrate.
        $data = FluteConfig::fromArray($this->valid())->__serialize();
        $data['rogue'] = 'boom';

        $restored = unserialize(self::serializedConfig($data));

        self::assertInstanceOf(FluteConfig::class, $restored);
        self::assertFalse(property_exists($restored, 'rogue'));
        self::assertSame('cid-1', $restored->clientId);
        self::assertSame(Environment::SANDBOX, $restored->environment);
    }

    public function testUnserializeFailsClosedOnWrongTypedValues(): void
    {
        // Wrong-typed values fall back to fail-closed defaults instead of
        // throwing an uncaught TypeError on assignment.
        $data = FluteConfig::fromArray($this->valid())->__serialize();
        $data['httpTimeoutSeconds'] = 'ten';
        $data['environment'] = 'production';
        $data['clientSecret'] = 42;
        $data['httpClient'] = 'GuzzleHttp\Client';

        $restored = unserialize(self::serializedConfig($data));

        self::assertInstanceOf(FluteConfig::class, $restored);
        self::assertSame(30, $restored->httpTimeoutSeconds);
        // A non-enum environment falls back to sandbox, never production.
        self::assertSame(Environment::SANDBOX, $restored->environment);
        // A retyped secret restores masked, not the injected value.
        self::assertSame('***redacted***', $restored->clientSecret);
        // maskedView() reduces httpClient to a class-name string -> null.
        self::assertNull($restored->httpClient);
    }

    public function testUnserializeHydratesMissingKeysWithDefaults(): void
    {
        // Missing keys hydrate immediately instead of deferring an
        // uninitialized-property error to first access.
        $data = FluteConfig::fromArray($this->valid())->__serialize();
        unset($data['accessToken'], $data['tokenRefreshBufferSeconds']);

        $restored = unserialize(self::serializedConfig($data));

        self::assertInstanceOf(FluteConfig::class, $restored);
        self::assertNull($restored->accessToken);
        self::assertSame(60, $restored->tokenRefreshBufferSeconds);
    }

    public function testUnserializeRoundTripStaysMasked(): void
    {
        // A round-tripped config keeps the fail-closed masked view.
        $config = FluteConfig::fromArray($this->valid() + ['accessToken' => 'tok_SUPERSECRET']);

        $restored = unserialize(serialize($config));

        self::assertInstanceOf(FluteConfig::class, $restored);
        self::assertSame('***redacted***', $restored->clientSecret);
        self::assertSame('***redacted***', $restored->accessToken);
        self::assertSame($config->apiBaseUrl, $restored->apiBaseUrl);
        self::assertSame($config->oauthTokenUrl, $restored->oauthTokenUrl);
    }

    public function testGenericSerializationDoesNotLeakInjectedClientSecrets(): void
    {
        // A custom client can carry its own credentials (proxy auth, API keys) in
        // default headers; the config debug view must reduce it to a type name,
        // never recurse into its state.
        $client = new Client([
            'headers' => ['Authorization' => 'Basic PROXYSECRET', 'X-Api-Key' => 'APIKEYSECRET'],
        ]);
        $config = FluteConfig::fromArray($this->valid() + ['httpClient' => $client]);

        ob_start();
        var_dump($config);
        $varDump = (string) ob_get_clean();

        foreach (['var_dump' => $varDump, 'print_r' => print_r($config, true)] as $path => $output) {
            self::assertStringNotContainsString('PROXYSECRET', $output, "injected client secret leaked via {$path}");
            self::assertStringNotContainsString('APIKEYSECRET', $output, "injected client key leaked via {$path}");
        }
        // The non-sensitive descriptor (class name) is still shown for debugging.
        self::assertStringContainsString('GuzzleHttp\Client', $varDump);

        // Direct access still returns the real client for SDK use.
        self::assertSame($client, $config->httpClient);
    }
}

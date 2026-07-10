<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Security;

use Flute\Sdk\Auth\TokenManager;
use Flute\Sdk\FluteConfig;
use Flute\Sdk\Http\ApiClient;
use Flute\Sdk\Models\Requests\CreateMerchantApiKeyRequest;
use Flute\Sdk\Models\Responses\MerchantApiKey;
use Flute\Sdk\Resources\SessionsResource;
use PHPUnit\Framework\TestCase;

/**
 * Discovery companion to SensitiveParameterCoverageTest. The pinned list there
 * guards known frames but cannot see a future parameter named like a secret;
 * this sweep reflects over every class under src/ and requires
 * #[\SensitiveParameter] on every parameter whose normalized name matches the
 * sensitive-name families Redact already defines — unless a justified
 * exclusion entry says the value is not a secret. Like the pinned test, the
 * attribute is matched by name (no newInstance()), so the sweep passes on the
 * PHP 8.1 floor where \SensitiveParameter does not exist.
 */
final class SensitiveParameterSweepTest extends TestCase
{
    /**
     * Name families mirrored from src/Internal/Redact.php, whose consts are
     * private: CARD_KEYS + CREDENTIAL_KEYS (substring match) and
     * CARD_KEYS_EXACT + SENSITIVE_KEYS_EXACT (exact match). Keep in sync.
     *
     * @var list<string>
     */
    private const SUBSTRING_FAMILIES = [
        // Card data (Redact::CARD_KEYS).
        'accountnumber',
        'cardnumber',
        'securitycode',
        'cvv',
        'cvc',
        'cardcode',
        // Credentials (Redact::CREDENTIAL_KEYS).
        'clientsecret',
        'accesstoken',
        'authorization',
        'password',
        'secret',
        'token',
        'rawbody',
    ];

    /** @var list<string> */
    private const EXACT_FAMILIES = [
        // Redact::CARD_KEYS_EXACT.
        'pan',
        // Redact::SENSITIVE_KEYS_EXACT (prohibited SAD).
        'pin',
        'pinblock',
        'track1',
        'track2',
        'trackdata',
    ];

    /**
     * Matched-by-name parameters that are NOT secrets. Every entry must
     * justify why the value is safe in a captured stack frame; when in doubt
     * the parameter gets the attribute, not a row here.
     *
     * @var array<string, string>
     */
    private const NON_SECRET_EXCLUSIONS = [
        TokenManager::class . '::__construct $tokenUrl'
            => 'OAuth endpoint URL, not a credential; FluteConfig rejects userinfo-bearing URLs upstream',
        FluteConfig::class . '::__construct $oauthTokenUrl'
            => 'OAuth endpoint URL, not a credential; normalizeBaseUrl rejects embedded userinfo',
        FluteConfig::class . '::__construct $tokenRefreshBufferSeconds'
            => 'refresh-timing integer, not a token value',
        ApiClient::class . '::__construct $tokenManager'
            => 'service collaborator object; TokenManager masks its own secret state',
        SessionsResource::class . '::__construct $tokenManager'
            => 'service collaborator object; TokenManager masks its own secret state',
        CreateMerchantApiKeyRequest::class . '::__construct $tokenName'
            => 'human-readable key label, not the token value',
        MerchantApiKey::class . '::__construct $tokenName'
            => 'human-readable key label, not the token value',
    ];

    /**
     * Matched parameters that DO carry a secret and still lack the attribute —
     * sweep findings awaiting a src/ fix. Remove each entry when the attribute
     * lands; the staleness assertion forces that cleanup.
     *
     * @var array<string, string>
     */
    private const REPORTED_UNGUARDED = [];

    public function testEverySensitiveNamedParameterIsGuardedOrJustified(): void
    {
        $matched = 0;
        $unguarded = [];
        foreach (self::sdkClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue; // inherited frames are audited on the declaring class
                }
                foreach ($method->getParameters() as $parameter) {
                    if (!self::matchesSensitiveFamily($parameter->getName())) {
                        continue;
                    }
                    $matched++;
                    // Match by name (no newInstance()) so this holds on PHP 8.1.
                    if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                        continue;
                    }
                    $unguarded[] = sprintf('%s::%s $%s', $class, $method->getName(), $parameter->getName());
                }
            }
        }

        // Discovery sanity: the SDK has well over a dozen matching parameters;
        // near-zero means the sweep itself broke, not that the code is clean.
        self::assertGreaterThan(10, $matched, 'Sweep discovered implausibly few sensitive-named parameters.');

        $accounted = array_merge(array_keys(self::NON_SECRET_EXCLUSIONS), array_keys(self::REPORTED_UNGUARDED));
        sort($accounted);
        sort($unguarded);

        self::assertSame(
            [],
            array_values(array_diff($unguarded, $accounted)),
            'Sensitive-named parameter(s) lack #[\SensitiveParameter]; '
            . 'add the attribute or a justified exclusion entry.',
        );
        self::assertSame(
            [],
            array_values(array_diff($accounted, $unguarded)),
            'Stale exclusion entries (parameter removed, renamed, or now guarded); remove them.',
        );
    }

    private static function matchesSensitiveFamily(string $name): bool
    {
        // Same normalization as Redact::normalizeKey().
        $normalized = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
        if (in_array($normalized, self::EXACT_FAMILIES, true)) {
            return true;
        }
        foreach (self::SUBSTRING_FAMILIES as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every class-like type under src/, derived from the PSR-4 mapping so a
     * new file is swept automatically.
     *
     * @return list<class-string>
     */
    private static function sdkClasses(): array
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($srcDir) + 1);
            $class = 'Flute\\Sdk\\' . str_replace(DIRECTORY_SEPARATOR, '\\', substr($relative, 0, -4));
            if (
                class_exists($class) || interface_exists($class)
                || enum_exists($class) || trait_exists($class)
            ) {
                /** @var class-string $class ReflectionClass accepts trait names too */
                $classes[] = $class;
                continue;
            }
            self::fail(sprintf('src file %s does not autoload as %s.', $relative, $class));
        }
        sort($classes);

        return $classes;
    }
}

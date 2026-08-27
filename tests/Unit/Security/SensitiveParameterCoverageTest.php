<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Security;

use Flute\Sdk\Auth\TokenManager;
use Flute\Sdk\Flute;
use Flute\Sdk\FluteConfig;
use Flute\Sdk\Http\ApiClient;
use Flute\Sdk\Models\Requests\AbstractCardTransactionRequest;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;
use Flute\Sdk\Resources\TransactionsResource;
use Flute\Sdk\Resources\WebhooksResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins #[\SensitiveParameter] on every parameter that carries card data or a
 * credential into a frame that can be captured in Throwable::getTrace()['args'].
 * Without the attribute, PHP records those arguments verbatim
 * in exception traces whenever zend.exception_ignore_args is Off (the compiled
 * default), so a structured error tracker (Sentry/Bugsnag/Monolog) that
 * deep-serializes the exception writes PAN/CVV/secrets to durable logs.
 *
 * Reflection-based and matches the attribute by name, so it does not instantiate
 * the attribute class and therefore passes on the PHP 8.1 floor (where
 * \SensitiveParameter does not exist and the attribute is an inert no-op) as well
 * as on 8.2+ where it is active.
 */
final class SensitiveParameterCoverageTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function sensitiveParameters(): iterable
    {
        // S1 — card-bearing payload + the Guzzle exception holding the request body.
        yield 'ApiClient::post $json' => [ApiClient::class, 'post', 'json'];
        yield 'ApiClient::send $json' => [ApiClient::class, 'send', 'json'];
        yield 'ApiClient::sendWithAuthRetry $json' => [ApiClient::class, 'sendWithAuthRetry', 'json'];
        yield 'ApiClient::sendOnce $json' => [ApiClient::class, 'sendOnce', 'json'];
        yield 'ApiClient::mapApiException $e' => [ApiClient::class, 'mapApiException', 'e'];
        yield 'TransactionsResource::saleTransaction $request'
            => [TransactionsResource::class, 'saleTransaction', 'request'];
        yield 'TransactionsResource::authorizeTransaction $request'
            => [TransactionsResource::class, 'authorizeTransaction', 'request'];

        // The public card request constructor: a TypeError thrown
        // here (e.g. a bad-typed amount) would otherwise capture PAN/CVV in its
        // trace args, before the request reaches the annotated
        // ApiClient/TransactionsResource frames.
        yield 'AbstractCardTransactionRequest::__construct $accountNumber'
            => [AbstractCardTransactionRequest::class, '__construct', 'accountNumber'];
        yield 'AbstractCardTransactionRequest::__construct $securityCode'
            => [AbstractCardTransactionRequest::class, '__construct', 'securityCode'];

        // S2 — the raw config array carries clientSecret/accessToken. Both entry
        // points AND the private helpers that receive the whole array on their own
        // frame and throw (positiveInt/requireNonEmptyString) must be covered, or
        // the common bad-timeout / empty-string validation paths still leak.
        yield 'Flute::__construct $config' => [Flute::class, '__construct', 'config'];
        yield 'FluteConfig::fromArray $config' => [FluteConfig::class, 'fromArray', 'config'];
        yield 'FluteConfig::requireNonEmptyString $config' => [FluteConfig::class, 'requireNonEmptyString', 'config'];
        yield 'FluteConfig::positiveInt $config' => [FluteConfig::class, 'positiveInt', 'config'];
        // A base URL with embedded userinfo (user:pass@) is
        // rejected, but $value would otherwise sit in the throwing frame's args.
        yield 'FluteConfig::normalizeBaseUrl $value' => [FluteConfig::class, 'normalizeBaseUrl', 'value'];
        yield 'FluteConfig::__construct $clientSecret' => [FluteConfig::class, '__construct', 'clientSecret'];
        yield 'FluteConfig::__construct $accessToken' => [FluteConfig::class, '__construct', 'accessToken'];
        yield 'TokenManager::__construct $clientSecret' => [TokenManager::class, '__construct', 'clientSecret'];
        yield 'TokenManager::__construct $presuppliedToken' => [TokenManager::class, '__construct', 'presuppliedToken'];

        // S3 — webhook HMAC secret + raw body bound to the frame before the
        // empty-parameter guard throws.
        yield 'WebhooksResource::verifySignature $rawRequestBody'
            => [WebhooksResource::class, 'verifySignature', 'rawRequestBody'];
        yield 'WebhooksResource::verifySignature $signatureSecret'
            => [WebhooksResource::class, 'verifySignature', 'signatureSecret'];
        yield 'WebhooksResource::verify $rawRequestBody'
            => [WebhooksResource::class, 'verify', 'rawRequestBody'];
        yield 'WebhooksResource::verify $signatureSecret'
            => [WebhooksResource::class, 'verify', 'signatureSecret'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('sensitiveParameters')]
    public function testParameterIsMarkedSensitive(string $class, string $method, string $parameter): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        $target = null;
        foreach ($reflection->getParameters() as $param) {
            if ($param->getName() === $parameter) {
                $target = $param;
                break;
            }
        }

        self::assertNotNull($target, sprintf('%s::%s has no parameter $%s', $class, $method, $parameter));

        // Match by name (no newInstance()) so the assertion holds on PHP 8.1,
        // where \SensitiveParameter is absent but the attribute is still recorded.
        $attributes = $target->getAttributes(\SensitiveParameter::class);

        self::assertNotEmpty(
            $attributes,
            sprintf('%s::%s $%s must carry #[\\SensitiveParameter]', $class, $method, $parameter),
        );
    }

    /**
     * Behavior regression: a real constructor TypeError must not
     * carry PAN/CVV in its trace args even with arg capture forced on. This
     * proves runtime redaction, not just that the annotation exists. The
     * attribute is inert on PHP < 8.2, where the only mitigation is the
     * zend.exception_ignore_args ini, so the assertion is skipped there.
     */
    public function testConstructorTypeErrorDoesNotCaptureCardDataInTrace(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped(
                '#[\\SensitiveParameter] is inert on PHP < 8.2; on the 8.1 floor the only '
                . 'mitigation is zend.exception_ignore_args=On.',
            );
        }

        $pan = '4111111111111111';
        $cvv = '987';
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0'); // worst case: capture trace args

        try {
            // @phpstan-ignore-next-line — intentional bad amount type forces a TypeError
            new SaleTransactionRequest(amount: 'not-a-float', accountNumber: $pan, securityCode: $cvv);
            self::fail('Expected a TypeError from the bad amount type');
        } catch (\TypeError $e) {
            $args = (string) json_encode(array_map(
                static fn (array $frame): mixed => $frame['args'] ?? [],
                $e->getTrace(),
            ));
            self::assertStringNotContainsString($pan, $args, 'PAN leaked into constructor trace args');
            self::assertStringNotContainsString($cvv, $args, 'CVV leaked into constructor trace args');
        } finally {
            ini_set('zend.exception_ignore_args', $original === false ? '1' : $original);
        }
    }
}

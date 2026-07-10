<?php

declare(strict_types=1);

namespace Flute\Sdk;

use Flute\Sdk\Auth\TokenManager;
use Flute\Sdk\Http\ApiClient;
use Flute\Sdk\Resources\MerchantsResource;
use Flute\Sdk\Resources\PaymentSessionsResource;
use Flute\Sdk\Resources\SessionsResource;
use Flute\Sdk\Resources\SettingsResource;
use Flute\Sdk\Resources\TransactionsResource;
use Flute\Sdk\Resources\WebhooksResource;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * Flute SDK entry point.
 *
 * ```php
 * $flute = new Flute([
 *     'clientId' => '...',
 *     'clientSecret' => '...',
 *     'environment' => 'sandbox',
 * ]);
 * ```
 *
 * The constructor validates configuration and wires resources; it performs
 * no network I/O. Authentication happens lazily on the first API call.
 * Resources are exposed as public readonly properties, giving the
 * $flute->transactions->saleTransaction(...) module-access syntax.
 */
final class Flute
{
    public const VERSION = Version::VERSION;

    public readonly SessionsResource $sessions;

    public readonly TransactionsResource $transactions;

    public readonly PaymentSessionsResource $paymentSessions;

    public readonly SettingsResource $settings;

    public readonly MerchantsResource $merchants;

    public readonly WebhooksResource $webhooks;

    /**
     * @param array<string, mixed> $config See FluteConfig for accepted keys.
     *
     * @throws \InvalidArgumentException on invalid configuration
     */
    public function __construct(#[\SensitiveParameter] array $config)
    {
        $resolved = FluteConfig::fromArray($config);
        $httpClient = $resolved->httpClient ?? self::defaultHttpClient($resolved->httpTimeoutSeconds);

        $tokenManager = new TokenManager(
            httpClient: $httpClient,
            tokenUrl: $resolved->oauthTokenUrl,
            clientId: $resolved->clientId,
            clientSecret: $resolved->clientSecret,
            refreshBufferSeconds: $resolved->tokenRefreshBufferSeconds,
            timeoutSeconds: $resolved->httpTimeoutSeconds,
            presuppliedToken: $resolved->accessToken,
        );

        $this->sessions = new SessionsResource($tokenManager);

        $apiClient = new ApiClient(
            httpClient: $httpClient,
            tokenManager: $tokenManager,
            apiBaseUrl: $resolved->apiBaseUrl,
            timeoutSeconds: $resolved->httpTimeoutSeconds,
        );

        $this->transactions = new TransactionsResource($apiClient);
        $this->paymentSessions = new PaymentSessionsResource($apiClient);
        $this->settings = new SettingsResource($apiClient);
        $this->merchants = new MerchantsResource($apiClient);
        $this->webhooks = new WebhooksResource();
    }

    /** Returns the SDK version string; no API call is made. */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    private static function defaultHttpClient(int $timeoutSeconds): ClientInterface
    {
        return new Client([
            RequestOptions::TIMEOUT => $timeoutSeconds,
            RequestOptions::HTTP_ERRORS => true,
        ]);
    }
}

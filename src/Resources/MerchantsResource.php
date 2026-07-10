<?php

declare(strict_types=1);

namespace Flute\Sdk\Resources;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Http\ApiClient;
use Flute\Sdk\Models\Requests\CreateMerchantApiKeyRequest;
use Flute\Sdk\Models\Requests\ListMerchantsRequest;
use Flute\Sdk\Models\Responses\CreateMerchantApiKeyResponse;
use Flute\Sdk\Models\Responses\ListMerchantApiKeysResponse;
use Flute\Sdk\Models\Responses\ListMerchantsResponse;

/**
 * Partner operations: the merchants under a partner (ISV) account and their
 * API keys. Requires a partner credential — merchant-scoped credentials
 * cannot call these endpoints.
 *
 * API-key lifecycle:
 *  - listMerchants        — the merchants under this partner.
 *  - listMerchantApiKeys  — audit a merchant's keys (never returns secrets).
 *  - createMerchantApiKey — mint a credential; the clientSecret is returned
 *                           ONCE and is unrecoverable afterward.
 *  - revokeMerchantApiKey — permanently revoke a credential by clientId.
 *
 * To rotate safely: mint the replacement, deploy it, then revoke the old key —
 * never revoke first, or the merchant loses the ability to process meanwhile.
 */
final class MerchantsResource
{
    private const BASE = '/pay-api/v1/merchants';

    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Lists merchants under the partner account.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function listMerchants(?ListMerchantsRequest $request = null): ListMerchantsResponse
    {
        $data = $this->api->getJson(self::BASE, $request?->toQuery() ?? []);

        return ListMerchantsResponse::fromArray($data);
    }

    /**
     * Lists API keys, optionally scoped to one merchant. Listings never
     * include secrets — only the clientId, name, and creation date.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function listMerchantApiKeys(?string $merchantId = null): ListMerchantApiKeysResponse
    {
        $query = $merchantId !== null ? ['merchantId' => $merchantId] : [];
        $data = $this->api->getJson(self::BASE . '/tokens', $query);

        return ListMerchantApiKeysResponse::fromArray($data);
    }

    /**
     * Mints an API key for a merchant. The clientSecret in the response is
     * shown only once — it can never be retrieved again.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function createMerchantApiKey(CreateMerchantApiKeyRequest $request): CreateMerchantApiKeyResponse
    {
        $data = $this->api->postJson(self::BASE . '/tokens', $request->toArray());

        return CreateMerchantApiKeyResponse::fromArray($data);
    }

    /**
     * Permanently revokes an API key by its clientId. The API requires
     * $merchantId; both arguments are validated as non-empty so a request the
     * API is known to reject with HTTP 400 (V0000) is never sent.
     *
     * @throws \InvalidArgumentException when $clientId or $merchantId is empty
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function revokeMerchantApiKey(string $clientId, string $merchantId): void
    {
        if ($clientId === '') {
            throw new \InvalidArgumentException('clientId must not be empty.');
        }
        if ($merchantId === '') {
            throw new \InvalidArgumentException('merchantId must not be empty.');
        }

        $this->api->delete(
            self::BASE . '/tokens/' . rawurlencode($clientId),
            ['merchantId' => $merchantId],
        );
    }
}

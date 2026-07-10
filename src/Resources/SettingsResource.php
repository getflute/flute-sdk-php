<?php

declare(strict_types=1);

namespace Flute\Sdk\Resources;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Http\ApiClient;
use Flute\Sdk\Models\Responses\PaymentSettingsResponse;

/**
 * Merchant configuration.
 */
final class SettingsResource
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Retrieves the merchant's payment configuration.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function getPaymentSettings(): PaymentSettingsResponse
    {
        $data = $this->api->getJson('/pay-api/v1/configurations/payments');

        return PaymentSettingsResponse::fromArray($data);
    }
}

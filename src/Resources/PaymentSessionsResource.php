<?php

declare(strict_types=1);

namespace Flute\Sdk\Resources;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Http\ApiClient;
use Flute\Sdk\Models\Requests\CreatePaymentSessionRequest;
use Flute\Sdk\Models\Responses\CreatePaymentSessionResponse;
use Flute\Sdk\Models\Responses\PaymentSessionResponse;

/**
 * Payment session operations for Flute Checkout and Elements.
 */
final class PaymentSessionsResource
{
    private const BASE = '/pay-int-api/payment-sessions';
    private const API_VERSION_HEADER = ['x-api-version' => '1'];

    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Creates a new payment session.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function createPaymentSession(CreatePaymentSessionRequest $request): CreatePaymentSessionResponse
    {
        $data = $this->api->postJson(self::BASE, $request->toArray(), headers: self::API_VERSION_HEADER);

        return CreatePaymentSessionResponse::fromArray($data);
    }

    /**
     * Retrieves payment session details by ID.
     *
     * @throws \InvalidArgumentException when $paymentSessionId is empty
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function getPaymentSession(string $paymentSessionId): PaymentSessionResponse
    {
        if ($paymentSessionId === '') {
            throw new \InvalidArgumentException('paymentSessionId must not be empty.');
        }

        $data = $this->api->getJson(
            self::BASE . '/' . rawurlencode($paymentSessionId),
            headers: self::API_VERSION_HEADER,
        );

        return PaymentSessionResponse::fromArray($data);
    }

    /**
     * Cancels a payment session.
     *
     * @throws \InvalidArgumentException when $paymentSessionId is empty
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function cancelPaymentSession(string $paymentSessionId): void
    {
        if ($paymentSessionId === '') {
            throw new \InvalidArgumentException('paymentSessionId must not be empty.');
        }

        $this->api->post(
            self::BASE . '/' . rawurlencode($paymentSessionId) . '/cancel',
            headers: self::API_VERSION_HEADER,
        );
    }
}

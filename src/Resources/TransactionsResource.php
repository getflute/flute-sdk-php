<?php

declare(strict_types=1);

namespace Flute\Sdk\Resources;

use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Http\ApiClient;
use Flute\Sdk\Models\Requests\AuthorizeTransactionRequest;
use Flute\Sdk\Models\Requests\CalculateAmountRequest;
use Flute\Sdk\Models\Requests\CaptureTransactionRequest;
use Flute\Sdk\Models\Requests\ListTransactionsRequest;
use Flute\Sdk\Models\Requests\RefundTransactionRequest;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;
use Flute\Sdk\Models\Requests\VoidTransactionRequest;
use Flute\Sdk\Models\Responses\CalculateAmountResponse;
use Flute\Sdk\Models\Responses\ListTransactionsResponse;
use Flute\Sdk\Models\Responses\TransactionDetailsResponse;
use Flute\Sdk\Models\Responses\TransactionResponse;

/**
 * Card transaction operations.
 *
 * The transaction lifecycle:
 *  - sale       — authorize and capture in one step (the common path).
 *  - authorize  — reserve funds now; capture later.
 *  - capture    — settle a prior authorization.
 *  - void       — cancel an authorized/unsettled transaction (before settlement).
 *  - refund     — return funds on an already-settled transaction.
 *  - calculateAmount — preview amounts, including zero-cost pricing breakdowns.
 *
 * Every method serializes a typed request, sends it through the shared
 * ApiClient, and hydrates a typed response. All API/auth/network failures
 * surface as Flute\Sdk\Exceptions\* exceptions.
 */
final class TransactionsResource
{
    private const BASE = '/pay-api/v1/transactions';

    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Lists transactions with optional pagination filters.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function listTransactions(?ListTransactionsRequest $request = null): ListTransactionsResponse
    {
        $data = $this->api->getJson(self::BASE, $request?->toQuery() ?? []);

        return ListTransactionsResponse::fromArray($data);
    }

    /**
     * Retrieves a transaction by ID.
     *
     * @throws \InvalidArgumentException when $transactionId is empty
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function getTransaction(string $transactionId): TransactionDetailsResponse
    {
        if ($transactionId === '') {
            throw new \InvalidArgumentException('transactionId must not be empty.');
        }

        $data = $this->api->getJson(self::BASE . '/' . rawurlencode($transactionId));

        return TransactionDetailsResponse::fromArray($data);
    }

    /**
     * Authorizes a transaction for later capture.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function authorizeTransaction(
        #[\SensitiveParameter] AuthorizeTransactionRequest $request,
    ): TransactionResponse {
        $data = $this->api->postJson(self::BASE . '/auth', $request->toArray());

        return TransactionResponse::fromArray($data);
    }

    /**
     * Executes a sale (authorization + capture).
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function saleTransaction(
        #[\SensitiveParameter] SaleTransactionRequest $request,
    ): TransactionResponse {
        $data = $this->api->postJson(self::BASE . '/sale', $request->toArray());

        return TransactionResponse::fromArray($data);
    }

    /**
     * Voids a transaction before settlement.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function voidTransaction(VoidTransactionRequest $request): TransactionResponse
    {
        $data = $this->api->postJson(self::BASE . '/void', $request->toArray());

        return TransactionResponse::fromArray($data);
    }

    /**
     * Captures a previously authorized transaction.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function captureTransaction(CaptureTransactionRequest $request): TransactionResponse
    {
        $data = $this->api->postJson(self::BASE . '/capture', $request->toArray());

        return TransactionResponse::fromArray($data);
    }

    /**
     * Refunds (returns) a settled transaction.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function refundTransaction(RefundTransactionRequest $request): TransactionResponse
    {
        $data = $this->api->postJson(self::BASE . '/return', $request->toArray());

        return TransactionResponse::fromArray($data);
    }

    /**
     * Calculates transaction amounts, including zero-cost pricing breakdowns.
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function calculateAmount(CalculateAmountRequest $request): CalculateAmountResponse
    {
        $data = $this->api->getJson(self::BASE . '/calculate-amount', $request->toQuery());

        return CalculateAmountResponse::fromArray($data);
    }
}

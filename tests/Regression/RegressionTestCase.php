<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

use Flute\Sdk\Enums\Environment;
use Flute\Sdk\Flute;
use Flute\Sdk\Models\Requests\AuthorizeTransactionRequest;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;
use Flute\Sdk\Tests\Support\LiveTestCase;
use Flute\Sdk\Tests\Support\SandboxFixtures;
use GuzzleHttp\Client;

/**
 * Base for the 25-scenario Flute-facing regression harness.
 *
 * Real sandbox only — no mocks. Credentials come from the environment
 * (FLUTE_CLIENT_ID / FLUTE_CLIENT_SECRET, merchant-scoped); every case skips
 * with an explicit reason when its prerequisites are missing and builds its
 * own Flute instance, so each scenario is independently runnable.
 */
abstract class RegressionTestCase extends LiveTestCase
{
    protected const REFERENCE_PREFIX = 'rg-';

    protected static function approvedCard(float $amount): SaleTransactionRequest
    {
        return SandboxFixtures::approvedCardSale($amount, self::REFERENCE_PREFIX);
    }

    protected static function approvedAuthorize(float $amount): AuthorizeTransactionRequest
    {
        return SandboxFixtures::approvedCardAuthorize($amount, self::REFERENCE_PREFIX);
    }

    protected static function declineCard(float $amount): SaleTransactionRequest
    {
        return SandboxFixtures::declineCardSale($amount, self::REFERENCE_PREFIX);
    }

    /**
     * Settles the merchant's open card batch so captured transactions become
     * refundable. The settle endpoint is outside the SDK surface, so
     * the call goes through Guzzle directly.
     *
     * Wire shape (verified live 2026-06-11): the request body is
     * {paymentProcessorId} — there is NO per-transaction settle. The endpoint
     * settles the processor's whole open batch and answers
     * {"code":0,"message":"Batch settled successfully","processorResponseCode":"00"}.
     * The parent transaction's own status remains Captured afterwards.
     */
    protected function settleCardBatch(Flute $flute): void
    {
        $token = $flute->sessions->getAccessToken();
        $baseUrl = self::env('FLUTE_API_BASE_URL') ?? Environment::SANDBOX->apiBaseUrl();

        $response = (new Client(['http_errors' => false]))->post(
            $baseUrl . '/pay-api/v1/transactions/settle',
            [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'json' => ['paymentProcessorId' => $this->defaultCardProcessorId($flute)],
                'timeout' => 30,
            ],
        );

        $rawBody = (string) $response->getBody();

        self::assertSame(
            200,
            $response->getStatusCode(),
            'Batch settle HTTP error: ' . $rawBody,
        );

        /** @var array<string, mixed>|null $body */
        $body = json_decode($rawBody, true);
        self::assertIsArray($body, 'Batch settle response is not valid JSON: ' . $rawBody);
        self::assertSame(
            0,
            $body['code'] ?? null,
            'Batch settle envelope code !== 0: ' . $rawBody,
        );
    }

    /** Default SandboxCard processor id, read from the merchant payment settings. */
    private function defaultCardProcessorId(Flute $flute): string
    {
        $settings = $flute->settings->getPaymentSettings()->toArray();
        $processors = $settings['availablePaymentProcessors'] ?? null;
        if (is_array($processors)) {
            foreach ($processors as $processor) {
                if (!is_array($processor) || ($processor['type'] ?? null) !== 'SandboxCard') {
                    continue;
                }
                $id = $processor['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }
        }

        self::fail('No SandboxCard processor in payment settings; cannot settle the card batch.');
    }
}

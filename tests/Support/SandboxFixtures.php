<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Support;

use Flute\Sdk\Models\Requests\Address;
use Flute\Sdk\Models\Requests\AuthorizeTransactionRequest;
use Flute\Sdk\Models\Requests\SaleTransactionRequest;

/**
 * Canonical sandbox request builders shared by the live suites.
 *
 * Approval path: any unlisted PAN + AVS full-match billing address (the
 * merchant's "Moderate" AVS profile denies card transactions without one).
 * Every money request carries a unique referenceId so re-runs clear the
 * sandbox duplicate controls (~10-minute window).
 */
final class SandboxFixtures
{
    private const APPROVED_PAN = '4111111111111111';
    private const DECLINE_PAN = '4000000010050005'; // "Do not honor"

    /** @param array<string, mixed> $extra Extra constructor arguments; colliding keys override the defaults */
    public static function approvedCardSale(
        float $amount,
        string $referencePrefix,
        array $extra = [],
    ): SaleTransactionRequest {
        return self::cardSale(self::APPROVED_PAN, $amount, $referencePrefix, $extra);
    }

    public static function declineCardSale(float $amount, string $referencePrefix): SaleTransactionRequest
    {
        return self::cardSale(self::DECLINE_PAN, $amount, $referencePrefix);
    }

    /**
     * @param array<string, mixed> $extra Extra constructor arguments (e.g. useCardPrice: false).
     *                                    Keys that collide with the defaults override them.
     */
    public static function cardSale(
        string $pan,
        float $amount,
        string $referencePrefix,
        array $extra = [],
    ): SaleTransactionRequest {
        return new SaleTransactionRequest(...$extra + self::cardArguments($pan, $amount, $referencePrefix));
    }

    public static function approvedCardAuthorize(float $amount, string $referencePrefix): AuthorizeTransactionRequest
    {
        return new AuthorizeTransactionRequest(
            ...self::cardArguments(self::APPROVED_PAN, $amount, $referencePrefix),
        );
    }

    /** Street 123 Test St + postal 10001 = sandbox AVS full match. */
    public static function avsFullMatchAddress(): Address
    {
        return new Address(line1: '123 Test St', postalCode: '10001');
    }

    public static function uniqueReferenceId(string $prefix): string
    {
        return $prefix . uniqid('', true);
    }

    /** @return array<string, mixed> */
    private static function cardArguments(string $pan, float $amount, string $referencePrefix): array
    {
        return [
            'amount' => $amount,
            'accountNumber' => $pan,
            'currencyId' => 1,
            'expirationMonth' => 12,
            'expirationYear' => 2030,
            'securityCode' => '123',
            'billingAddress' => self::avsFullMatchAddress(),
            'referenceId' => self::uniqueReferenceId($referencePrefix),
            /*
             * The sandbox merchant runs zero-cost DualPricing (observed
             * 2026-07-03): money requests must state whether the amount is the
             * card price or the server rejects them 400 V0000. true charges the
             * amount as-is; false adds the card-price uplift.
             */
            'useCardPrice' => true,
        ];
    }
}

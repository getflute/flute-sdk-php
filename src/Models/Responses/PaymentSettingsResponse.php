<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * Merchant payment configuration. zeroCostProcessingOptionId:
 * 1 = None, 2 = CashDiscount, 3 = DualPricing, 4 = Surcharge.
 */
final class PaymentSettingsResponse
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?int $zeroCostProcessingOptionId,
        public readonly ?string $zeroCostProcessingOption,
        public readonly ?bool $isTipsEnabled,
        public readonly ?float $defaultSurchargeRate,
        public readonly ?float $defaultCashDiscountRate,
        public readonly ?float $defaultDualPricingRate,
        public readonly ?string $companyName,
        public readonly ?int $currencyId,
        public readonly ?string $currencyIsoCode,
        public readonly ?float $maxTransactionAmount,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            zeroCostProcessingOptionId: Data::int($data, 'zeroCostProcessingOptionId'),
            zeroCostProcessingOption: Data::str($data, 'zeroCostProcessingOption'),
            isTipsEnabled: Data::bool($data, 'isTipsEnabled'),
            defaultSurchargeRate: Data::float($data, 'defaultSurchargeRate'),
            defaultCashDiscountRate: Data::float($data, 'defaultCashDiscountRate'),
            defaultDualPricingRate: Data::float($data, 'defaultDualPricingRate'),
            companyName: Data::str($data, 'companyName'),
            currencyId: Data::int($data, 'currencyId'),
            currencyIsoCode: Data::str($data, 'currencyIsoCode'),
            maxTransactionAmount: Data::float($data, 'maxTransactionAmount'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

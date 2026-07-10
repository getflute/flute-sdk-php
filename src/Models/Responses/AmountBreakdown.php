<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * Amount breakdown including zero-cost pricing components.
 *
 * `cashDiscountRate` and `cashDiscountPercentage` are aliases in the API
 * (AmountDto carries both with identical meaning). Each falls back to the other
 * here so callers can read either regardless of which the payload populates.
 */
final class AmountBreakdown
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?float $baseAmount,
        public readonly ?float $percentageOffAmount,
        public readonly ?float $percentageOffRate,
        public readonly ?float $cashDiscountAmount,
        public readonly ?float $cashDiscountRate,
        public readonly ?float $cashDiscountPercentage,
        public readonly ?float $surchargeAmount,
        public readonly ?float $surchargeRate,
        public readonly ?float $tipAmount,
        public readonly ?float $tipRate,
        public readonly ?float $taxAmount,
        public readonly ?float $taxRate,
        public readonly ?float $totalAmount,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $cashDiscountRate = Data::float($data, 'cashDiscountRate');
        $cashDiscountPercentage = Data::float($data, 'cashDiscountPercentage');

        return new self(
            baseAmount: Data::float($data, 'baseAmount'),
            percentageOffAmount: Data::float($data, 'percentageOffAmount'),
            percentageOffRate: Data::float($data, 'percentageOffRate'),
            cashDiscountAmount: Data::float($data, 'cashDiscountAmount'),
            cashDiscountRate: $cashDiscountRate ?? $cashDiscountPercentage,
            cashDiscountPercentage: $cashDiscountPercentage ?? $cashDiscountRate,
            surchargeAmount: Data::float($data, 'surchargeAmount'),
            surchargeRate: Data::float($data, 'surchargeRate'),
            tipAmount: Data::float($data, 'tipAmount'),
            tipRate: Data::float($data, 'tipRate'),
            taxAmount: Data::float($data, 'taxAmount'),
            taxRate: Data::float($data, 'taxRate'),
            totalAmount: Data::float($data, 'totalAmount'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

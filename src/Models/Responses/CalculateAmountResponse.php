<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * Calculated amounts per payment method, including zero-cost pricing.
 */
final class CalculateAmountResponse
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?string $currency,
        public readonly ?int $currencyId,
        public readonly ?string $zeroCostProcessingOption,
        public readonly ?int $zeroCostProcessingOptionId,
        public readonly ?bool $useCardPrice,
        public readonly ?AmountBreakdown $cash,
        public readonly ?AmountBreakdown $creditCard,
        public readonly ?AmountBreakdown $debitCard,
        public readonly ?AmountBreakdown $ach,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $breakdown = static function (string $key) use ($data): ?AmountBreakdown {
            $value = Data::arr($data, $key);

            return $value !== null ? AmountBreakdown::fromArray($value) : null;
        };

        return new self(
            currency: Data::str($data, 'currency'),
            currencyId: Data::int($data, 'currencyId'),
            zeroCostProcessingOption: Data::str($data, 'zeroCostProcessingOption'),
            zeroCostProcessingOptionId: Data::int($data, 'zeroCostProcessingOptionId'),
            useCardPrice: Data::bool($data, 'useCardPrice'),
            cash: $breakdown('cash'),
            creditCard: $breakdown('creditCard'),
            debitCard: $breakdown('debitCard'),
            ach: $breakdown('ach'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

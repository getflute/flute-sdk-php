<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Inputs for transaction amount calculation (GET query parameters).
 */
final class CalculateAmountRequest
{
    public function __construct(
        public readonly ?float $amount = null,
        public readonly ?float $percentageOffRate = null,
        public readonly ?float $surchargeRate = null,
        public readonly ?float $tipAmount = null,
        public readonly ?float $tipRate = null,
        public readonly ?int $currencyId = null,
        public readonly ?bool $useCardPrice = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toQuery(): array
    {
        return Data::filterNull([
            'amount' => $this->amount,
            'percentageOffRate' => $this->percentageOffRate,
            'surchargeRate' => $this->surchargeRate,
            'tipAmount' => $this->tipAmount,
            'tipRate' => $this->tipRate,
            'currencyId' => $this->currencyId,
            /*
             * Explicit strings: the query builder renders PHP bools as 1/0
             * (mirrors ListMerchantsRequest::toQuery()).
             */
            'useCardPrice' => $this->useCardPrice === null ? null : ($this->useCardPrice ? 'true' : 'false'),
        ]);
    }
}

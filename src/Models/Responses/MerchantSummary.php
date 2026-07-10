<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * One merchant row from the partner merchant list.
 */
final class MerchantSummary
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?string $merchantId,
        public readonly ?string $companyName,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            merchantId: Data::str($data, 'merchantId'),
            companyName: Data::str($data, 'companyName'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

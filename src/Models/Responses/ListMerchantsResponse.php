<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * Paginated merchant list for the partner account.
 */
final class ListMerchantsResponse
{
    /**
     * @param list<MerchantSummary> $items
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public readonly array $items,
        public readonly ?int $total,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = Data::mapList($data, 'items', MerchantSummary::fromArray(...));

        return new self(items: $items, total: Data::int($data, 'total'), raw: $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

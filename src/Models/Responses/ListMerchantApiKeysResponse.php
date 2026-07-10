<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * Merchant API keys visible to the partner credential.
 */
final class ListMerchantApiKeysResponse
{
    /**
     * @param list<MerchantApiKey> $keys
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public readonly array $keys,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        // SDK vocabulary is "keys"; the wire names this array "tokens".
        $keys = Data::mapList($data, 'tokens', MerchantApiKey::fromArray(...));

        return new self(keys: $keys, raw: $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

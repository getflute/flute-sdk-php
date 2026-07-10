<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * One merchant API key. Secrets are never part of key listings — only the
 * clientId, descriptive name, and creation date.
 */
final class MerchantApiKey
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?string $merchantId,
        public readonly ?string $tokenName,
        public readonly ?string $clientId,
        public readonly ?string $creationDate,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            merchantId: Data::str($data, 'merchantId'),
            tokenName: Data::str($data, 'tokenName'),
            clientId: Data::str($data, 'clientId'),
            creationDate: Data::str($data, 'creationDate'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

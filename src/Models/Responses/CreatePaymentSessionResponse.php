<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * Created payment session, including the hosted checkout URLs.
 */
final class CreatePaymentSessionResponse
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?string $id,
        public readonly ?string $checkoutUrl,
        public readonly ?string $checkoutUrlShort,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Data::str($data, 'id'),
            checkoutUrl: Data::str($data, 'checkoutUrl'),
            checkoutUrlShort: Data::str($data, 'checkoutUrlShort'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

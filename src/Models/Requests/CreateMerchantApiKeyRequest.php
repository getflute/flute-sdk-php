<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

/**
 * Payload for minting a merchant API key.
 */
final class CreateMerchantApiKeyRequest
{
    public function __construct(
        public readonly string $merchantId,
        public readonly string $tokenName,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'merchantId' => $this->merchantId,
            'tokenName' => $this->tokenName,
        ];
    }
}

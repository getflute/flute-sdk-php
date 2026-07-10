<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Voids a transaction before settlement. Wire-required: transactionId.
 */
final class VoidTransactionRequest
{
    public function __construct(public readonly ?string $transactionId = null)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Data::filterNull(['transactionId' => $this->transactionId]);
    }
}

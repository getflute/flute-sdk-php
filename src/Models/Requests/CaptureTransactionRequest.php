<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Captures a previously authorized transaction. Wire-required: transactionId, amount.
 */
final class CaptureTransactionRequest
{
    public function __construct(
        public readonly ?string $transactionId = null,
        public readonly ?float $amount = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Data::filterNull([
            'amount' => $this->amount,
            'transactionId' => $this->transactionId,
        ]);
    }
}

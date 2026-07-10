<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Refunds (returns) a settled transaction. Wire-required: transactionId,
 * cardDataSource. Omit amount for a full refund.
 */
final class RefundTransactionRequest
{
    public function __construct(
        public readonly ?string $transactionId = null,
        public readonly ?float $amount = null,
        public readonly int $cardDataSource = 1,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = Data::filterNull([
            'transactionId' => $this->transactionId,
            'amount' => $this->amount,
        ]);

        $payload['cardDataSource'] = $this->cardDataSource;

        return $payload;
    }
}

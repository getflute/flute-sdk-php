<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * Result of a transaction operation (sale, authorize, capture, void, refund).
 */
final class TransactionResponse
{
    use ParsesTransactionDateTime;

    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?string $transactionId,
        public readonly ?string $transactionDateTime,
        public readonly ?int $typeId,
        public readonly ?string $type,
        public readonly ?int $statusId,
        public readonly ?string $status,
        public readonly ?float $processedAmount,
        public readonly ?TransactionOutcomeDetails $details,
        public readonly ?AmountBreakdown $receiptAmount,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $details = Data::arr($data, 'details');
        $receipt = Data::arr($data, 'transactionReceipt');
        $receiptAmount = $receipt !== null ? Data::arr($receipt, 'amount') : null;

        return new self(
            transactionId: Data::str($data, 'transactionId'),
            transactionDateTime: Data::str($data, 'transactionDateTime'),
            typeId: Data::int($data, 'typeId'),
            type: Data::str($data, 'type'),
            statusId: Data::int($data, 'statusId'),
            status: Data::str($data, 'status'),
            processedAmount: Data::float($data, 'processedAmount'),
            details: $details !== null ? TransactionOutcomeDetails::fromArray($details) : null,
            receiptAmount: $receiptAmount !== null ? AmountBreakdown::fromArray($receiptAmount) : null,
            raw: $data,
        );
    }

    /**
     * Full decoded response payload (forward-compatibility escape hatch).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}

<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;

/**
 * Payment session details. statusId: 1 = Created, 2 = Cancelled, 3 = Completed, 4 = Failed.
 */
final class PaymentSessionResponse
{
    /**
     * @param array<string, mixed>|null $transactionDetails
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public readonly ?int $statusId,
        public readonly ?string $status,
        public readonly ?string $customerId,
        public readonly ?int $mode,
        public readonly ?bool $skipAddressVerification,
        public readonly ?string $referenceId,
        public readonly ?string $vaultedPaymentMethodId,
        public readonly ?array $transactionDetails,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            statusId: Data::int($data, 'statusId'),
            status: Data::str($data, 'status'),
            customerId: Data::str($data, 'customerId'),
            mode: Data::int($data, 'mode'),
            skipAddressVerification: Data::bool($data, 'skipAddressVerification'),
            referenceId: Data::str($data, 'referenceId'),
            vaultedPaymentMethodId: Data::str($data, 'vaultedPaymentMethodId'),
            transactionDetails: Data::arr($data, 'transactionDetails'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}

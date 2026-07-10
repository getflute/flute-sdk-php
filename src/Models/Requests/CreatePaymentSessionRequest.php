<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Creates a payment session for Flute Checkout / Elements.
 *
 * Wire-required: amount. mode: 1 = Payment (default), 2 = SaveMethod, 3 = PaymentAndSave.
 */
final class CreatePaymentSessionRequest
{
    public function __construct(
        public readonly ?float $amount = null,
        public readonly ?string $customerId = null,
        public readonly ?int $mode = null,
        public readonly ?bool $skipAddressVerification = null,
        public readonly ?string $referenceId = null,
        public readonly ?float $tipAmount = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Data::filterNull([
            'amount' => $this->amount,
            'customerId' => $this->customerId,
            'mode' => $this->mode,
            'skipAddressVerification' => $this->skipAddressVerification,
            'referenceId' => $this->referenceId,
            'tipAmount' => $this->tipAmount,
        ]);
    }
}

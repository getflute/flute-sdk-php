<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Enums\PaymentMethodType;
use Flute\Sdk\Internal\Data;

/**
 * Creates a payment session for Flute Checkout / Elements.
 *
 * Wire-required: amount. mode: 1 = Payment (default), 2 = SaveMethod, 3 = PaymentAndSave.
 * expiresAt is an ISO 8601 timestamp string. paymentMethodTypes entries may be
 * PaymentMethodType cases or plain strings; strings are sent unchanged so a
 * method type Flute introduces later needs no SDK release. metadata is a
 * string-to-string map on the wire; PHP holds a numeric-string key such as
 * "1042" as an int, which still encodes as {"1042": ...}. A value that is not
 * a string, or an empty array (which encodes as JSON `[]`, not `{}`), is
 * rejected by the API with a 400.
 */
final class CreatePaymentSessionRequest
{
    /**
     * @param list<PaymentMethodType|string>|null $paymentMethodTypes
     * @param array<array-key, string>|null $metadata
     */
    public function __construct(
        public readonly ?float $amount = null,
        public readonly ?string $customerId = null,
        public readonly ?int $mode = null,
        public readonly ?bool $skipAddressVerification = null,
        public readonly ?string $referenceId = null,
        public readonly ?float $tipAmount = null,
        public readonly ?string $returnUrl = null,
        public readonly ?array $paymentMethodTypes = null,
        public readonly ?array $metadata = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $pageName = null,
        public readonly ?string $paymentNotes = null,
        public readonly ?string $afterCompletionMessage = null,
        public readonly ?bool $isMultiUse = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $paymentMethodTypes = $this->paymentMethodTypes === null ? null : array_map(
            static fn (PaymentMethodType|string $type): string => $type instanceof PaymentMethodType
                ? $type->value
                : $type,
            $this->paymentMethodTypes,
        );

        return Data::filterNull([
            'amount' => $this->amount,
            'customerId' => $this->customerId,
            'mode' => $this->mode,
            'skipAddressVerification' => $this->skipAddressVerification,
            'referenceId' => $this->referenceId,
            'tipAmount' => $this->tipAmount,
            'returnUrl' => $this->returnUrl,
            'paymentMethodTypes' => $paymentMethodTypes,
            'metadata' => $this->metadata,
            'expiresAt' => $this->expiresAt,
            'pageName' => $this->pageName,
            'paymentNotes' => $this->paymentNotes,
            'afterCompletionMessage' => $this->afterCompletionMessage,
            'isMultiUse' => $this->isMultiUse,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;
use Flute\Sdk\Internal\Redact;

/**
 * Payment session details. statusId: 1 = Created, 2 = Cancelled, 3 = Completed, 4 = Failed.
 *
 * The typed properties cover the fields a Checkout integration reads back after
 * create; toArray() returns the complete raw payload for anything else. metadata
 * is a string-to-string map on the wire. paymentMethodTypes is a list of
 * lowercase method names as delivered ("card", "ach"). checkoutUrl is delivered
 * on the create response (see CreatePaymentSessionResponse); the get body may not
 * carry it, in which case it reads null here. achAccountLast2 and achRoutingLast2
 * are two-digit display fragments, null until an ACH payment method has been used.
 */
final class PaymentSessionResponse
{
    /**
     * @param array<string, mixed>|null $transactionDetails
     * @param array<array-key, string>|null $metadata
     * @param list<string>|null $paymentMethodTypes
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
        public readonly ?string $returnUrl,
        public readonly ?array $metadata,
        public readonly ?string $expiresAt,
        public readonly ?string $pageName,
        public readonly ?array $paymentMethodTypes,
        public readonly ?string $checkoutUrl,
        public readonly ?float $surchargeAmount,
        public readonly ?string $achAccountLast2,
        public readonly ?string $achRoutingLast2,
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
            returnUrl: Data::str($data, 'returnUrl'),
            metadata: Data::strMap($data, 'metadata'),
            expiresAt: Data::str($data, 'expiresAt'),
            pageName: Data::str($data, 'pageName'),
            paymentMethodTypes: Data::strList($data, 'paymentMethodTypes'),
            checkoutUrl: Data::str($data, 'checkoutUrl'),
            surchargeAmount: Data::float($data, 'surchargeAmount'),
            achAccountLast2: Data::str($data, 'achAccountLast2'),
            achRoutingLast2: Data::str($data, 'achRoutingLast2'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * Fail closed for debug output — var_dump()/print_r()/VarDumper (__debugInfo).
     * The typed view and the retained raw get the same key-aware scrub, so a
     * credential-named metadata key, a signed returnUrl, a nested customerPan
     * in transactionDetails, or an opaque identifier is masked identically in
     * both. Two-digit ACH fragments stay readable; a fuller account or routing
     * echo is masked — the explicit override on the two ACH keys exists because
     * a routing number is shorter than the card-length digit runs
     * Redact::payload() masks on its own. toArray() remains the explicit raw path.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        /** @var array<string, mixed> $view */
        $view = get_object_vars($this);
        unset($view['raw']);
        $view = Redact::payload($view);
        $raw = Redact::payload($this->raw);
        foreach (['achAccountLast2', 'achRoutingLast2'] as $key) {
            if ($this->{$key} !== null) {
                $view[$key] = Redact::sensitive($this->{$key});
            }
            $value = $raw[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $raw[$key] = Redact::sensitive((string) $value);
            }
        }
        $view['raw'] = $raw;

        return $view;
    }
}

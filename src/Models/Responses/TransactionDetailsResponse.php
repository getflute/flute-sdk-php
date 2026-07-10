<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;
use Flute\Sdk\Internal\Redact;

/**
 * Full transaction record from GET /pay-api/v1/transactions/{id} and list items.
 *
 * Field shapes differ by endpoint. List rows key the identifier as `id` and the
 * timestamp as `date`; get-by-id uses `transactionId`/`transactionDateTime`.
 * Both are fallback-mapped so `transactionId`/`transactionDateTime` are reliable
 * for either shape (raw payload is preserved via toArray()). Amount likewise
 * differs: list items carry top-level baseAmount/totalAmount, get-by-id nests
 * them under `amount`; baseAmount/totalAmount fall back to the nested object so
 * both shapes hydrate, and `amount` exposes the full breakdown when present.
 */
final class TransactionDetailsResponse
{
    use ParsesTransactionDateTime;

    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?string $transactionId,
        public readonly ?string $transactionDateTime,
        public readonly ?int $statusId,
        public readonly ?string $status,
        public readonly ?string $transactionType,
        public readonly ?string $operationType,
        public readonly ?string $paymentMethodType,
        public readonly ?float $baseAmount,
        public readonly ?float $totalAmount,
        public readonly ?string $currency,
        public readonly ?string $customerId,
        public readonly ?string $customerPan,
        public readonly ?string $authCode,
        public readonly ?string $responseCode,
        public readonly ?string $responseDescription,
        public readonly ?string $orderNumber,
        public readonly ?AmountBreakdown $amount,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $nested = Data::arr($data, 'amount');
        $amount = $nested !== null ? AmountBreakdown::fromArray($nested) : null;

        return new self(
            transactionId: Data::str($data, 'transactionId') ?? Data::str($data, 'id'),
            transactionDateTime: Data::str($data, 'transactionDateTime') ?? Data::str($data, 'date'),
            statusId: Data::int($data, 'statusId'),
            status: Data::str($data, 'status'),
            transactionType: Data::str($data, 'transactionType'),
            operationType: Data::str($data, 'operationType'),
            paymentMethodType: Data::str($data, 'paymentMethodType'),
            baseAmount: Data::float($data, 'baseAmount') ?? $amount?->baseAmount,
            totalAmount: Data::float($data, 'totalAmount') ?? $amount?->totalAmount,
            currency: Data::str($data, 'currency') ?? Data::str($data, 'currencyCode'),
            customerId: Data::str($data, 'customerId'),
            customerPan: Data::str($data, 'customerPan'),
            authCode: Data::str($data, 'authCode'),
            responseCode: Data::str($data, 'responseCode'),
            responseDescription: Data::str($data, 'responseDescription'),
            orderNumber: Data::str($data, 'orderNumber'),
            amount: $amount,
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
     * Flute returns customerPan already masked, but the SDK hedges against a
     * server echoing a fuller value (the same theory behind scrubbing gateway
     * error text): the typed property gets the card-style scrub and the retained
     * raw payload is scrubbed key-aware (Redact::payload), matching
     * CreateMerchantApiKeyResponse. toArray() remains the explicit raw path.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        /** @var array<string, mixed> $view */
        $view = get_object_vars($this);
        if ($this->customerPan !== null) {
            $view['customerPan'] = Redact::sensitive($this->customerPan);
        }
        $view['raw'] = Redact::payload($this->raw);

        return $view;
    }
}

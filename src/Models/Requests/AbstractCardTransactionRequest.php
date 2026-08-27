<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Shared shape for card-present-on-file transaction requests (auth and sale).
 *
 * Auth and sale post the identical wire contract; this base carries the single
 * constructor + toArray() so the serialization can never drift between them. The
 * two concrete types stay distinct for caller/IDE intent and future
 * endpoint-specific fields.
 *
 * @internal construct AuthorizeTransactionRequest or SaleTransactionRequest, not this base
 */
abstract class AbstractCardTransactionRequest implements \JsonSerializable
{
    /** CVV is sensitive authentication data: never displayed, even masked. */
    private const CVV_PLACEHOLDER = '***';

    /**
     * accountNumber/securityCode are marked #[\SensitiveParameter] so a constructor
     * TypeError (e.g. a bad-typed amount) cannot capture PAN/CVV in its stack-trace
     * args on PHP 8.2+. On the PHP 8.1 floor the attribute is inert (recorded but
     * not enforced by the engine), so a thrown exception can still carry the real
     * arguments into any error tracker that serializes trace args. The only 8.1
     * mitigation is zend.exception_ignore_args=On in production.
     *
     * @param int $cardDataSource 1 = Internet (server-side API default)
     */
    public function __construct(
        public readonly ?float $amount = null,
        #[\SensitiveParameter] public readonly ?string $accountNumber = null,
        public readonly ?int $currencyId = null,
        public readonly ?int $expirationMonth = null,
        public readonly ?int $expirationYear = null,
        public readonly int $cardDataSource = 1,
        #[\SensitiveParameter] public readonly ?string $securityCode = null,
        public readonly ?string $customerId = null,
        public readonly ?string $paymentMethodId = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $paymentProcessorId = null,
        public readonly ?float $tipAmount = null,
        public readonly ?float $tipRate = null,
        public readonly ?float $percentageOffRate = null,
        public readonly ?float $surchargeRate = null,
        public readonly ?bool $useCardPrice = null,
        public readonly ?Address $billingAddress = null,
        public readonly ?Address $shippingAddress = null,
        public readonly ?ContactInfo $contactInfo = null,
        public readonly ?string $referenceId = null,
        public readonly ?bool $customerInitiatedTransaction = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = Data::filterNull([
            'amount' => $this->amount,
            'accountNumber' => $this->accountNumber,
            'currencyId' => $this->currencyId,
            'expirationMonth' => $this->expirationMonth,
            'expirationYear' => $this->expirationYear,
            'securityCode' => $this->securityCode,
            'customerId' => $this->customerId,
            'paymentMethodId' => $this->paymentMethodId,
            'deviceId' => $this->deviceId,
            'paymentProcessorId' => $this->paymentProcessorId,
            'tipAmount' => $this->tipAmount,
            'tipRate' => $this->tipRate,
            'percentageOffRate' => $this->percentageOffRate,
            'surchargeRate' => $this->surchargeRate,
            'useCardPrice' => $this->useCardPrice,
            'billingAddress' => $this->billingAddress?->toArray(),
            'shippingAddress' => $this->shippingAddress?->toArray(),
            'contactInfo' => $this->contactInfo?->toArray(),
            'referenceId' => $this->referenceId,
            'customerInitiatedTransaction' => $this->customerInitiatedTransaction,
        ]);

        $payload['cardDataSource'] = $this->cardDataSource;

        return $payload;
    }

    /**
     * Fail closed for every generic serialization path an integrator might
     * accidentally hit — var_dump()/VarDumper/Xdebug (__debugInfo), json_encode()
     * (JsonSerializable), and serialize() (__serialize) — so none of them emit the
     * PAN/CVV (PCI Req 3.2/3.4). var_export() has no maskable hook (PHP exposes
     * none) and stays unsafe by design — never var_export() one. The SDK never
     * serializes the object itself: the wire path builds its payload from toArray(),
     * which is unaffected and still carries the real values. toArray() remains the
     * only explicit way to reach the cleartext card data.
     *
     * @return array<string, mixed>
     */
    private function maskedView(): array
    {
        /** @var array<string, mixed> $view */
        $view = get_object_vars($this);
        if ($this->accountNumber !== null) {
            $view['accountNumber'] = self::maskAccountNumber($this->accountNumber);
        }
        if ($this->securityCode !== null) {
            // CVV is sensitive authentication data: never display, even masked.
            $view['securityCode'] = self::CVV_PLACEHOLDER;
        }

        return $view;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->maskedView();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->maskedView();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->maskedView();
    }

    /**
     * Restore from the redacted __serialize() payload. A round-tripped object
     * therefore carries masked card data (fail closed by design) — these objects
     * are not meant to be serialized; use toArray() for the wire payload.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        /*
         * Explicit per-property assignment with type guards (the
         * CreateMerchantApiKeyResponse pattern): unknown keys are ignored so no
         * deprecated dynamic property is created, a wrong-typed value falls back
         * to null/default instead of throwing an uncaught TypeError, and a
         * missing key hydrates here rather than deferring failure to first
         * property access. readonly promoted properties may be initialized once
         * because unserialize() builds the instance without the constructor.
         */
        $this->amount = self::floatOrNull($data['amount'] ?? null);
        $this->accountNumber = self::strOrNull($data['accountNumber'] ?? null);
        $this->currencyId = self::intOrNull($data['currencyId'] ?? null);
        $this->expirationMonth = self::intOrNull($data['expirationMonth'] ?? null);
        $this->expirationYear = self::intOrNull($data['expirationYear'] ?? null);
        $this->cardDataSource = is_int($data['cardDataSource'] ?? null) ? $data['cardDataSource'] : 1;
        $this->securityCode = self::strOrNull($data['securityCode'] ?? null);
        $this->customerId = self::strOrNull($data['customerId'] ?? null);
        $this->paymentMethodId = self::strOrNull($data['paymentMethodId'] ?? null);
        $this->deviceId = self::strOrNull($data['deviceId'] ?? null);
        $this->paymentProcessorId = self::strOrNull($data['paymentProcessorId'] ?? null);
        $this->tipAmount = self::floatOrNull($data['tipAmount'] ?? null);
        $this->tipRate = self::floatOrNull($data['tipRate'] ?? null);
        $this->percentageOffRate = self::floatOrNull($data['percentageOffRate'] ?? null);
        $this->surchargeRate = self::floatOrNull($data['surchargeRate'] ?? null);
        $this->useCardPrice = self::boolOrNull($data['useCardPrice'] ?? null);
        $billingAddress = $data['billingAddress'] ?? null;
        $this->billingAddress = $billingAddress instanceof Address ? $billingAddress : null;
        $shippingAddress = $data['shippingAddress'] ?? null;
        $this->shippingAddress = $shippingAddress instanceof Address ? $shippingAddress : null;
        $contactInfo = $data['contactInfo'] ?? null;
        $this->contactInfo = $contactInfo instanceof ContactInfo ? $contactInfo : null;
        $this->referenceId = self::strOrNull($data['referenceId'] ?? null);
        $this->customerInitiatedTransaction = self::boolOrNull($data['customerInitiatedTransaction'] ?? null);
    }

    private static function strOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_float($value) ? $value : null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** Reveal only the last four digits, the maximum PCI permits displaying. */
    private static function maskAccountNumber(#[\SensitiveParameter] string $accountNumber): string
    {
        if (strlen($accountNumber) <= 4) {
            return str_repeat('*', strlen($accountNumber));
        }

        return str_repeat('*', strlen($accountNumber) - 4) . substr($accountNumber, -4);
    }
}

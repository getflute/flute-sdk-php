<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;
use Flute\Sdk\Internal\Redact;

/**
 * Processor outcome details attached to transaction operations.
 */
final class TransactionOutcomeDetails
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?string $authCode,
        public readonly ?string $maskedPan,
        public readonly ?string $hostResponseCode,
        public readonly ?string $hostResponseMessage,
        public readonly ?string $processorResponseCode,
        private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            code: Data::str($data, 'code'),
            message: Data::str($data, 'message'),
            authCode: Data::str($data, 'authCode'),
            maskedPan: Data::str($data, 'maskedPan'),
            hostResponseCode: Data::str($data, 'hostResponseCode'),
            hostResponseMessage: Data::str($data, 'hostResponseMessage'),
            processorResponseCode: Data::str($data, 'processorResponseCode'),
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
     * Flute returns maskedPan already masked, but the SDK hedges against a
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
        if ($this->maskedPan !== null) {
            $view['maskedPan'] = Redact::sensitive($this->maskedPan);
        }
        $view['raw'] = Redact::payload($this->raw);

        return $view;
    }
}

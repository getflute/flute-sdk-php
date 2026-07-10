<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

use Flute\Sdk\Internal\Data;
use Flute\Sdk\Internal\Redact;

/**
 * Freshly minted merchant API credential. The clientSecret is returned only
 * at creation and can never be retrieved again — store it immediately.
 */
final class CreateMerchantApiKeyResponse implements \JsonSerializable
{
    private const SECRET_PLACEHOLDER = '***redacted***';

    /** @param array<string, mixed> $raw */
    private function __construct(
        public readonly ?string $clientId,
        #[\SensitiveParameter] public readonly ?string $clientSecret,
        #[\SensitiveParameter] private readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            clientId: Data::str($data, 'clientId'),
            clientSecret: Data::str($data, 'clientSecret'),
            raw: $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * Fail closed for every generic serialization path — var_dump()/VarDumper
     * (__debugInfo), json_encode() (JsonSerializable), and serialize()
     * (__serialize) — so the one-time minted clientSecret (a live credential the
     * docs tell integrators to capture) cannot leak into logs, sessions, or
     * queues through any of them. The retained raw payload is scrubbed key-aware
     * (Redact::payload) so credential-like aliases, casing variants, or future
     * credential fields are masked too — not only the exact `clientSecret` key.
     * toArray() remains the explicit way to read the real secret for the one-time
     * capture.
     *
     * @return array<string, mixed>
     */
    private function maskedView(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret === null ? null : self::SECRET_PLACEHOLDER,
            'raw' => Redact::payload($this->raw),
        ];
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
     * Restore from the redacted __serialize() payload (fail closed: a
     * round-tripped object carries a masked secret). Use toArray() for the
     * one-time real-secret capture instead of serializing this object.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        /** @var array<string, mixed> $raw */
        $raw = $data['raw'] ?? [];
        $this->clientId = is_string($data['clientId'] ?? null) ? $data['clientId'] : null;
        $this->clientSecret = is_string($data['clientSecret'] ?? null) ? $data['clientSecret'] : null;
        $this->raw = $raw;
    }
}

<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Billing or shipping address value object (nested in transaction requests).
 *
 * Omit the address entirely when you have no fields to send: an Address with
 * every field null serializes to JSON `[]`, not `{}`, which the API rejects.
 * Construct one only when you have at least one field to populate.
 */
final class Address
{
    public function __construct(
        public readonly ?string $line1 = null,
        public readonly ?string $line2 = null,
        public readonly ?string $city = null,
        public readonly ?string $stateName = null,
        public readonly ?int $stateId = null,
        public readonly ?string $postalCode = null,
        public readonly ?int $countryId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Data::filterNull([
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'stateName' => $this->stateName,
            'stateId' => $this->stateId,
            'postalCode' => $this->postalCode,
            'countryId' => $this->countryId,
        ]);
    }
}

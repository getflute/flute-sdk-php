<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Customer contact details (nested in transaction requests).
 */
final class ContactInfo
{
    public function __construct(
        public readonly ?string $companyName = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $email = null,
        public readonly ?string $mobilePhoneNumber = null,
        public readonly ?bool $smsNotification = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return Data::filterNull([
            'companyName' => $this->companyName,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'mobilePhoneNumber' => $this->mobilePhoneNumber,
            'smsNotification' => $this->smsNotification,
        ]);
    }
}

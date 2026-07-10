<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Responses;

/**
 * Shared parse of the wire `transactionDateTime` string into a date object,
 * for the response DTOs that carry that readonly property. Consumers must
 * declare a `?string $transactionDateTime` property; PHPStan enforces this.
 *
 * @internal shared by the transaction response DTOs; not a public extension point
 */
trait ParsesTransactionDateTime
{
    /** Wire timestamp parsed as an immutable date; null when absent or unparseable. */
    public function transactionDateTimeAsObject(): ?\DateTimeImmutable
    {
        if ($this->transactionDateTime === null || trim($this->transactionDateTime) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($this->transactionDateTime);
        } catch (\Exception) {
            return null;
        }
    }
}

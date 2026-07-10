<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Pagination filters for transaction listing.
 */
final class ListTransactionsRequest
{
    /**
     * @throws \InvalidArgumentException when $page is negative or $pageSize is not positive
     */
    public function __construct(
        public readonly ?int $page = null,
        public readonly ?int $pageSize = null,
    ) {
        if ($page !== null && $page < 0) {
            throw new \InvalidArgumentException('page must be >= 0.');
        }
        if ($pageSize !== null && $pageSize < 1) {
            throw new \InvalidArgumentException('pageSize must be >= 1.');
        }
    }

    /** @return array<string, mixed> */
    public function toQuery(): array
    {
        return Data::filterNull([
            'page' => $this->page,
            'pageSize' => $this->pageSize,
        ]);
    }
}

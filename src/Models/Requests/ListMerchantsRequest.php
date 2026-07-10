<?php

declare(strict_types=1);

namespace Flute\Sdk\Models\Requests;

use Flute\Sdk\Internal\Data;

/**
 * Search, filter, and pagination options for the partner merchant list.
 * Date filters are ISO-8601 UTC strings.
 */
final class ListMerchantsRequest
{
    /**
     * @throws \InvalidArgumentException when $page is negative or $pageSize is not positive
     */
    public function __construct(
        public readonly ?int $page = null,
        public readonly ?int $pageSize = null,
        public readonly ?string $orderBy = null,
        public readonly ?bool $asc = null,
        public readonly ?string $search = null,
        public readonly ?int $mccCodeId = null,
        public readonly ?int $statusId = null,
        public readonly ?string $createdFrom = null,
        public readonly ?string $createdTo = null,
        public readonly ?string $modifiedFrom = null,
        public readonly ?string $modifiedTo = null,
        public readonly ?string $lastTransactionDateFrom = null,
        public readonly ?string $lastTransactionDateTo = null,
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
            'orderBy' => $this->orderBy,
            // Explicit strings: the query builder renders PHP bools as 1/0.
            'asc' => $this->asc === null ? null : ($this->asc ? 'true' : 'false'),
            'search' => $this->search,
            'mccCodeId' => $this->mccCodeId,
            'statusId' => $this->statusId,
            'createdFrom' => $this->createdFrom,
            'createdTo' => $this->createdTo,
            'modifiedFrom' => $this->modifiedFrom,
            'modifiedTo' => $this->modifiedTo,
            'lastTransactionDateFrom' => $this->lastTransactionDateFrom,
            'lastTransactionDateTo' => $this->lastTransactionDateTo,
        ]);
    }
}

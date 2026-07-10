<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Models\Requests;

use Flute\Sdk\Models\Requests\CreateMerchantApiKeyRequest;
use Flute\Sdk\Models\Requests\ListMerchantsRequest;
use PHPUnit\Framework\TestCase;

final class MerchantRequestsTest extends TestCase
{
    public function testListMerchantsSerializesAllFiltersAndOmitsNulls(): void
    {
        $request = new ListMerchantsRequest(
            page: 0,
            pageSize: 25,
            orderBy: 'companyName',
            asc: true,
            search: 'cafe',
            mccCodeId: 28,
            statusId: 1,
            createdFrom: '2026-01-01T00:00:00Z',
            createdTo: '2026-06-01T00:00:00Z',
            modifiedFrom: '2026-02-01T00:00:00Z',
            modifiedTo: '2026-05-01T00:00:00Z',
            lastTransactionDateFrom: '2026-03-01T00:00:00Z',
            lastTransactionDateTo: '2026-04-01T00:00:00Z',
        );

        self::assertSame([
            'page' => 0,
            'pageSize' => 25,
            'orderBy' => 'companyName',
            'asc' => 'true',
            'search' => 'cafe',
            'mccCodeId' => 28,
            'statusId' => 1,
            'createdFrom' => '2026-01-01T00:00:00Z',
            'createdTo' => '2026-06-01T00:00:00Z',
            'modifiedFrom' => '2026-02-01T00:00:00Z',
            'modifiedTo' => '2026-05-01T00:00:00Z',
            'lastTransactionDateFrom' => '2026-03-01T00:00:00Z',
            'lastTransactionDateTo' => '2026-04-01T00:00:00Z',
        ], $request->toQuery());
    }

    public function testListMerchantsDefaultsToEmptyQueryAndSerializesFalse(): void
    {
        self::assertSame([], (new ListMerchantsRequest())->toQuery());
        self::assertSame(['asc' => 'false'], (new ListMerchantsRequest(asc: false))->toQuery());
    }

    public function testListMerchantsRejectsNegativePage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListMerchantsRequest(page: -1);
    }

    public function testListMerchantsRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListMerchantsRequest(pageSize: 0);
    }

    public function testCreateMerchantApiKeySerializesBothFields(): void
    {
        $request = new CreateMerchantApiKeyRequest(merchantId: 'm-1', tokenName: 'Cafe key');

        self::assertSame(['merchantId' => 'm-1', 'tokenName' => 'Cafe key'], $request->toArray());
    }
}

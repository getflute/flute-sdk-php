<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Internal;

use Flute\Sdk\Internal\Data;
use PHPUnit\Framework\TestCase;

final class DataTest extends TestCase
{
    public function testExtractsTypedValues(): void
    {
        $raw = [
            'name' => 'abc',
            'count' => 3,
            'amount' => 10.5,
            'intAmount' => 10,
            'numericString' => '7.25',
            'flag' => true,
            'nested' => ['a' => 1],
        ];

        self::assertSame('abc', Data::str($raw, 'name'));
        self::assertSame(3, Data::int($raw, 'count'));
        self::assertSame(10.5, Data::float($raw, 'amount'));
        self::assertSame(10.0, Data::float($raw, 'intAmount'));
        self::assertSame(7.25, Data::float($raw, 'numericString'));
        self::assertTrue(Data::bool($raw, 'flag'));
        self::assertSame(['a' => 1], Data::arr($raw, 'nested'));
    }

    public function testMissingOrMistypedKeysReturnNull(): void
    {
        $raw = ['name' => 123, 'amount' => 'not-a-number', 'intish' => '7', 'floatish' => 7.0];

        self::assertNull(Data::str($raw, 'name'));
        self::assertNull(Data::str($raw, 'absent'));
        self::assertNull(Data::int($raw, 'name2'));
        self::assertNull(Data::float($raw, 'amount'));
        self::assertNull(Data::bool($raw, 'absent'));
        self::assertNull(Data::arr($raw, 'absent'));
        self::assertNull(Data::int($raw, 'intish'));
        self::assertNull(Data::int($raw, 'floatish'));
    }

    public function testArrRejectsListsButKeepsEmptyObject(): void
    {
        // A JSON list is never a nested object: reject it rather than laundering
        // it through the array<string, mixed> contract callers rely on.
        self::assertNull(Data::arr(['x' => [1, 2, 3]], 'x'));
        // Empty array is the ambiguous decoding of an empty JSON object: kept.
        self::assertSame([], Data::arr(['x' => []], 'x'));
    }

    public function testMapListHydratesObjectRowsAndSkipsNonArrayRows(): void
    {
        // Object-shaped rows are hydrated via the factory; scalar rows (a string,
        // an int) are skipped rather than laundered into the typed list.
        $out = Data::mapList(
            ['items' => [['id' => 'a'], 'junk', 42, ['id' => 'b']]],
            'items',
            $this->wrapRow(...),
        );

        self::assertCount(2, $out);
        self::assertEquals((object) ['id' => 'a'], $out[0]);
        self::assertEquals((object) ['id' => 'b'], $out[1]);
    }

    public function testMapListReturnsEmptyForAbsentOrScalarContainer(): void
    {
        // Absent key -> [].
        self::assertSame([], Data::mapList([], 'items', $this->wrapRow(...)));
        // A present-but-scalar container degrades to [] rather than throwing: the
        // load-bearing container guard the three paginated list DTOs rely on.
        self::assertSame([], Data::mapList(['items' => 'oops'], 'items', $this->wrapRow(...)));
    }

    /**
     * Trivial hydration factory for exercising mapList in isolation.
     *
     * @param array<string, mixed> $row
     */
    private function wrapRow(array $row): object
    {
        return (object) $row;
    }
}

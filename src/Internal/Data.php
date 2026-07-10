<?php

declare(strict_types=1);

namespace Flute\Sdk\Internal;

/**
 * Tolerant extraction of typed values from decoded API payloads.
 *
 * @internal not part of the public SDK surface
 */
final class Data
{
    /** @param array<string, mixed> $data */
    public static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function arr(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        /*
         * Only object-shaped arrays represent nested DTOs. A JSON list is never a
         * nested object, so reject it rather than laundering a list through the
         * array<string, mixed> contract callers rely on. Empty array is kept: it
         * is the ambiguous decoding of an empty JSON object.
         */
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Hydrate a payload list under $key into objects via $factory, guarding the
     * container and each row as object-shaped. A non-array container or row is
     * skipped, never laundered — the same guard the list DTOs reimplemented by
     * hand. Returns [] when the key is absent or its value is not an array; a
     * present array is iterated by value (an object-shaped container is not
     * rejected, unlike arr()), and non-array rows are skipped.
     *
     * @template T of object
     *
     * @param array<string, mixed> $data
     * @param callable(array<string, mixed>): T $factory
     *
     * @return list<T>
     */
    public static function mapList(array $data, string $key, callable $factory): array
    {
        $raw = $data[$key] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $items[] = $factory($row);
            }
        }

        return $items;
    }

    /**
     * Drop only null entries from a request payload, preserving every other
     * value. A bare array_filter() would also drop 0, 0.0, false, and '' — all
     * legitimate wire values (amount 0.0, useCardPrice false) — so request DTOs
     * must filter on null identity, never array_filter's truthiness default.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public static function filterNull(array $fields): array
    {
        return array_filter($fields, static fn (mixed $v): bool => $v !== null);
    }
}

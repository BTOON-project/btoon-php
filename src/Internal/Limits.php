<?php

declare(strict_types=1);

namespace BTOON\Internal;

/**
 * The decoder's resource limits (SPEC §7.1), and their defaults under PHP's `memory_limit`.
 *
 * SPEC 0.2's defaults let a document decode to 2^20 values. PHP spends up to about 400 bytes
 * on one value (a `\DateTimeImmutable`, or an `\ArrayObject` row: measured with PHP 8.5),
 * so a document within those limits can need about 0.5 GB, and a few KB of compressed input
 * can describe one. Under the usual `memory_limit` of 128M that is an uncatchable fatal
 * error, not a `BtoonException`.
 *
 * SPEC §7.1 lets an implementation whose runtime has a lower memory ceiling use lower
 * defaults, and requires it to document them. So when the caller doesn't set them, the
 * defaults shrink to fit `memory_limit`:
 *
 * - `max_values` is at most `memory_limit / 1024`: values of the most expensive kind then
 *   take at most about half of `memory_limit`;
 * - `max_decompressed_size` is at most `memory_limit / 8`: inflating holds up to twice its
 *   output while the string grows, and the decoder keeps the output while it decodes.
 *
 * The result depends only on the `memory_limit` setting when the options are created, not
 * on the memory in use. With `memory_limit = 128M` the defaults are 131,072 values and
 * 16 MiB. SPEC's 64 MiB applies from 512M and its 2^20 values from 1G, and both apply with
 * no limit (-1). Explicit limits are never changed.
 *
 * @internal
 */
final class Limits
{
    /** SPEC §7.1 default for `max_values`: 2^20. */
    public const MAX_VALUES = 1_048_576;
    /** SPEC §7.1 default for `max_decompressed_size`: 64 MiB. */
    public const MAX_DECOMPRESSED_SIZE = 67_108_864;
    /** SPEC §7: no `max_depth` above this. */
    public const MAX_DEPTH = 1000;
    /**
     * PHP's own default for `max_map_entries`: the most entries a map, or columns a table,
     * may have. PHP hashes array keys with DJBX33A and no secret seed, so keys can be chosen
     * to share one hash, and then adding n of them to one array compares about n²/2 keys.
     * With at most this many entries per map, each entry costs at most this many comparisons,
     * so decoding stays linear in `max_values`. A lower cap would be cheaper still, but would
     * reject ordinary documents that every other BTOON library decodes, such as a few
     * thousand entries keyed by id. The worst case is in the README's "Map size" section.
     */
    public const MAX_MAP_ENTRIES = 4096;

    /** The default `max_values` is at most `memory_limit / 2^10`. */
    private const VALUES_SHIFT = 10;
    /** The default `max_decompressed_size` is at most `memory_limit / 2^3`. */
    private const DECOMPRESSED_SHIFT = 3;
    /**
     * A `memory_limit` below the memory in use can't be set, and PHP's heap always holds a
     * 2 MiB chunk, so a smaller setting is never in effect.
     */
    private const MIN_MEMORY_LIMIT = 2 * 1024 * 1024;

    /** @return non-negative-int */
    public static function defaultMaxValues(): int
    {
        $limit = self::memoryLimit();

        return $limit === null ? self::MAX_VALUES : min(self::MAX_VALUES, $limit >> self::VALUES_SHIFT);
    }

    /** @return non-negative-int */
    public static function defaultMaxDecompressedSize(): int
    {
        $limit = self::memoryLimit();

        return $limit === null
            ? self::MAX_DECOMPRESSED_SIZE
            : min(self::MAX_DECOMPRESSED_SIZE, $limit >> self::DECOMPRESSED_SHIFT);
    }

    /**
     * The `memory_limit` setting in bytes, or null when there is none.
     *
     * @return positive-int|null
     */
    public static function memoryLimit(): ?int
    {
        // memory_limit is always set, and validated when it is, so this can't warn in practice.
        $bytes = @ini_parse_quantity(ini_get('memory_limit'));

        return $bytes < 0 ? null : max($bytes, self::MIN_MEMORY_LIMIT);
    }
}

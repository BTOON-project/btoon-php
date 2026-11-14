<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\Limits;
use BTOON\Internal\Options;

/**
 * Decoder options. Pass an instance, or the equivalent snake_case array, to
 * {@see decode()} or {@see Decoder}:
 *
 * | array key               | property              | default    | meaning                                              |
 * |-------------------------|-----------------------|------------|------------------------------------------------------|
 * | `strict`                | `strict`              | true       | SPEC §7 strict mode                                  |
 * | `max_depth`             | `maxDepth`            | 128        | nesting limit, at most 1000                          |
 * | `max_decompressed_size` | `maxDecompressedSize` | 64 MiB *   | limit for compression envelopes and table keys       |
 * | `max_values`            | `maxValues`           | 2^20 *     | how many values a document may decode to             |
 * | `max_map_entries`       | `maxMapEntries`       | 4096       | most entries in one map, or columns in one table     |
 * | `auto_decompress`       | `autoDecompress`      | true       | accept compression envelopes                         |
 * | `hmac_key`              | `hmacKey`             | null       | require and verify a signed envelope (SPEC §8)       |
 * | `timestamps`            | `timestamps`          | 'datetime' | `'datetime'` → `\DateTimeImmutable`, `'object'` → value objects |
 * | `binary_as_object`      | `binaryAsObject`      | false      | return binaries as {@see Binary} instead of strings  |
 * | `maps`                  | `maps`                | 'auto'     | `'auto'`, `'array'` or `'object'`: see {@see MapMode} |
 *
 * Non-strict mode accepts trailing bytes (ignored), duplicate map keys (last wins) and
 * string values that aren't valid UTF-8. Map keys and column names must be valid UTF-8 in
 * both modes, and no bounds, depth or size check is relaxed.
 *
 * Resource limits (SPEC §7.1); exceeding one raises `size_limit`:
 * - `max_values` bounds the values a document decodes to. Each value counts once, when it
 *   starts: the top-level value, array elements, map values, extensions, and a table's
 *   rows and cells. Map keys don't count; the values inside a compression envelope do.
 * - `max_decompressed_size` bounds the size a compression envelope declares, and a table's
 *   rows × the byte length of its column names, which the decoder copies into every row.
 *
 * \* When not given (or given as null), these two defaults shrink to fit `memory_limit`, so
 * that a document within them can't exhaust it, as SPEC §7.1 allows for runtimes with a
 * lower memory ceiling: `max_values` is at most `memory_limit / 1024`, and
 * `max_decompressed_size` at most `memory_limit / 8`. With `memory_limit = 128M` they are
 * 131,072 values and 16 MiB; from 1G, and with no limit, SPEC's 2^20 and 64 MiB. The
 * properties hold the values in effect, and explicit values are used as given.
 *
 * `max_map_entries` is PHP's own limit, not SPEC's: a map with more entries, or a table with
 * more columns (its rows are maps), raises `size_limit`. PHP hashes array keys without a
 * secret seed, so keys can be chosen to share one hash, and then every key added to a map
 * is compared with every key already in it. The limit keeps the cost linear in `max_values`
 * (see the README's "Map size" section for the worst case). Raise it to decode larger maps.
 */
final readonly class DecodeOptions
{
    public const DEFAULT_MAX_DEPTH = 128;
    /** The largest `max_depth` (SPEC §7); more raises `invalid_argument`. */
    public const MAX_DEPTH_LIMIT = Limits::MAX_DEPTH;
    /** SPEC's default `max_decompressed_size`, before the `memory_limit` adjustment. */
    public const DEFAULT_MAX_DECOMPRESSED_SIZE = Limits::MAX_DECOMPRESSED_SIZE;
    /** SPEC's default `max_values`, before the `memory_limit` adjustment. */
    public const DEFAULT_MAX_VALUES = Limits::MAX_VALUES;
    /** The default `max_map_entries` (PHP's own limit, against hash collisions). */
    public const DEFAULT_MAX_MAP_ENTRIES = Limits::MAX_MAP_ENTRIES;

    private const KEYS = [
        'strict', 'max_depth', 'max_decompressed_size', 'max_values', 'max_map_entries', 'auto_decompress', 'hmac_key',
        'timestamps', 'binary_as_object', 'maps',
    ];

    /** @var non-negative-int bytes; envelopes declaring more, and larger table keys, raise `size_limit` */
    public int $maxDecompressedSize;

    /** @var non-negative-int a document that decodes to more values raises `size_limit` */
    public int $maxValues;

    /**
     * @param non-negative-int      $maxDepth            at most {@see MAX_DEPTH_LIMIT}
     * @param non-negative-int|null $maxDecompressedSize bytes; null for the default
     * @param non-negative-int|null $maxValues           null for the default
     * @param non-negative-int      $maxMapEntries       entries per map, columns per table
     */
    public function __construct(
        public bool $strict = true,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        ?int $maxDecompressedSize = null,
        public bool $autoDecompress = true,
        public ?string $hmacKey = null,
        public TimestampMode $timestamps = TimestampMode::DateTime,
        public bool $binaryAsObject = false,
        public MapMode $maps = MapMode::Auto,
        ?int $maxValues = null,
        public int $maxMapEntries = self::DEFAULT_MAX_MAP_ENTRIES,
    ) {
        // Runtime checks: callers without static analysis can pass anything.
        Options::maxDepth($maxDepth);
        if ($maxDecompressedSize !== null) {
            Options::assertNonNegative('max_decompressed_size', $maxDecompressedSize);
        }
        if ($maxValues !== null) {
            Options::assertNonNegative('max_values', $maxValues);
        }
        Options::assertNonNegative('max_map_entries', $maxMapEntries);
        Options::assertKey($hmacKey);
        $this->maxDecompressedSize = $maxDecompressedSize ?? Limits::defaultMaxDecompressedSize();
        $this->maxValues = $maxValues ?? Limits::defaultMaxValues();
    }

    /**
     * Builds options from a snake_case array such as `['strict' => false, 'timestamps' => 'object']`.
     * Unknown keys and values of the wrong type raise `invalid_argument`.
     *
     * @param array<array-key, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $strict = true;
        $maxDepth = self::DEFAULT_MAX_DEPTH;
        $maxSize = null;
        $maxValues = null;
        $maxMapEntries = self::DEFAULT_MAX_MAP_ENTRIES;
        $autoDecompress = true;
        $hmacKey = null;
        $timestamps = TimestampMode::DateTime;
        $binaryAsObject = false;
        $maps = MapMode::Auto;
        foreach ($options as $name => $value) {
            switch ($name) {
                case 'strict':
                    $strict = Options::bool($name, $value);
                    break;
                case 'max_depth':
                    $maxDepth = Options::nonNegativeInt($name, $value);
                    break;
                case 'max_decompressed_size':
                    $maxSize = Options::limit($name, $value);
                    break;
                case 'max_values':
                    $maxValues = Options::limit($name, $value);
                    break;
                case 'max_map_entries':
                    $maxMapEntries = Options::nonNegativeInt($name, $value);
                    break;
                case 'auto_decompress':
                    $autoDecompress = Options::bool($name, $value);
                    break;
                case 'hmac_key':
                    $hmacKey = Options::hmacKey($name, $value);
                    break;
                case 'timestamps':
                    $timestamps = Options::enum($name, $value, TimestampMode::class);
                    break;
                case 'binary_as_object':
                    $binaryAsObject = Options::bool($name, $value);
                    break;
                case 'maps':
                    $maps = Options::enum($name, $value, MapMode::class);
                    break;
                default:
                    throw Options::unknown((string) $name, 'decode', self::KEYS);
            }
        }

        return new self(
            $strict,
            $maxDepth,
            $maxSize,
            $autoDecompress,
            $hmacKey,
            $timestamps,
            $binaryAsObject,
            $maps,
            $maxValues,
            $maxMapEntries,
        );
    }
}

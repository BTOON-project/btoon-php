<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\Options;

/**
 * Decoder options. Pass an instance, or the equivalent snake_case array, to
 * {@see decode()} or {@see Decoder}:
 *
 * | array key               | property              | default    | meaning                                              |
 * |-------------------------|-----------------------|------------|------------------------------------------------------|
 * | `strict`                | `strict`              | true       | SPEC §7 strict mode                                  |
 * | `max_depth`             | `maxDepth`            | 128        | nesting limit                                        |
 * | `max_decompressed_size` | `maxDecompressedSize` | 256 MiB    | limit for compression envelopes                      |
 * | `auto_decompress`       | `autoDecompress`      | true       | accept compression envelopes                         |
 * | `hmac_key`              | `hmacKey`             | null       | require and verify a signed envelope (SPEC §8)       |
 * | `timestamps`            | `timestamps`          | 'datetime' | `'datetime'` → `\DateTimeImmutable`, `'object'` → value objects |
 * | `binary_as_object`      | `binaryAsObject`      | false      | return binaries as {@see Binary} instead of strings  |
 *
 * Non-strict mode accepts trailing bytes (ignored), duplicate map keys (last wins) and
 * invalid UTF-8. It never relaxes bounds, depth or size checks.
 */
final readonly class DecodeOptions
{
    public const DEFAULT_MAX_DEPTH = 128;
    public const DEFAULT_MAX_DECOMPRESSED_SIZE = 256 * 1024 * 1024;

    private const KEYS = [
        'strict', 'max_depth', 'max_decompressed_size', 'auto_decompress', 'hmac_key', 'timestamps', 'binary_as_object',
    ];

    /**
     * @param non-negative-int $maxDepth
     * @param non-negative-int $maxDecompressedSize bytes; envelopes declaring more fail with `size_limit`
     */
    public function __construct(
        public bool $strict = true,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxDecompressedSize = self::DEFAULT_MAX_DECOMPRESSED_SIZE,
        public bool $autoDecompress = true,
        public ?string $hmacKey = null,
        public TimestampMode $timestamps = TimestampMode::DateTime,
        public bool $binaryAsObject = false,
    ) {
        // Runtime checks: callers without static analysis can pass anything.
        Options::maxDepth($maxDepth);
        Options::assertNonNegative('max_decompressed_size', $maxDecompressedSize);
        Options::assertKey($hmacKey);
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
        $maxSize = self::DEFAULT_MAX_DECOMPRESSED_SIZE;
        $autoDecompress = true;
        $hmacKey = null;
        $timestamps = TimestampMode::DateTime;
        $binaryAsObject = false;
        foreach ($options as $name => $value) {
            switch ($name) {
                case 'strict':
                    $strict = Options::bool($name, $value);
                    break;
                case 'max_depth':
                    $maxDepth = Options::nonNegativeInt($name, $value);
                    break;
                case 'max_decompressed_size':
                    $maxSize = Options::nonNegativeInt($name, $value);
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
                default:
                    throw Options::unknown((string) $name, 'decode', self::KEYS);
            }
        }

        return new self($strict, $maxDepth, $maxSize, $autoDecompress, $hmacKey, $timestamps, $binaryAsObject);
    }
}

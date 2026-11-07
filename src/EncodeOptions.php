<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\Options;

/**
 * Encoder options. Pass an instance, or the equivalent snake_case array, to
 * {@see encode()} or {@see Encoder}:
 *
 * | array key      | property       | default | meaning                                                  |
 * |----------------|----------------|---------|----------------------------------------------------------|
 * | `compress`     | `compress`     | false   | wrap the output in a compression envelope (SPEC §6)      |
 * | `compression`  | `compression`  | zlib    | algorithm used when `compress` is true                   |
 * | `auto_tabular` | `autoTabular`  | true    | use the columnar encoding when the size rule says so     |
 * | `max_depth`    | `maxDepth`     | 128     | nesting limit; deeper or cyclic input raises `max_depth` |
 * | `hmac_key`     | `hmacKey`      | null    | sign the output with HMAC-SHA256 (SPEC §8)               |
 */
final readonly class EncodeOptions
{
    public const DEFAULT_MAX_DEPTH = 128;

    private const KEYS = ['compress', 'compression', 'auto_tabular', 'max_depth', 'hmac_key'];

    /** @param non-negative-int $maxDepth */
    public function __construct(
        public bool $compress = false,
        public Compression $compression = Compression::Zlib,
        public bool $autoTabular = true,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public ?string $hmacKey = null,
    ) {
        Options::maxDepth($maxDepth);
        Options::assertKey($hmacKey);
    }

    /**
     * Builds options from a snake_case array such as `['compress' => true, 'max_depth' => 64]`.
     * Unknown keys and values of the wrong type raise `invalid_argument`.
     *
     * @param array<array-key, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $compress = false;
        $compression = Compression::Zlib;
        $autoTabular = true;
        $maxDepth = self::DEFAULT_MAX_DEPTH;
        $hmacKey = null;
        foreach ($options as $name => $value) {
            switch ($name) {
                case 'compress':
                    $compress = Options::bool($name, $value);
                    break;
                case 'compression':
                    $compression = Options::enum($name, $value, Compression::class);
                    break;
                case 'auto_tabular':
                    $autoTabular = Options::bool($name, $value);
                    break;
                case 'max_depth':
                    $maxDepth = Options::nonNegativeInt($name, $value);
                    break;
                case 'hmac_key':
                    $hmacKey = Options::hmacKey($name, $value);
                    break;
                default:
                    throw Options::unknown((string) $name, 'encode', self::KEYS);
            }
        }

        return new self($compress, $compression, $autoTabular, $maxDepth, $hmacKey);
    }
}

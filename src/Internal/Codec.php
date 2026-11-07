<?php

declare(strict_types=1);

namespace BTOON\Internal;

use BTOON\BtoonException;
use BTOON\Compression;
use BTOON\ErrorCode;

/**
 * Compression codecs for the envelope (SPEC §6).
 *
 * Decompression is always exact and bounded: the output must be exactly the declared
 * size, the payload must contain nothing after the compressed stream, and no codec
 * may produce much more than the declared size before it is stopped.
 *
 * - zlib (ext-zlib, required): incremental inflate with input fed in slices small
 *   enough that one slice can't inflate far past the declared size. (gzuncompress()
 *   is not used: its max_length can be exceeded and it ignores trailing bytes.)
 * - LZ4 (ext-lz4 >= 0.8): the raw block API, whose output buffer is exactly the
 *   declared size.
 * - Zstandard (ext-zstd >= 0.15): the frame structure is validated here first (one
 *   frame, no dictionary, matching content size, window at most
 *   max(8 MiB, min(size, 128 MiB))), then the frame is fed block by block to the
 *   incremental API, so each call yields at most one block (128 KiB).
 * - Snappy (ext-snappy): the length preamble is checked against the declared size
 *   before ext-snappy allocates it.
 * - Brotli: unsupported. ext-brotli has no way to bound or stop decompression or to
 *   detect trailing bytes, so it can't meet SPEC §6.
 *
 * @internal
 */
final class Codec
{
    /** Deflate expands at most ~1032:1 (258-byte matches coded in 2 bits). */
    private const DEFLATE_MAX_RATIO = 1032;
    private const ZLIB_MAX_SLICE = 65536;
    /** LZ4 expands at most ~255:1; LZ4_MAX_INPUT_SIZE. */
    private const LZ4_MAX_RATIO = 255;
    private const LZ4_MAX_SIZE = 0x7E000000;
    /** Snappy expands at most ~32:1. */
    private const SNAPPY_MAX_RATIO = 32;

    public static function isAvailable(Compression $algorithm): bool
    {
        return match ($algorithm) {
            Compression::Zlib => \function_exists('gzcompress') && \function_exists('inflate_init'),
            Compression::Lz4 => \function_exists('lz4_compress_raw') && \function_exists('lz4_uncompress_raw'),
            Compression::Zstd => \function_exists('zstd_compress') && \function_exists('zstd_uncompress_init')
                && \function_exists('zstd_uncompress_add'),
            Compression::Snappy => \function_exists('snappy_compress') && \function_exists('snappy_uncompress'),
            Compression::Brotli => false,
        };
    }

    public static function compress(Compression $algorithm, string $data): string
    {
        if (!self::isAvailable($algorithm)) {
            throw self::unavailable($algorithm);
        }
        [$compressed, $warning] = self::quietly(static fn (): string|false => match ($algorithm) {
            Compression::Zlib => gzcompress($data, 6),
            Compression::Lz4 => lz4_compress_raw($data),
            Compression::Zstd => zstd_compress($data, 3),
            Compression::Snappy => snappy_compress($data),
            Compression::Brotli => false,
        });
        if (!\is_string($compressed)) {
            throw new BtoonException(
                ErrorCode::InvalidArgument,
                sprintf('%s compression failed%s', $algorithm->value, $warning !== null ? ': ' . $warning : ''),
            );
        }

        return $compressed;
    }

    /**
     * Decompresses a payload that must expand to exactly $size bytes.
     *
     * @param non-negative-int $size
     */
    public static function decompress(Compression $algorithm, string $payload, int $size): string
    {
        return match ($algorithm) {
            Compression::Zlib => self::inflate($payload, $size),
            Compression::Lz4 => self::lz4($payload, $size),
            Compression::Zstd => self::zstd($payload, $size),
            Compression::Snappy => self::snappy($payload, $size),
            Compression::Brotli => throw self::unavailable($algorithm),
        };
    }

    /** @param non-negative-int $size */
    private static function inflate(string $payload, int $size): string
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $context = inflate_init(ZLIB_ENCODING_DEFLATE);
            if ($context === false) {
                throw self::corrupt('zlib', 'could not initialise inflate');
            }
            $output = '';
            $length = \strlen($payload);
            $offset = 0;
            while ($offset < $length) {
                // A slice of n input bytes inflates to at most ~1032·n bytes, so this slice can
                // overshoot the remaining budget by at most a few KiB.
                $slice = min(self::ZLIB_MAX_SLICE, intdiv($size - \strlen($output), self::DEFLATE_MAX_RATIO) + 16);
                $part = inflate_add($context, substr($payload, $offset, $slice), ZLIB_SYNC_FLUSH);
                if ($part === false) {
                    throw self::corrupt('zlib', 'is corrupt' . ($warning !== null ? ' (' . $warning . ')' : ''));
                }
                $offset += $slice;
                $output .= $part;
                if (\strlen($output) > $size) {
                    throw self::corrupt('zlib', sprintf('decompresses to more than the declared %d bytes', $size));
                }
                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    if (inflate_get_read_len($context) !== $length) {
                        throw self::corrupt('zlib', 'has trailing bytes after the zlib stream');
                    }
                    break;
                }
            }
            if (inflate_get_status($context) !== ZLIB_STREAM_END) {
                throw self::corrupt('zlib', 'is truncated');
            }
            if (\strlen($output) !== $size) {
                throw self::sizeMismatch('zlib', \strlen($output), $size);
            }

            return $output;
        } finally {
            restore_error_handler();
        }
    }

    /** @param non-negative-int $size */
    private static function lz4(string $payload, int $size): string
    {
        if ($size > self::LZ4_MAX_SIZE || $size > \strlen($payload) * self::LZ4_MAX_RATIO + 16) {
            throw self::corrupt('lz4', sprintf('declares an impossible decompressed size of %d bytes', $size));
        }
        if ($size === 0) {
            // ext-lz4 rejects a zero output size; the only empty LZ4 block is a single zero token.
            if ($payload === "\x00") {
                return '';
            }
            throw self::sizeMismatch('lz4', -1, 0);
        }
        [$output, $warning] = self::quietly(static fn (): string|false => lz4_uncompress_raw($payload, $size));
        if (!\is_string($output)) {
            throw self::corrupt('lz4', 'is corrupt' . ($warning !== null ? ' (' . $warning . ')' : ''));
        }
        if (\strlen($output) !== $size) {
            throw self::sizeMismatch('lz4', \strlen($output), $size);
        }

        return $output;
    }

    /** @param non-negative-int $size */
    private static function zstd(string $payload, int $size): string
    {
        $chunks = ZstdFrame::chunks($payload, $size);
        [$output, $warning] = self::quietly(static function () use ($payload, $chunks, $size): string|false {
            $context = zstd_uncompress_init();
            if ($context === false) {
                return false;
            }
            $output = '';
            $start = 0;
            foreach ($chunks as $end) {
                $part = zstd_uncompress_add($context, substr($payload, $start, $end - $start));
                if ($part === false) {
                    return false;
                }
                $output .= $part;
                if (\strlen($output) > $size) {
                    return $output; // reported as a size mismatch below
                }
                $start = $end;
            }

            return $output;
        });
        if (!\is_string($output)) {
            throw self::corrupt('zstd', 'is corrupt' . ($warning !== null ? ' (' . $warning . ')' : ''));
        }
        if (\strlen($output) !== $size) {
            throw self::sizeMismatch('zstd', \strlen($output), $size);
        }

        return $output;
    }

    /** @param non-negative-int $size */
    private static function snappy(string $payload, int $size): string
    {
        // Raw Snappy starts with the uncompressed length as a little-endian base-128 varint.
        $declared = 0;
        $shift = 0;
        $index = 0;
        $length = \strlen($payload);
        do {
            if ($index >= $length || $shift > 28) {
                throw self::corrupt('snappy', 'has a malformed length preamble');
            }
            $byte = \ord($payload[$index++]);
            $declared |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);
        if ($declared !== $size) {
            throw self::corrupt('snappy', sprintf('declares %d bytes but the envelope declares %d', $declared, $size));
        }
        if ($size > $length * self::SNAPPY_MAX_RATIO + 64) {
            throw self::corrupt('snappy', sprintf('declares an impossible decompressed size of %d bytes', $size));
        }
        [$output, $warning] = self::quietly(static fn (): string|false => snappy_uncompress($payload));
        if (!\is_string($output)) {
            throw self::corrupt('snappy', 'is corrupt' . ($warning !== null ? ' (' . $warning . ')' : ''));
        }
        if (\strlen($output) !== $size) {
            throw self::sizeMismatch('snappy', \strlen($output), $size);
        }

        return $output;
    }

    /**
     * Runs an extension call, capturing (instead of emitting) its warnings.
     *
     * @template T
     * @param \Closure(): T $operation
     * @return array{T, ?string}
     */
    private static function quietly(\Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $warning];
    }

    private static function unavailable(Compression $algorithm): BtoonException
    {
        return new BtoonException(
            ErrorCode::UnsupportedCompression,
            sprintf('compression algorithm %s is not available in this PHP installation', $algorithm->value),
        );
    }

    public static function corrupt(string $algorithm, string $what): BtoonException
    {
        return new BtoonException(ErrorCode::InvalidEnvelope, sprintf('%s payload %s', $algorithm, $what));
    }

    private static function sizeMismatch(string $algorithm, int $actual, int $declared): BtoonException
    {
        return self::corrupt($algorithm, $actual < 0
            ? sprintf('does not decompress to the declared %d bytes', $declared)
            : sprintf('decompresses to %d bytes but the envelope declares %d', $actual, $declared));
    }
}

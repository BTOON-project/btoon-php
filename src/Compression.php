<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\Codec;

/**
 * Compression algorithms of the compression envelope (SPEC §6).
 *
 * zlib is always available. The others need an optional PHP extension, see
 * {@see isAvailable()}. Asking for an unavailable algorithm raises
 * `unsupported_compression` instead of silently writing uncompressed output.
 */
enum Compression: string
{
    case Zlib = 'zlib';
    case Lz4 = 'lz4';
    case Zstd = 'zstd';
    case Brotli = 'brotli';
    case Snappy = 'snappy';

    /** The algorithm id written in the envelope header. */
    public function id(): int
    {
        return match ($this) {
            self::Zlib => 0,
            self::Lz4 => 1,
            self::Zstd => 2,
            self::Brotli => 3,
            self::Snappy => 4,
        };
    }

    /** The algorithm for an envelope id, or null for ids the spec doesn't define. */
    public static function fromId(int $id): ?self
    {
        return match ($id) {
            0 => self::Zlib,
            1 => self::Lz4,
            2 => self::Zstd,
            3 => self::Brotli,
            4 => self::Snappy,
            default => null,
        };
    }

    /** Whether this installation can compress and decompress with this algorithm. */
    public function isAvailable(): bool
    {
        return Codec::isAvailable($this);
    }

    /**
     * The algorithms usable in this installation.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isAvailable()));
    }
}

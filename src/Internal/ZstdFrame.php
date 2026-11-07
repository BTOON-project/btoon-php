<?php

declare(strict_types=1);

namespace BTOON\Internal;

/**
 * Structural validation of a Zstandard frame (RFC 8878 §3.1.1), done before any
 * decompression so the incremental decoder can be fed one block at a time.
 *
 * @internal
 */
final class ZstdFrame
{
    private const MAGIC = "\x28\xb5\x2f\xfd";
    /** Block_Maximum_Size is at most 128 KiB. */
    private const MAX_BLOCK = 131072;
    /** SPEC §6: reject windows above max(8 MiB, min(uncompressed_size, 128 MiB)). */
    private const MIN_WINDOW_LIMIT = 8 << 20;
    private const MAX_WINDOW_LIMIT = 128 << 20;

    /**
     * Checks that `$frame` is exactly one Zstandard frame (no skippable frames, no
     * trailing bytes, no dictionary) whose declared content size, if present, is
     * `$size`, and whose window is no larger than max(8 MiB, min($size, 128 MiB)),
     * which bounds the decoder's memory (SPEC §6). Returns the offsets at which to
     * split the input for the decoder: one chunk per block, the first one also holding
     * the frame header and the last one the content checksum. Each chunk therefore
     * regenerates at most 128 KiB.
     *
     * @param non-negative-int $size
     * @return non-empty-list<int>
     */
    public static function chunks(string $frame, int $size): array
    {
        $length = \strlen($frame);
        if ($length < 5 || !str_starts_with($frame, self::MAGIC)) {
            throw Codec::corrupt('zstd', 'is not a single Zstandard frame');
        }
        $descriptor = \ord($frame[4]);
        $contentSizeFlag = $descriptor >> 6;
        $singleSegment = ($descriptor & 0x20) !== 0;
        $checksum = ($descriptor & 0x04) !== 0;
        if (($descriptor & 0x08) !== 0) {
            throw Codec::corrupt('zstd', 'frame header has the reserved bit set');
        }
        $dictionaryBytes = [0, 1, 2, 4][$descriptor & 0x03];
        $contentSizeBytes = [$singleSegment ? 1 : 0, 2, 4, 8][$contentSizeFlag];
        $offset = 5 + ($singleSegment ? 0 : 1);
        if ($offset + $dictionaryBytes + $contentSizeBytes > $length) {
            throw Codec::corrupt('zstd', 'frame header is truncated');
        }
        // In single-segment frames the window is the content size, checked below.
        $window = $size;
        if (!$singleSegment) {
            $windowDescriptor = \ord($frame[5]);
            $windowBase = 1 << (10 + ($windowDescriptor >> 3));
            $window = $windowBase + ($windowBase >> 3) * ($windowDescriptor & 0x07);
        }
        if ($window > max(self::MIN_WINDOW_LIMIT, min($size, self::MAX_WINDOW_LIMIT))) {
            throw Codec::corrupt('zstd', sprintf('frame window of %d bytes is too large for %d bytes of content', $window, $size));
        }
        if ($dictionaryBytes > 0 && self::littleEndian($frame, $offset, $dictionaryBytes) !== 0) {
            throw Codec::corrupt('zstd', 'frame needs a dictionary');
        }
        $offset += $dictionaryBytes;
        if ($contentSizeBytes > 0) {
            $declared = self::littleEndian($frame, $offset, $contentSizeBytes) + ($contentSizeBytes === 2 ? 256 : 0);
            if ($declared !== $size) {
                throw Codec::corrupt('zstd', 'frame content size does not match the envelope');
            }
            $offset += $contentSizeBytes;
        }

        $chunks = [];
        $known = 0;
        do {
            if ($offset + 3 > $length) {
                throw Codec::corrupt('zstd', 'is truncated');
            }
            $header = self::littleEndian($frame, $offset, 3);
            $last = ($header & 1) === 1;
            $type = ($header >> 1) & 3;
            $blockSize = $header >> 3;
            if ($type === 3 || $blockSize > self::MAX_BLOCK) {
                throw Codec::corrupt('zstd', 'has an invalid block header');
            }
            if ($type !== 2) {
                $known += $blockSize; // raw and RLE blocks regenerate exactly Block_Size bytes
            }
            $offset += 3 + ($type === 1 ? 1 : $blockSize);
            if ($offset > $length) {
                throw Codec::corrupt('zstd', 'is truncated');
            }
            $chunks[] = $offset;
        } while (!$last);
        if ($known > $size) {
            throw Codec::corrupt('zstd', 'regenerates more than the declared size');
        }
        if ($checksum) {
            $offset += 4;
            if ($offset > $length) {
                throw Codec::corrupt('zstd', 'is truncated');
            }
            $chunks[\count($chunks) - 1] = $offset;
        }
        if ($offset !== $length) {
            throw Codec::corrupt('zstd', 'has trailing bytes after the frame');
        }

        return $chunks;
    }

    private static function littleEndian(string $bytes, int $offset, int $count): int
    {
        $value = 0;
        for ($i = $count - 1; $i >= 0; --$i) {
            $value = ($value << 8) | \ord($bytes[$offset + $i]);
        }

        return $value; // an 8-byte size >= 2^63 wraps negative and can never match
    }
}

<?php

declare(strict_types=1);

namespace BTOON\Internal;

use BTOON\BtoonException;
use BTOON\Compression;
use BTOON\DecodeOptions;
use BTOON\EncodeOptions;
use BTOON\ErrorCode;

/**
 * The compression envelope (SPEC §6) and the signed envelope (SPEC §8), layered as
 * sign(compress(encode(value))).
 *
 * @internal
 */
final class Envelope
{
    public const COMPRESSED_MAGIC = 'BTON';
    public const SIGNED_MAGIC = 'BTSG';
    public const COMPRESSED_HEADER = 16;
    public const SIGNED_HEADER = 40;

    /** Encoders SHOULD skip compression below this size (SPEC §6). */
    private const MIN_COMPRESS_SIZE = 256;

    /** Wraps an encoded document as the options ask. */
    public static function seal(string $document, EncodeOptions $options): string
    {
        if ($options->compress) {
            $document = self::compress($document, $options->compression);
        }
        if ($options->hmacKey !== null) {
            $document = self::sign($document, $options->hmacKey);
        }

        return $document;
    }

    /** Verifies and unwraps envelopes; returns the bare BTOON document. */
    public static function open(string $input, DecodeOptions $options): string
    {
        $length = \strlen($input);
        if ($options->hmacKey !== null) {
            $input = self::verify($input, $options->hmacKey);
            $length = \strlen($input);
        } elseif ($length >= self::SIGNED_HEADER && str_starts_with($input, self::SIGNED_MAGIC)) {
            throw new BtoonException(
                ErrorCode::AuthenticationFailed,
                'input is a signed envelope but no hmac_key was given',
            );
        }

        if ($length >= self::COMPRESSED_HEADER && str_starts_with($input, self::COMPRESSED_MAGIC)) {
            if (!$options->autoDecompress) {
                throw new BtoonException(
                    ErrorCode::InvalidEnvelope,
                    'input is a compression envelope but auto_decompress is disabled',
                );
            }
            $input = self::decompress($input, $options->maxDecompressedSize);
        }

        return $input;
    }

    public static function sign(string $payload, string $key): string
    {
        $header = self::SIGNED_MAGIC . "\x01\x01\x00\x00";

        return $header . hash_hmac('sha256', $header . $payload, $key, true) . $payload;
    }

    private static function verify(string $input, string $key): string
    {
        if (!str_starts_with($input, self::SIGNED_MAGIC)) {
            throw new BtoonException(
                ErrorCode::AuthenticationFailed,
                'hmac_key is set but the input is not a signed envelope',
            );
        }
        if (\strlen($input) < self::SIGNED_HEADER) {
            throw new BtoonException(ErrorCode::InvalidEnvelope, 'signed envelope is truncated');
        }
        if (substr($input, 4, 4) !== "\x01\x01\x00\x00") {
            throw new BtoonException(
                ErrorCode::InvalidEnvelope,
                'unsupported signed envelope version, algorithm or reserved bytes: 0x' . bin2hex(substr($input, 4, 4)),
            );
        }
        $payload = (string) substr($input, self::SIGNED_HEADER);
        $expected = hash_hmac('sha256', substr($input, 0, 8) . $payload, $key, true);
        if (!hash_equals($expected, substr($input, 8, 32))) {
            throw new BtoonException(ErrorCode::AuthenticationFailed, 'HMAC verification failed');
        }

        return $payload;
    }

    private static function compress(string $document, Compression $algorithm): string
    {
        $length = \strlen($document);
        if ($length < self::MIN_COMPRESS_SIZE) {
            return $document;
        }
        if ($length > 0xffffffff) {
            throw new BtoonException(ErrorCode::SizeLimit, 'document too large for a compression envelope');
        }
        $payload = Codec::compress($algorithm, $document);
        // Only when the envelope saves at least 5%: 20 × envelope_size ≤ 19 × input_size (SPEC §6).
        if (20 * (\strlen($payload) + self::COMPRESSED_HEADER) > 19 * $length) {
            return $document;
        }

        return self::COMPRESSED_MAGIC . pack('CCnNN', 1, $algorithm->id(), 0, \strlen($payload), $length) . $payload;
    }

    /** @param non-negative-int $limit */
    private static function decompress(string $input, int $limit): string
    {
        /** @var array{version: int<0, 255>, algorithm: int<0, 255>, reserved: int<0, 65535>, compressed: int<0, 4294967295>, uncompressed: int<0, 4294967295>} $header */
        $header = unpack('Cversion/Calgorithm/nreserved/Ncompressed/Nuncompressed', $input, 4);
        if ($header['version'] !== 1) {
            throw new BtoonException(
                ErrorCode::InvalidEnvelope,
                sprintf('unsupported compression envelope version %d', $header['version']),
            );
        }
        if ($header['reserved'] !== 0) {
            throw new BtoonException(ErrorCode::InvalidEnvelope, 'compression envelope reserved bytes must be zero');
        }
        if ($header['compressed'] !== \strlen($input) - self::COMPRESSED_HEADER) {
            throw new BtoonException(ErrorCode::InvalidEnvelope, sprintf(
                'compressed_size %d does not match the %d payload bytes',
                $header['compressed'],
                \strlen($input) - self::COMPRESSED_HEADER,
            ));
        }
        $size = $header['uncompressed'];
        if ($size > $limit) {
            throw new BtoonException(ErrorCode::SizeLimit, sprintf(
                'declared uncompressed size %d exceeds max_decompressed_size %d',
                $size,
                $limit,
            ));
        }
        $algorithm = Compression::fromId($header['algorithm']);
        if ($algorithm === null) {
            throw new BtoonException(
                ErrorCode::UnsupportedCompression,
                sprintf('unknown compression algorithm id %d', $header['algorithm']),
            );
        }
        if (!Codec::isAvailable($algorithm)) {
            throw new BtoonException(ErrorCode::UnsupportedCompression, sprintf(
                'compression algorithm %s is not available in this PHP installation',
                $algorithm->value,
            ));
        }

        return Codec::decompress($algorithm, (string) substr($input, self::COMPRESSED_HEADER), $size);
    }
}

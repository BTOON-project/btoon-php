<?php

declare(strict_types=1);

namespace BTOON\Tests\Support;

use BTOON\Compression;

/** Loads tests/vectors/btoon-vectors.json (a verbatim copy of btoon-core's file). */
final class Vectors
{
    private static ?\stdClass $document = null;

    public static function document(): \stdClass
    {
        if (self::$document === null) {
            $json = file_get_contents(__DIR__ . '/../vectors/btoon-vectors.json');
            if ($json === false) {
                throw new \RuntimeException('cannot read tests/vectors/btoon-vectors.json; run composer sync-vectors');
            }
            $document = json_decode($json, false, 4096, JSON_THROW_ON_ERROR);
            if (!$document instanceof \stdClass) {
                throw new \RuntimeException('unexpected vectors file layout');
            }
            self::$document = $document;
        }

        return self::$document;
    }

    /** @return list<\stdClass> */
    public static function all(): array
    {
        $vectors = self::document()->vectors ?? null;
        if (!\is_array($vectors)) {
            throw new \RuntimeException('unexpected vectors file layout');
        }

        return array_values(array_filter($vectors, static fn (mixed $v): bool => $v instanceof \stdClass));
    }

    public static function bytes(\stdClass $vector): string
    {
        $bytes = hex2bin((string) $vector->hex);
        if ($bytes === false) {
            throw new \RuntimeException("vector {$vector->id} has invalid hex");
        }

        return $bytes;
    }

    /**
     * The vector's decoder options as a PHP option array (SPEC §10): `strict`, `max_depth`,
     * `max_decompressed_size`, `max_values` and `hmac_key`, which is hex in the vectors file.
     * An empty `hmac_key` is an empty key, which the decoder must reject, not "no key".
     * Options a vector doesn't give take the file's `defaults`, given explicitly: the
     * library's own defaults for the size limits shrink when memory_limit is low.
     *
     * @return array<string, mixed>
     */
    public static function options(\stdClass $vector): array
    {
        $options = [];
        $defaults = self::document()->defaults ?? null;
        if (!$defaults instanceof \stdClass) {
            throw new \RuntimeException('the vectors file has no defaults');
        }
        foreach (get_object_vars($defaults) as $name => $value) {
            $options[(string) $name] = $value;
        }
        foreach (get_object_vars($vector->options ?? new \stdClass()) as $name => $value) {
            $options[(string) $name] = $name === 'hmac_key' && \is_string($value) ? self::hex($value) : $value;
        }

        return $options;
    }

    /**
     * The encoder options for re-encoding a canonical vector: its `hmac_key`, if any, since
     * canonical signed vectors are signed on encode. The other options only affect decoding.
     *
     * @return array<string, mixed>
     */
    public static function encodeOptions(\stdClass $vector): array
    {
        $options = self::options($vector);

        return isset($options['hmac_key']) ? ['hmac_key' => $options['hmac_key']] : [];
    }

    private static function hex(string $hex): string
    {
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new \RuntimeException("invalid hex $hex");
        }

        return $bytes;
    }

    /** The codec a vector needs, or null when it needs nothing optional. */
    public static function requiredCodec(\stdClass $vector): ?Compression
    {
        $requires = $vector->requires ?? null;

        return \is_string($requires) ? Compression::from($requires) : null;
    }
}

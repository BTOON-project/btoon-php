<?php

declare(strict_types=1);

namespace BTOON;

/**
 * Static entry points and version constants. `BTOON::encode()` and `BTOON::decode()`
 * are the same as the functions {@see encode()} and {@see decode()}.
 */
final class BTOON
{
    /** Version of this library. */
    public const VERSION = '0.2.0';

    /** Version of the BTOON specification this library implements. */
    public const SPEC_VERSION = '0.2';

    private function __construct()
    {
    }

    /**
     * @param EncodeOptions|array<string, mixed> $options
     * @throws BtoonException
     */
    public static function encode(mixed $value, EncodeOptions|array $options = []): string
    {
        return (new Encoder($options))->encode($value);
    }

    /**
     * @param DecodeOptions|array<string, mixed> $options
     * @throws BtoonException
     */
    public static function decode(string $data, DecodeOptions|array $options = []): mixed
    {
        return (new Decoder($options))->decode($data);
    }
}

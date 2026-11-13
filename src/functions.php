<?php

declare(strict_types=1);

namespace BTOON;

/** Version of this library. */
const VERSION = '0.1.1';

/** Version of the BTOON specification this library implements. */
const SPEC_VERSION = '0.1';

/**
 * Encodes a PHP value as canonical BTOON.
 *
 * @param EncodeOptions|array<string, mixed> $options see {@see EncodeOptions}
 * @throws BtoonException
 */
function encode(mixed $value, EncodeOptions|array $options = []): string
{
    return (new Encoder($options))->encode($value);
}

/**
 * Decodes a BTOON document (bare, compressed or signed).
 *
 * @param DecodeOptions|array<string, mixed> $options see {@see DecodeOptions}
 * @throws BtoonException
 */
function decode(string $data, DecodeOptions|array $options = []): mixed
{
    return (new Decoder($options))->decode($data);
}

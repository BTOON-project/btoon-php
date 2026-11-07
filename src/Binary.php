<?php

declare(strict_types=1);

namespace BTOON;

/**
 * A byte string that must be encoded as BTOON binary, never as a string.
 *
 * PHP strings are byte strings, so the encoder writes valid UTF-8 as `str` and
 * anything else as `bin`. Wrap a value in Binary to force `bin` (for example,
 * bytes that happen to be valid UTF-8). The decoder returns Binary objects for
 * `bin` values when the `binary_as_object` option is set.
 */
final readonly class Binary
{
    public function __construct(public string $data)
    {
    }
}

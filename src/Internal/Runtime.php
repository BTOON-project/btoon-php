<?php

declare(strict_types=1);

namespace BTOON\Internal;

/**
 * @internal
 */
final class Runtime
{
    /** BTOON maps 64-bit integers onto PHP ints, so a 32-bit build can't work correctly. */
    public static function check(): void
    {
        if (\PHP_INT_SIZE < 8) {
            throw new \RuntimeException('BTOON for PHP requires a 64-bit PHP build (PHP_INT_SIZE = 8)');
        }
    }
}

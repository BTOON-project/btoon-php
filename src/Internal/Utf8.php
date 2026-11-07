<?php

declare(strict_types=1);

namespace BTOON\Internal;

/**
 * Well-formed UTF-8 (RFC 3629: no overlongs, no surrogates, nothing above U+10FFFF).
 *
 * @internal
 */
final class Utf8
{
    private static ?bool $mbstring = null;

    public static function isValid(string $value): bool
    {
        if (self::$mbstring ??= \function_exists('mb_check_encoding')) {
            return mb_check_encoding($value, 'UTF-8');
        }

        return preg_match('//u', $value) === 1;
    }

    /** The PCRE validator, used when ext-mbstring is missing (exposed for tests). */
    public static function isValidPcre(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}

<?php

declare(strict_types=1);

namespace BTOON\Internal;

use BTOON\BtoonException;
use BTOON\ErrorCode;

/**
 * Validation helpers for option arrays.
 *
 * @internal
 */
final class Options
{
    public static function bool(string $name, mixed $value): bool
    {
        if (!\is_bool($value)) {
            throw self::invalid($name, 'a bool', $value);
        }

        return $value;
    }

    /** @return non-negative-int */
    public static function nonNegativeInt(string $name, mixed $value): int
    {
        if (!\is_int($value) || $value < 0) {
            throw self::invalid($name, 'a non-negative int', $value);
        }

        return $value;
    }

    /**
     * A resource limit: a non-negative int, or null for the default.
     *
     * @return non-negative-int|null
     */
    public static function limit(string $name, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!\is_int($value) || $value < 0) {
            throw self::invalid($name, 'a non-negative int or null', $value);
        }

        return $value;
    }

    public static function hmacKey(string $name, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw self::invalid($name, 'a binary string or null', $value);
        }
        self::assertKey($value);

        return $value;
    }

    public static function assertKey(?string $key): void
    {
        if ($key === '') {
            // An empty key is almost always a configuration mistake (e.g. a missing env var).
            // Treating it as "no key" would silently disable signature verification.
            throw new BtoonException(ErrorCode::InvalidArgument, 'hmac_key must not be empty (use null for no key)');
        }
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    public static function enum(string $name, mixed $value, string $enum): \BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }
        if (\is_string($value)) {
            $case = $enum::tryFrom(strtolower($value));
            if ($case !== null) {
                return $case;
            }
        }
        $allowed = implode(', ', array_map(static fn (\BackedEnum $c): string => var_export($c->value, true), $enum::cases()));

        throw self::invalid($name, sprintf('one of %s or a %s case', $allowed, $enum), $value);
    }

    /** `max_depth` is at most 1000 (SPEC §7): deeper limits could exhaust the stack. */
    public static function maxDepth(int $value): void
    {
        self::assertNonNegative('max_depth', $value);
        if ($value > Limits::MAX_DEPTH) {
            throw new BtoonException(
                ErrorCode::InvalidArgument,
                sprintf('max_depth must be at most %d, got %d', Limits::MAX_DEPTH, $value),
            );
        }
    }

    public static function assertNonNegative(string $name, int $value): void
    {
        if ($value < 0) {
            throw new BtoonException(ErrorCode::InvalidArgument, sprintf('%s must be >= 0, got %d', $name, $value));
        }
    }

    /** @param list<string> $known */
    public static function unknown(string $name, string $kind, array $known): BtoonException
    {
        return new BtoonException(
            ErrorCode::InvalidArgument,
            sprintf('unknown %s option %s (known: %s)', $kind, var_export($name, true), implode(', ', $known)),
        );
    }

    private static function invalid(string $name, string $expected, mixed $value): BtoonException
    {
        return new BtoonException(
            ErrorCode::InvalidArgument,
            sprintf('option %s must be %s, got %s', $name, $expected, get_debug_type($value)),
        );
    }
}

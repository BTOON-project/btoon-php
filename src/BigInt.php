<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\BigIntMath;

/**
 * An arbitrary-precision integer.
 *
 * The decoder returns BigInt for integers outside PHP's int range: uint64 values
 * above PHP_INT_MAX and bigint extensions (-4) that don't fit in 64 bits. The
 * encoder accepts BigInt (and GMP objects) and picks the canonical form from the
 * value: a MessagePack integer inside [-2^63, 2^64-1], a bigint extension outside.
 *
 * The value is immutable. The decimal string and the two's-complement bytes are
 * computed lazily from each other, so decoding a huge bigint stays linear in its
 * size. Compare values with {@see equals()}, not `==`.
 */
final class BigInt implements \Stringable
{
    private ?string $decimal;
    private ?string $bytes = null;

    /** @param int|string $value an int, or a decimal string such as "-18446744073709551616" */
    public function __construct(int|string $value)
    {
        $this->decimal = \is_int($value) ? (string) $value : self::normalize($value);
    }

    /** From two's-complement big-endian bytes (at least one byte; need not be minimal). */
    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '') {
            throw new BtoonException(ErrorCode::InvalidArgument, 'bigint bytes must not be empty');
        }
        $value = new self(0);
        $value->decimal = null;
        $value->bytes = BigIntMath::minimal($bytes);

        return $value;
    }

    /** From a GMP number (requires ext-gmp). */
    public static function fromGmp(\GMP $value): self
    {
        return new self(gmp_strval($value));
    }

    /** Minimal two's-complement big-endian bytes, as written in a bigint extension. */
    public function toBytes(): string
    {
        if ($this->bytes === null) {
            $this->bytes = BigIntMath::decimalToBytes((string) $this->decimal);
        }

        return $this->bytes;
    }

    /** The canonical decimal representation, e.g. "-123" or "18446744073709551616". */
    public function toString(): string
    {
        if ($this->decimal === null) {
            $this->decimal = BigIntMath::bytesToDecimal((string) $this->bytes);
        }

        return $this->decimal;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** As a GMP number (requires ext-gmp). */
    public function toGmp(): \GMP
    {
        if (!BigIntMath::hasGmp()) {
            throw new BtoonException(ErrorCode::InvalidArgument, 'BigInt::toGmp() requires ext-gmp');
        }

        return gmp_init($this->toString(), 10);
    }

    /** Whether the value fits in a PHP int (signed 64-bit). */
    public function fitsInt(): bool
    {
        return \strlen($this->toBytes()) <= 8;
    }

    /** The value as a PHP int; throws `invalid_argument` if it doesn't fit. */
    public function toInt(): int
    {
        $bytes = $this->toBytes();
        if (\strlen($bytes) > 8) {
            throw new BtoonException(
                ErrorCode::InvalidArgument,
                sprintf('%s does not fit in a 64-bit PHP int', $this->toString()),
            );
        }

        return BigIntMath::bytesToInt($bytes);
    }

    public function isNegative(): bool
    {
        if ($this->decimal !== null) {
            return $this->decimal[0] === '-';
        }

        return (\ord($this->toBytes()[0]) & 0x80) !== 0;
    }

    /** Numeric equality with another BigInt, an int or a decimal string. */
    public function equals(self|int|string $other): bool
    {
        if (!$other instanceof self) {
            $other = new self($other);
        }

        return $this->toBytes() === $other->toBytes();
    }

    private static function normalize(string $value): string
    {
        $sign = $value !== '' && ($value[0] === '-' || $value[0] === '+') ? $value[0] : '';
        $digits = $sign === '' ? $value : substr($value, 1);
        if ($digits === '' || strspn($digits, '0123456789') !== \strlen($digits)) {
            throw new BtoonException(
                ErrorCode::InvalidArgument,
                'BigInt expects an int or a decimal integer string, got ' . var_export(
                    \strlen($value) > 40 ? substr($value, 0, 40) . '…' : $value,
                    true,
                ),
            );
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '0';
        }

        return $sign === '-' ? '-' . $digits : $digits;
    }
}

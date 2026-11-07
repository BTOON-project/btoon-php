<?php

declare(strict_types=1);

namespace BTOON\Internal;

/**
 * Conversions between normalized decimal strings and minimal two's-complement
 * big-endian bytes (the bigint payload of SPEC §5.4).
 *
 * Uses ext-gmp when it is loaded. The pure-PHP fallback works on base-10^9 and
 * base-2^32 limbs, which keeps every intermediate product below 2^63.
 *
 * @internal
 */
final class BigIntMath
{
    private static ?bool $gmp = null;

    public static function hasGmp(): bool
    {
        return self::$gmp ??= \extension_loaded('gmp');
    }

    /**
     * Minimal two's-complement bytes of a normalized decimal string
     * (optional '-', no leading zeros, "0" for zero).
     */
    public static function decimalToBytes(string $decimal, ?bool $useGmp = null): string
    {
        $negative = $decimal[0] === '-';
        $digits = $negative ? substr($decimal, 1) : $decimal;
        $magnitude = ($useGmp ?? self::hasGmp())
            ? gmp_export(gmp_init($digits, 10), 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN)
            : self::decimalToMagnitude($digits);

        return self::fromSignMagnitude($negative, $magnitude);
    }

    /** Normalized decimal string of two's-complement big-endian bytes (any length >= 1). */
    public static function bytesToDecimal(string $bytes, ?bool $useGmp = null): string
    {
        [$negative, $magnitude] = self::toSignMagnitude($bytes);
        $magnitude = ltrim($magnitude, "\x00");
        if ($magnitude === '') {
            return '0';
        }
        $digits = ($useGmp ?? self::hasGmp())
            ? gmp_strval(gmp_import($magnitude, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN))
            : self::magnitudeToDecimal($magnitude);

        return $negative ? '-' . $digits : $digits;
    }

    /**
     * Strips redundant sign-extension bytes: the result never starts with 0x00
     * followed by a byte < 0x80, nor with 0xff followed by a byte >= 0x80.
     */
    public static function minimal(string $bytes): string
    {
        $length = \strlen($bytes);
        if ($length < 2) {
            return $length === 1 ? $bytes : "\x00";
        }
        $first = $bytes[0];
        if ($first === "\x00") {
            $run = strspn($bytes, "\x00");
            if ($run === $length) {
                return "\x00";
            }
            $start = (\ord($bytes[$run]) & 0x80) !== 0 ? $run - 1 : $run;
        } elseif ($first === "\xff") {
            $run = strspn($bytes, "\xff");
            if ($run === $length) {
                return "\xff";
            }
            $start = (\ord($bytes[$run]) & 0x80) !== 0 ? $run : $run - 1;
        } else {
            return $bytes;
        }

        return $start > 0 ? substr($bytes, $start) : $bytes;
    }

    public static function intToBytes(int $value): string
    {
        return self::minimal(pack('J', $value));
    }

    /** Interprets 1…8 two's-complement big-endian bytes as a PHP int. */
    public static function bytesToInt(string $bytes): int
    {
        $pad = (\ord($bytes[0]) & 0x80) !== 0 ? "\xff" : "\x00";
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', str_pad($bytes, 8, $pad, STR_PAD_LEFT));

        return $unpacked[1];
    }

    /** Big-endian magnitude bytes (possibly empty for zero) of a string of decimal digits. */
    public static function decimalToMagnitude(string $digits): string
    {
        $length = \strlen($digits);
        $head = $length % 9 ?: 9;
        $limbs = [(int) substr($digits, 0, $head)];
        for ($i = $head; $i < $length; $i += 9) {
            $limbs[] = (int) substr($digits, $i, 9);
        }
        if ($limbs[0] === 0 && \count($limbs) === 1) {
            return '';
        }

        // Repeated division by 2^32: each pass yields the next least-significant 32 bits.
        $words = [];
        while ($limbs !== []) {
            $remainder = 0;
            $quotient = [];
            foreach ($limbs as $limb) {
                $current = $remainder * 1_000_000_000 + $limb;
                $q = $current >> 32;
                $remainder = $current & 0xffffffff;
                if ($q !== 0 || $quotient !== []) {
                    $quotient[] = $q;
                }
            }
            $words[] = pack('N', $remainder);
            $limbs = $quotient;
        }

        return ltrim(implode('', array_reverse($words)), "\x00");
    }

    /** Decimal digits of non-empty big-endian magnitude bytes without leading zero bytes. */
    public static function magnitudeToDecimal(string $magnitude): string
    {
        $pad = (4 - \strlen($magnitude) % 4) % 4;
        /** @var array<int, int> $unpacked */
        $unpacked = unpack('N*', str_repeat("\x00", $pad) . $magnitude);
        $limbs = array_values($unpacked);

        // Repeated division by 10^9: each pass yields the next nine decimal digits.
        $chunks = [];
        while ($limbs !== []) {
            $remainder = 0;
            $quotient = [];
            foreach ($limbs as $limb) {
                $current = ($remainder << 32) | $limb;
                $q = intdiv($current, 1_000_000_000);
                $remainder = $current - $q * 1_000_000_000;
                if ($q !== 0 || $quotient !== []) {
                    $quotient[] = $q;
                }
            }
            $chunks[] = $remainder;
            $limbs = $quotient;
        }

        $decimal = (string) array_pop($chunks);
        while ($chunks !== []) {
            $decimal .= str_pad((string) array_pop($chunks), 9, '0', STR_PAD_LEFT);
        }

        return $decimal;
    }

    /** Minimal two's-complement encoding of a sign and a big-endian magnitude. */
    private static function fromSignMagnitude(bool $negative, string $magnitude): string
    {
        $magnitude = ltrim($magnitude, "\x00");
        if (!$negative || $magnitude === '') {
            if ($magnitude === '') {
                return "\x00";
            }

            return (\ord($magnitude[0]) & 0x80) !== 0 ? "\x00" . $magnitude : $magnitude;
        }
        // -m in two's complement is ~(m - 1); prefix a zero byte so the sign bit ends up set.
        $lessOne = ltrim(self::decrement($magnitude), "\x00");
        if ($lessOne === '' || (\ord($lessOne[0]) & 0x80) !== 0) {
            $lessOne = "\x00" . $lessOne;
        }

        return ~$lessOne;
    }

    /**
     * Sign and big-endian magnitude of two's-complement bytes.
     *
     * @return array{bool, string}
     */
    private static function toSignMagnitude(string $bytes): array
    {
        if ((\ord($bytes[0]) & 0x80) === 0) {
            return [false, $bytes];
        }

        return [true, self::increment(~$bytes)];
    }

    /** $bytes - 1 for a big-endian magnitude > 0 (same length). */
    private static function decrement(string $bytes): string
    {
        $i = \strlen($bytes) - 1;
        while ($i >= 0 && $bytes[$i] === "\x00") {
            $bytes[$i] = "\xff";
            --$i;
        }
        if ($i >= 0) {
            $bytes[$i] = \chr(\ord($bytes[$i]) - 1);
        }

        return $bytes;
    }

    /** $bytes + 1 for a big-endian magnitude (grows by one byte on overflow). */
    private static function increment(string $bytes): string
    {
        $i = \strlen($bytes) - 1;
        while ($i >= 0 && $bytes[$i] === "\xff") {
            $bytes[$i] = "\x00";
            --$i;
        }
        if ($i < 0) {
            return "\x01" . $bytes;
        }
        $bytes[$i] = \chr(\ord($bytes[$i]) + 1);

        return $bytes;
    }
}

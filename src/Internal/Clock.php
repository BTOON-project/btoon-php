<?php

declare(strict_types=1);

namespace BTOON\Internal;

use BTOON\BtoonException;
use BTOON\ErrorCode;

/**
 * Date/time helpers shared by the value classes and the decoder.
 *
 * @internal
 */
final class Clock
{
    private static ?\DateTimeZone $utc = null;
    private static ?\DateTimeImmutable $epoch = null;
    /** @var array<int, \DateTimeZone> */
    private static array $offsets = [];

    public static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }

    /**
     * Builds a `\DateTimeImmutable` from Unix seconds and a microsecond fraction (0…999999).
     * Uses setTimestamp()/setTime(), which behave the same for negative instants on every
     * supported PHP version.
     */
    public static function fromParts(int $seconds, int $micros, ?int $offsetMinutes = null): \DateTimeImmutable
    {
        self::$epoch ??= (new \DateTimeImmutable('@0'))->setTimezone(self::utc());
        $dateTime = self::$epoch->setTimestamp($seconds);
        if ($micros !== 0) {
            $secondOfDay = $seconds % 86400;
            if ($secondOfDay < 0) {
                $secondOfDay += 86400;
            }
            $dateTime = $dateTime->setTime(
                intdiv($secondOfDay, 3600),
                intdiv($secondOfDay % 3600, 60),
                $secondOfDay % 60,
                $micros,
            );
        }
        if ($offsetMinutes !== null) {
            $dateTime = $dateTime->setTimezone(self::$offsets[$offsetMinutes] ??= new \DateTimeZone(sprintf(
                '%s%02d:%02d',
                $offsetMinutes < 0 ? '-' : '+',
                intdiv(abs($offsetMinutes), 60),
                abs($offsetMinutes) % 60,
            )));
        }

        return $dateTime;
    }

    /**
     * Floor division: returns [quotient, remainder] with 0 <= remainder < $divisor.
     *
     * @param positive-int $divisor
     * @return array{int, int}
     */
    public static function floorDivMod(int $value, int $divisor): array
    {
        $quotient = intdiv($value, $divisor);
        $remainder = $value % $divisor;
        if ($remainder < 0) {
            $remainder += $divisor;
            --$quotient;
        }

        return [$quotient, $remainder];
    }

    /**
     * `$seconds * $unit + $fraction` with an overflow check (0 <= $fraction < $unit). Every
     * result in the 64-bit range is accepted, PHP_INT_MIN included.
     *
     * @param positive-int $unit
     */
    public static function combine(int $seconds, int $fraction, int $unit, string $what): int
    {
        if ($seconds >= 0) {
            if ($seconds <= intdiv(PHP_INT_MAX - $fraction, $unit)) {
                return $seconds * $unit + $fraction;
            }
        } else {
            // The earliest count is floor(PHP_INT_MIN / unit) seconds plus a fraction, so that
            // second is in range only from that fraction on. (seconds + 1) × unit can't
            // overflow, and taking (unit - fraction) away from it then stays in range.
            [$minSeconds, $minFraction] = self::floorDivMod(PHP_INT_MIN, $unit);
            if ($seconds > $minSeconds || ($seconds === $minSeconds && $fraction >= $minFraction)) {
                return ($seconds + 1) * $unit - ($unit - $fraction);
            }
        }

        throw new BtoonException(
            ErrorCode::InvalidArgument,
            sprintf('instant is outside the range of a BTOON %s (64-bit count since 1970)', $what),
        );
    }
}

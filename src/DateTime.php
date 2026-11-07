<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\Clock;

/**
 * BTOON datetime, extension -3 (SPEC §5.3): nanoseconds since the Unix epoch, UTC
 * (1677-09-21 to 2262-04-11). Not related to PHP's `\DateTime`.
 *
 * Encode a BTOON\DateTime to write this type explicitly; PHP `DateTimeInterface`
 * values are written as timestamps (-1). The decoder returns BTOON\DateTime objects
 * with the `timestamps => 'object'` option.
 */
final readonly class DateTime
{
    public function __construct(public int $nanoseconds)
    {
    }

    /** The instant of `$dateTime` (microsecond precision). */
    public static function fromDateTime(\DateTimeInterface $dateTime): self
    {
        $nanos = (int) $dateTime->format('u') * 1000;

        return new self(Clock::combine($dateTime->getTimestamp(), $nanos, 1_000_000_000, 'datetime'));
    }

    /** This instant as a `\DateTimeImmutable` in UTC (truncated to microseconds). */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        [$seconds, $nanos] = Clock::floorDivMod($this->nanoseconds, 1_000_000_000);

        return Clock::fromParts($seconds, intdiv($nanos, 1000));
    }
}

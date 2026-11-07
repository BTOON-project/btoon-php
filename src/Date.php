<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\Clock;

/**
 * BTOON date, extension -2 (SPEC §5.2): milliseconds since the Unix epoch, UTC.
 *
 * Encode a Date to write this type explicitly; PHP `DateTimeInterface` values are
 * written as timestamps (-1). The decoder returns Date objects with the
 * `timestamps => 'object'` option.
 */
final readonly class Date
{
    public function __construct(public int $milliseconds)
    {
    }

    /** The instant of `$dateTime`, truncated to milliseconds. */
    public static function fromDateTime(\DateTimeInterface $dateTime): self
    {
        $millis = intdiv((int) $dateTime->format('u'), 1000);

        return new self(Clock::combine($dateTime->getTimestamp(), $millis, 1000, 'date'));
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        [$seconds, $millis] = Clock::floorDivMod($this->milliseconds, 1000);

        return Clock::fromParts($seconds, $millis * 1000);
    }
}

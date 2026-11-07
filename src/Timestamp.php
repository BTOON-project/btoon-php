<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\Clock;

/**
 * An instant with nanosecond precision, BTOON extension -1 (SPEC §5.1).
 *
 * `seconds` and `nanoseconds` always describe the instant in UTC. `offsetMinutes`
 * is optional presentation metadata (local time = UTC + offset); only timestamps
 * that carry it use the BTOON-only 14-byte form. PHP `DateTimeInterface` values
 * encode as plain UTC timestamps; use {@see fromDateTime()} with `$keepOffset` to
 * keep the offset.
 */
final readonly class Timestamp
{
    public const MAX_OFFSET_MINUTES = 1080;

    public function __construct(
        public int $seconds,
        public int $nanoseconds = 0,
        public ?int $offsetMinutes = null,
    ) {
        if ($nanoseconds < 0 || $nanoseconds > 999_999_999) {
            throw new BtoonException(
                ErrorCode::InvalidArgument,
                sprintf('timestamp nanoseconds must be within 0…999999999, got %d', $nanoseconds),
            );
        }
        if ($offsetMinutes !== null && abs($offsetMinutes) > self::MAX_OFFSET_MINUTES) {
            throw new BtoonException(
                ErrorCode::InvalidArgument,
                sprintf('timestamp offset must be within ±%d minutes, got %d', self::MAX_OFFSET_MINUTES, $offsetMinutes),
            );
        }
    }

    /**
     * The same instant as `$dateTime`, with microsecond precision.
     *
     * @param bool $keepOffset also record the value's UTC offset (14-byte form)
     */
    public static function fromDateTime(\DateTimeInterface $dateTime, bool $keepOffset = false): self
    {
        $offset = null;
        if ($keepOffset) {
            $seconds = $dateTime->getOffset();
            if ($seconds % 60 !== 0) {
                throw new BtoonException(
                    ErrorCode::InvalidArgument,
                    sprintf('UTC offset of %d seconds is not a whole number of minutes', $seconds),
                );
            }
            $offset = intdiv($seconds, 60);
        }

        return new self($dateTime->getTimestamp(), (int) $dateTime->format('u') * 1000, $offset);
    }

    /**
     * This instant as a `\DateTimeImmutable` (nanoseconds are truncated to microseconds).
     * The time zone is UTC, or a fixed offset such as `+02:00` when the timestamp has one.
     */
    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        return Clock::fromParts($this->seconds, intdiv($this->nanoseconds, 1000), $this->offsetMinutes);
    }
}

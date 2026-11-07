<?php

declare(strict_types=1);

namespace BTOON;

/**
 * How the decoder returns the temporal extension types (timestamp, date, datetime).
 */
enum TimestampMode: string
{
    /**
     * `\DateTimeImmutable` (the default). Timestamps with a UTC offset get a fixed-offset
     * time zone; everything else is in UTC. Precision is limited to microseconds.
     */
    case DateTime = 'datetime';

    /** Lossless {@see Timestamp}, {@see Date} and {@see DateTime} value objects. */
    case Object = 'object';
}

<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\BigIntMath;
use BTOON\Internal\Envelope;
use BTOON\Internal\Runtime;
use BTOON\Internal\Utf8;

/**
 * Canonical BTOON encoder (SPEC §4–§5).
 *
 * Every value has exactly one encoding: integers use the smallest format for their
 * value, floats are always float64 with NaN canonicalized, map keys are sorted by
 * their UTF-8 bytes, arrays of uniform maps use the tabular encoding exactly when
 * the size rule of SPEC §5.6.2 says so, and timestamps follow the MessagePack
 * selection algorithm. The same value therefore produces byte-identical output in
 * every BTOON implementation.
 *
 * PHP type mapping:
 * - `null`, `bool`, `int`, `float` → nil, bool, integer, float64
 * - `string` → str when it is valid UTF-8, otherwise bin; {@see Binary} forces bin
 * - list arrays (including `[]`) → array; other arrays → map (keys become strings)
 * - `stdClass`, `ArrayObject`, `ArrayIterator` → map (also when empty)
 * - `JsonSerializable` → whatever `jsonSerialize()` returns; `BackedEnum` → its value
 * - `DateTimeInterface` → UTC timestamp (microsecond precision)
 * - {@see BigInt}, `GMP` → integer, or bigint outside [-2^63, 2^64-1]
 * - {@see Timestamp}, {@see Date}, {@see DateTime}, {@see VectorF32}, {@see VectorF64}, {@see Ext}
 *
 * Anything else (resources, closures, other objects, pure enums) raises `invalid_argument`.
 */
final class Encoder
{
    // Tabular type codes (SPEC §5.6.1), returned by value() for the value just written.
    private const T_NIL = 0;
    private const T_BOOL = 1;
    private const T_INT = 2;
    private const T_UINT64 = 3;
    private const T_FLOAT = 4;
    private const T_STRING = 5;
    private const T_BINARY = 6;
    private const T_ARRAY = 7;
    private const T_MAP = 8;
    private const T_OTHER = 9;

    private const NAN = "\xcb\x7f\xf8\x00\x00\x00\x00\x00\x00";

    /** Extension ids that BTOON defines (SPEC §5); a generic Ext must not use them. */
    private const DEFINED_EXT_TYPES = [-1 => 'Timestamp', -2 => 'Date', -3 => 'DateTime', -4 => 'BigInt',
        -5 => 'VectorF32', -6 => 'VectorF64', -10 => 'tabular arrays'];

    private const KEY_CACHE_LIMIT = 4096;

    private readonly EncodeOptions $options;
    private readonly int $maxDepth;
    private readonly bool $autoTabular;
    private string $buffer = '';
    /** @var array<array-key, string> encoded map keys (header + bytes) */
    private array $keys = [];

    /** @param EncodeOptions|array<string, mixed> $options */
    public function __construct(EncodeOptions|array $options = [])
    {
        Runtime::check();
        $this->options = $options instanceof EncodeOptions ? $options : EncodeOptions::fromArray($options);
        $this->maxDepth = $this->options->maxDepth;
        $this->autoTabular = $this->options->autoTabular;
        if ($this->options->compress && !$this->options->compression->isAvailable()) {
            throw new BtoonException(
                ErrorCode::UnsupportedCompression,
                sprintf('compression algorithm %s is not available in this PHP installation', $this->options->compression->value),
            );
        }
    }

    public function getOptions(): EncodeOptions
    {
        return $this->options;
    }

    /**
     * Encodes a value, then applies the compression and signed envelopes the options ask for.
     *
     * @throws BtoonException
     */
    public function encode(mixed $value): string
    {
        // Save the buffer so that encode() stays reentrant (e.g. from jsonSerialize()).
        $outer = $this->buffer;
        $this->buffer = '';
        try {
            $this->value($value, 0);
            $document = $this->buffer;
        } finally {
            $this->buffer = $outer;
        }

        return Envelope::seal($document, $this->options);
    }

    /** Writes one value and returns its tabular type code. */
    private function value(mixed $value, int $depth): int
    {
        if (\is_string($value)) {
            return $this->string($value);
        }
        if (\is_int($value)) {
            $this->buffer .= $value >= 0 && $value <= 0x7f ? \chr($value) : self::int($value);

            return self::T_INT;
        }
        if (\is_array($value)) {
            return array_is_list($value) ? $this->list($value, $depth) : $this->map($value, $depth);
        }
        if (\is_float($value)) {
            $this->buffer .= is_nan($value) ? self::NAN : "\xcb" . pack('E', $value);

            return self::T_FLOAT;
        }
        if (\is_bool($value)) {
            $this->buffer .= $value ? "\xc3" : "\xc2";

            return self::T_BOOL;
        }
        if ($value === null) {
            $this->buffer .= "\xc0";

            return self::T_NIL;
        }
        if (\is_object($value)) {
            return $this->object($value, $depth);
        }

        throw new BtoonException(ErrorCode::InvalidArgument, 'cannot encode a value of type ' . get_debug_type($value));
    }

    private function string(string $value): int
    {
        $length = \strlen($value);
        if ($length === 0) {
            $this->buffer .= "\xa0";

            return self::T_STRING;
        }
        if (!Utf8::isValid($value)) {
            // Strict decoders reject invalid UTF-8 in str, so such bytes are binary (guide §5).
            $this->binary($value);

            return self::T_BINARY;
        }
        if ($length <= 31) {
            $this->buffer .= \chr(0xa0 | $length) . $value;

            return self::T_STRING;
        }
        $this->buffer .= self::strHeader($length);
        $this->buffer .= $value;

        return self::T_STRING;
    }

    private function binary(string $value): void
    {
        $length = \strlen($value);
        if ($length <= 0xff) {
            $this->buffer .= "\xc4" . \chr($length) . $value;

            return;
        }
        $this->buffer .= match (true) {
            $length <= 0xffff => "\xc5" . pack('n', $length),
            $length <= 0xffffffff => "\xc6" . pack('N', $length),
            default => throw new BtoonException(ErrorCode::SizeLimit, 'binary longer than 2^32-1 bytes'),
        };
        $this->buffer .= $value;
    }

    /** @param list<mixed> $list */
    private function list(array $list, int $depth): int
    {
        if ($depth >= $this->maxDepth) {
            throw self::tooDeep($this->maxDepth);
        }
        $count = \count($list);
        $resolved = [];
        if ($count >= 2 && $this->autoTabular && (\is_array($list[0]) || \is_object($list[0]))
            && $this->tabular($list, $count, $depth, $resolved)) {
            return self::T_ARRAY;
        }

        $this->buffer .= match (true) {
            $count <= 15 => \chr(0x90 | $count),
            $count <= 0xffff => "\xdc" . pack('n', $count),
            $count <= 0xffffffff => "\xdd" . pack('N', $count),
            default => throw new BtoonException(ErrorCode::SizeLimit, 'array longer than 2^32-1 elements'),
        };
        if ($resolved === []) {
            foreach ($list as $item) {
                $this->value($item, $depth + 1);
            }

            return self::T_ARRAY;
        }
        // The tabular check already called jsonSerialize() on a prefix; reuse those results.
        $done = \count($resolved);
        foreach ($resolved as $item) {
            $this->value($item, $depth + 1);
        }
        for ($i = $done; $i < $count; ++$i) {
            $this->value($list[$i], $depth + 1);
        }

        return self::T_ARRAY;
    }

    /** @param array<array-key, mixed> $map */
    private function map(array $map, int $depth): int
    {
        if ($depth >= $this->maxDepth) {
            throw self::tooDeep($this->maxDepth);
        }
        $count = \count($map);
        $this->buffer .= match (true) {
            $count <= 15 => \chr(0x80 | $count),
            $count <= 0xffff => "\xde" . pack('n', $count),
            $count <= 0xffffffff => "\xdf" . pack('N', $count),
            default => throw new BtoonException(ErrorCode::SizeLimit, 'map larger than 2^32-1 entries'),
        };
        if ($count > 1) {
            // Canonical order: keys compared as strings, bytewise (SPEC §4.4). PHP turns
            // integer-like string keys into ints; SORT_STRING compares their string form.
            ksort($map, SORT_STRING);
        }
        foreach ($map as $key => $item) {
            if (isset($this->keys[$key])) {
                $this->buffer .= $this->keys[$key];
            } else {
                if (\count($this->keys) >= self::KEY_CACHE_LIMIT) {
                    $this->keys = []; // bounded: records with recurring keys refill it quickly
                }
                $this->buffer .= $this->keys[$key] = self::key($key);
            }
            $this->value($item, $depth + 1);
        }

        return self::T_MAP;
    }

    /**
     * Tabular encoding (SPEC §5.6) when the list qualifies and the size rule picks it.
     *
     * @param list<mixed> $list
     * @param list<mixed> $resolved receives the elements examined so far, after
     *                              JsonSerializable resolution, so a fallback to a plain
     *                              array doesn't call jsonSerialize() twice
     */
    private function tabular(array $list, int $count, int $depth, array &$resolved): bool
    {
        $rows = [];
        $first = [];
        $columns = 0;
        foreach ($list as $index => $item) {
            if (\is_object($item)) {
                $item = $this->resolve($item);
            }
            $resolved[] = $item;
            $entries = self::mapEntries($item);
            if ($entries === null) {
                return false;
            }
            if ($index === 0) {
                $columns = \count($entries);
                if ($columns === 0) {
                    return false;
                }
                $first = $entries;
            } elseif (\count($entries) !== $columns || array_diff_key($first, $entries) !== []) {
                return false;
            }
            $rows[] = $entries;
        }

        // Size rule (SPEC §5.6.2): N × (maphdr(C) + Σ(strhdr(len k) + len k)) > 18 + Σ(9 + len k)
        $keys = [];
        foreach ($first as $key => $_) {
            $keys[] = (string) $key;
        }
        sort($keys, SORT_STRING);
        $perRow = $columns <= 15 ? 1 : ($columns <= 0xffff ? 3 : 5);
        $overhead = 18;
        foreach ($keys as $key) {
            $length = \strlen($key);
            $perRow += ($length <= 31 ? 1 : ($length <= 0xff ? 2 : ($length <= 0xffff ? 3 : 5))) + $length;
            $overhead += 9 + $length;
        }
        if ($perRow <= intdiv($overhead, $count)) { // ⇔ N × perRow <= overhead, without overflow
            return false;
        }
        if ($depth + 1 >= $this->maxDepth) {
            throw self::tooDeep($this->maxDepth);
        }
        if ($count > 0xffffffff || $columns > 0xffffffff) {
            return false;
        }

        $payload = pack('NNN', 1, $columns, $count);
        foreach ($keys as $key) {
            if (!Utf8::isValid($key)) {
                throw new BtoonException(ErrorCode::InvalidUtf8, 'map key is not valid UTF-8: 0x' . bin2hex(substr($key, 0, 32)));
            }
            $payload .= pack('N', \strlen($key)) . $key;
        }

        $outer = $this->buffer;
        $codes = '';
        $data = '';
        foreach ($keys as $key) {
            $this->buffer = '';
            $code = self::T_NIL;
            foreach ($rows as $row => $entries) {
                $type = $this->value($entries[$key], $depth + 2);
                if ($row === 0) {
                    $code = $type;
                }
            }
            $codes .= \chr($code);
            // A column over 4 GiB would make the payload too, which extHeader() rejects.
            $data .= pack('N', \strlen($this->buffer)) . $this->buffer;
        }
        $this->buffer = $outer;
        unset($outer);
        $payload .= $codes;
        $payload .= $data;
        $this->buffer .= self::extHeader(-10, \strlen($payload));
        $this->buffer .= $payload;

        return true;
    }

    private function object(object $value, int $depth): int
    {
        if ($value instanceof Binary) {
            $this->binary($value->data);

            return self::T_BINARY;
        }
        if ($value instanceof BigInt) {
            return $this->bigint($value->toBytes());
        }
        if ($value instanceof Timestamp) {
            $this->timestamp($value->seconds, $value->nanoseconds, $value->offsetMinutes);

            return self::T_OTHER;
        }
        if ($value instanceof \DateTimeInterface) {
            // Ordinary datetimes are normalized to plain UTC timestamps (SPEC §5.1).
            $this->timestamp($value->getTimestamp(), (int) $value->format('u') * 1000, null);

            return self::T_OTHER;
        }
        if ($value instanceof Ext) {
            if (isset(self::DEFINED_EXT_TYPES[$value->type])) {
                throw new BtoonException(ErrorCode::InvalidArgument, sprintf(
                    'extension type %d is defined by BTOON; encode a BTOON\\%s value instead of an Ext',
                    $value->type,
                    self::DEFINED_EXT_TYPES[$value->type],
                ));
            }
            $this->buffer .= self::extHeader($value->type, \strlen($value->data));
            $this->buffer .= $value->data;

            return self::T_OTHER;
        }
        if ($value instanceof Date) {
            $this->buffer .= "\xd7\xfe" . pack('J', $value->milliseconds);

            return self::T_OTHER;
        }
        if ($value instanceof DateTime) {
            $this->buffer .= "\xd7\xfd" . pack('J', $value->nanoseconds);

            return self::T_OTHER;
        }
        if ($value instanceof VectorF32 || $value instanceof VectorF64) {
            $bytes = $value->toBytes();
            $this->buffer .= self::extHeader($value instanceof VectorF32 ? -5 : -6, \strlen($bytes));
            $this->buffer .= $bytes;

            return self::T_OTHER;
        }
        if ($value instanceof \GMP) {
            return $this->bigint(BigInt::fromGmp($value)->toBytes());
        }
        if ($value instanceof \JsonSerializable) {
            return $this->value($this->resolve($value), $depth);
        }
        if ($value instanceof \BackedEnum) {
            return $this->value($value->value, $depth);
        }
        if ($value instanceof \UnitEnum) {
            throw new BtoonException(ErrorCode::InvalidArgument, sprintf(
                'cannot encode the pure enum case %s::%s; use a backed enum',
                $value::class,
                $value->name,
            ));
        }
        if ($value instanceof \stdClass) {
            return $this->map(get_object_vars($value), $depth);
        }
        if ($value instanceof \ArrayObject || $value instanceof \ArrayIterator) {
            return $this->map($value->getArrayCopy(), $depth);
        }

        throw new BtoonException(ErrorCode::InvalidArgument, sprintf(
            'cannot encode an object of class %s; implement JsonSerializable or pass an array',
            $value::class,
        ));
    }

    /** Integers in [-2^63, 2^64-1] use MessagePack integers; others use bigint (SPEC §5.4). */
    private function bigint(string $bytes): int
    {
        $length = \strlen($bytes);
        if ($length <= 8) {
            $this->buffer .= self::int(BigIntMath::bytesToInt($bytes));

            return self::T_INT;
        }
        if ($length === 9 && $bytes[0] === "\x00") {
            $this->buffer .= "\xcf" . substr($bytes, 1);

            return self::T_UINT64;
        }
        $this->buffer .= self::extHeader(-4, $length);
        $this->buffer .= $bytes;

        return self::T_OTHER;
    }

    /** MessagePack timestamp selection plus the BTOON 14-byte offset form (SPEC §5.1). */
    private function timestamp(int $seconds, int $nanos, ?int $offsetMinutes): void
    {
        if ($offsetMinutes !== null) {
            $this->buffer .= "\xc7\x0e\xff" . pack('NJn', $nanos, $seconds, $offsetMinutes & 0xffff);
        } elseif (($seconds >> 34) === 0) {
            $data64 = ($nanos << 34) | $seconds;
            $this->buffer .= ($data64 >> 32) === 0 ? "\xd6\xff" . pack('N', $data64) : "\xd7\xff" . pack('J', $data64);
        } else {
            $this->buffer .= "\xc7\x0c\xff" . pack('NJ', $nanos, $seconds);
        }
    }

    /** Unwraps JsonSerializable objects (never a DateTimeInterface, which encodes as a timestamp). */
    private function resolve(object $value): mixed
    {
        $hops = 0;
        while ($value instanceof \JsonSerializable && !$value instanceof \DateTimeInterface) {
            if (++$hops > $this->maxDepth) {
                throw new BtoonException(ErrorCode::MaxDepth, 'jsonSerialize() keeps returning JsonSerializable objects');
            }
            $value = $value->jsonSerialize();
            if (!\is_object($value)) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * The entries of a value that encodes as a map, or null if it doesn't.
     *
     * @return array<array-key, mixed>|null
     */
    private static function mapEntries(mixed $value): ?array
    {
        if (\is_array($value)) {
            return array_is_list($value) ? null : $value;
        }
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }
        if ($value instanceof \ArrayObject || $value instanceof \ArrayIterator) {
            return $value->getArrayCopy();
        }

        return null;
    }

    private static function int(int $value): string
    {
        if ($value >= 0) {
            return match (true) {
                $value <= 0x7f => \chr($value),
                $value <= 0xff => "\xcc" . \chr($value),
                $value <= 0xffff => "\xcd" . pack('n', $value),
                $value <= 0xffffffff => "\xce" . pack('N', $value),
                default => "\xcf" . pack('J', $value),
            };
        }

        return match (true) {
            $value >= -32 => \chr($value & 0xff),
            $value >= -128 => "\xd0" . \chr($value & 0xff),
            $value >= -32768 => "\xd1" . pack('n', $value & 0xffff),
            $value >= -2147483648 => "\xd2" . pack('N', $value & 0xffffffff),
            default => "\xd3" . pack('J', $value),
        };
    }

    private static function key(int|string $key): string
    {
        $key = (string) $key;
        if (!Utf8::isValid($key)) {
            throw new BtoonException(ErrorCode::InvalidUtf8, 'map key is not valid UTF-8: 0x' . bin2hex(substr($key, 0, 32)));
        }
        $length = \strlen($key);

        return ($length <= 31 ? \chr(0xa0 | $length) : self::strHeader($length)) . $key;
    }

    private static function strHeader(int $length): string
    {
        return match (true) {
            $length <= 31 => \chr(0xa0 | $length),
            $length <= 0xff => "\xd9" . \chr($length),
            $length <= 0xffff => "\xda" . pack('n', $length),
            $length <= 0xffffffff => "\xdb" . pack('N', $length),
            default => throw new BtoonException(ErrorCode::SizeLimit, 'string longer than 2^32-1 bytes'),
        };
    }

    private static function extHeader(int $type, int $length): string
    {
        $type = \chr($type & 0xff);

        return match (true) {
            $length === 1 => "\xd4" . $type,
            $length === 2 => "\xd5" . $type,
            $length === 4 => "\xd6" . $type,
            $length === 8 => "\xd7" . $type,
            $length === 16 => "\xd8" . $type,
            $length <= 0xff => "\xc7" . \chr($length) . $type,
            $length <= 0xffff => "\xc8" . pack('n', $length) . $type,
            $length <= 0xffffffff => "\xc9" . pack('N', $length) . $type,
            default => throw new BtoonException(ErrorCode::SizeLimit, 'extension payload longer than 2^32-1 bytes'),
        };
    }

    private static function tooDeep(int $maxDepth): BtoonException
    {
        return new BtoonException(
            ErrorCode::MaxDepth,
            sprintf('nesting deeper than max_depth (%d); cyclic data also ends here', $maxDepth),
        );
    }
}

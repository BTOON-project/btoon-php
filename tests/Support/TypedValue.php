<?php

declare(strict_types=1);

namespace BTOON\Tests\Support;

use BTOON\BigInt;
use BTOON\Binary;
use BTOON\Date;
use BTOON\DateTime;
use BTOON\Ext;
use BTOON\Timestamp;
use BTOON\VectorF32;
use BTOON\VectorF64;

/**
 * The typed JSON form of the conformance vectors (SPEC §10): building PHP values
 * from it, and comparing decoded PHP values against it.
 *
 * JSON must be decoded with objects as stdClass (not associative arrays), so that
 * `{"map": {}}` and `{"array": []}` stay distinguishable.
 */
final class TypedValue
{
    /** The PHP value that must encode to this typed value. */
    public static function build(\stdClass $typed): mixed
    {
        [$kind, $value] = self::unpack($typed);

        return match ($kind) {
            'nil' => null,
            'bool' => (bool) $value,
            'int' => self::int(self::str($value)),
            'float' => self::float(self::str($value)),
            'string' => self::str($value),
            'binary' => new Binary(self::hex($value)),
            'array' => array_map(self::build(...), self::typedList($value)),
            'map' => self::map(self::object($value)),
            'timestamp' => self::timestamp(self::object($value)),
            'date' => new Date((int) self::str($value)),
            'datetime' => new DateTime((int) self::str($value)),
            'float32_vector' => new VectorF32(array_map(self::float(...), self::stringList($value))),
            'float64_vector' => new VectorF64(array_map(self::float(...), self::stringList($value))),
            'ext' => new Ext(self::intField(self::object($value), 'type'), self::hex(self::object($value)->data ?? '')),
            default => throw new \UnexpectedValueException("unknown typed value kind $kind"),
        };
    }

    /**
     * Compares a decoded PHP value with the expected typed value.
     *
     * @param bool $objects whether the value was decoded with `timestamps => 'object'`
     *                      and `binary_as_object => true`
     * @return string|null null when equal, otherwise where and how they differ
     */
    public static function diff(\stdClass $typed, mixed $actual, bool $objects, string $path = '$'): ?string
    {
        [$kind, $expected] = self::unpack($typed);
        $got = self::describe($actual);
        $fail = static fn (string $why): string => "$path: $why (got $got)";

        switch ($kind) {
            case 'nil':
                return $actual === null ? null : $fail('expected nil');
            case 'bool':
                return $actual === $expected ? null : $fail('expected ' . var_export($expected, true));
            case 'int':
                $decimal = self::str($expected);
                if (\is_int($actual)) {
                    return (string) $actual === $decimal ? null : $fail("expected integer $decimal");
                }
                if ($actual instanceof BigInt) {
                    if ($actual->fitsInt()) {
                        return $fail('a BigInt that fits in a PHP int should have been an int');
                    }

                    return $actual->toString() === $decimal ? null : $fail("expected integer $decimal");
                }

                return $fail("expected integer $decimal");
            case 'float':
                $want = self::float(self::str($expected));
                if (!\is_float($actual)) {
                    return $fail('expected a float');
                }
                if (is_nan($want)) {
                    return is_nan($actual) ? null : $fail('expected NaN');
                }

                return pack('E', $actual) === pack('E', $want) ? null : $fail('expected float ' . self::str($expected));
            case 'string':
                return $actual === $expected ? null : $fail('expected string ' . var_export($expected, true));
            case 'binary':
                $bytes = self::hex($expected);
                if ($objects) {
                    return $actual instanceof Binary && $actual->data === $bytes ? null : $fail('expected Binary 0x' . bin2hex($bytes));
                }

                return $actual === $bytes ? null : $fail('expected binary string 0x' . bin2hex($bytes));
            case 'array':
                $items = self::typedList($expected);
                if (!\is_array($actual) || !array_is_list($actual) || \count($actual) !== \count($items)) {
                    return $fail(sprintf('expected a list of %d elements', \count($items)));
                }
                foreach ($items as $i => $item) {
                    $diff = self::diff($item, $actual[$i], $objects, "{$path}[$i]");
                    if ($diff !== null) {
                        return $diff;
                    }
                }

                return null;
            case 'map':
                // PHP maps are arrays; integer-like keys come back as int keys.
                $entries = get_object_vars(self::object($expected));
                if (!\is_array($actual) || \count($actual) !== \count($entries)) {
                    return $fail(sprintf('expected a map of %d entries', \count($entries)));
                }
                foreach ($entries as $key => $item) {
                    if (!\array_key_exists($key, $actual)) {
                        return $fail('missing key ' . var_export((string) $key, true));
                    }
                    if (!$item instanceof \stdClass) {
                        throw new \UnexpectedValueException('malformed typed map');
                    }
                    $diff = self::diff($item, $actual[$key], $objects, "{$path}[" . var_export((string) $key, true) . ']');
                    if ($diff !== null) {
                        return $diff;
                    }
                }

                return null;
            case 'timestamp':
                $ts = self::timestamp(self::object($expected));
                if ($objects) {
                    return $actual instanceof Timestamp && $actual == $ts ? null : $fail(sprintf('expected Timestamp(%d, %d, %s)', $ts->seconds, $ts->nanoseconds, var_export($ts->offsetMinutes, true)));
                }

                return self::sameInstant($actual, $ts->seconds, intdiv($ts->nanoseconds, 1000), $ts->offsetMinutes)
                    ? null : $fail(sprintf('expected DateTimeImmutable for %d.%09d', $ts->seconds, $ts->nanoseconds));
            case 'date':
                $ms = (int) self::str($expected);
                if ($objects) {
                    return $actual instanceof Date && $actual->milliseconds === $ms ? null : $fail("expected Date($ms)");
                }
                $seconds = intdiv($ms, 1000) - ($ms % 1000 < 0 ? 1 : 0);
                $rest = $ms - $seconds * 1000;

                return self::sameInstant($actual, $seconds, $rest * 1000, null) ? null : $fail("expected DateTimeImmutable for $ms ms");
            case 'datetime':
                $ns = (int) self::str($expected);
                if ($objects) {
                    return $actual instanceof DateTime && $actual->nanoseconds === $ns ? null : $fail("expected DateTime($ns)");
                }
                $seconds = intdiv($ns, 1_000_000_000) - ($ns % 1_000_000_000 < 0 ? 1 : 0);
                $rest = $ns - $seconds * 1_000_000_000;

                return self::sameInstant($actual, $seconds, intdiv($rest, 1000), null) ? null : $fail("expected DateTimeImmutable for $ns ns");
            case 'float32_vector':
                $bytes = (new VectorF32(array_map(self::float(...), self::stringList($expected))))->toBytes();

                return $actual instanceof VectorF32 && $actual->toBytes() === $bytes ? null : $fail('expected VectorF32 0x' . bin2hex($bytes));
            case 'float64_vector':
                $bytes = (new VectorF64(array_map(self::float(...), self::stringList($expected))))->toBytes();

                return $actual instanceof VectorF64 && $actual->toBytes() === $bytes ? null : $fail('expected VectorF64 0x' . bin2hex($bytes));
            case 'ext':
                $ext = self::object($expected);
                $type = self::intField($ext, 'type');
                $data = self::hex($ext->data ?? '');

                return $actual instanceof Ext && $actual->type === $type && $actual->data === $data
                    ? null : $fail(sprintf('expected Ext(%d, 0x%s)', $type, bin2hex($data)));
        }

        throw new \UnexpectedValueException("unknown typed value kind $kind");
    }

    public static function describe(mixed $value): string
    {
        return match (true) {
            \is_string($value) => sprintf('string(%d) 0x%s', \strlen($value), bin2hex(substr($value, 0, 24))),
            \is_float($value) => 'float ' . (is_nan($value) ? 'NaN' : bin2hex(pack('E', $value))),
            \is_array($value) => sprintf('%s(%d)', array_is_list($value) ? 'list' : 'map', \count($value)),
            $value instanceof BigInt => 'BigInt ' . $value->toString(),
            $value instanceof \DateTimeInterface => 'DateTimeImmutable ' . $value->format('Y-m-d\TH:i:s.uP'),
            \is_object($value) => $value::class,
            default => var_export($value, true),
        };
    }

    private static function sameInstant(mixed $actual, int $seconds, int $micros, ?int $offsetMinutes): bool
    {
        if (!$actual instanceof \DateTimeImmutable) {
            return false;
        }
        if ($actual->getTimestamp() !== $seconds || (int) $actual->format('u') !== $micros) {
            return false;
        }

        return $offsetMinutes === null
            ? $actual->getTimezone()->getName() === 'UTC'
            : $actual->getOffset() === $offsetMinutes * 60;
    }

    private static function int(string $decimal): int|BigInt
    {
        $value = new BigInt($decimal);

        return $value->fitsInt() ? $value->toInt() : $value;
    }

    public static function float(string $text): float
    {
        return match ($text) {
            'NaN' => NAN,
            'Infinity' => INF,
            '-Infinity' => -INF,
            default => (float) $text,
        };
    }

    /** Maps whose PHP form would be ambiguous (empty, or keys "0"…"n-1") become ArrayObject. */
    private static function map(\stdClass $typed): mixed
    {
        $entries = [];
        foreach (get_object_vars($typed) as $key => $item) {
            if (!$item instanceof \stdClass) {
                throw new \UnexpectedValueException('malformed typed map');
            }
            $entries[$key] = self::build($item);
        }

        return $entries === [] || array_is_list($entries) ? new \ArrayObject($entries) : $entries;
    }

    private static function timestamp(\stdClass $typed): Timestamp
    {
        $offset = $typed->offset_minutes ?? null;

        return new Timestamp(
            (int) self::str($typed->seconds ?? null),
            self::intField($typed, 'nanoseconds'),
            $offset === null ? null : (int) $offset,
        );
    }

    /** @return array{string, mixed} */
    private static function unpack(\stdClass $typed): array
    {
        $fields = get_object_vars($typed);
        if (\count($fields) !== 1) {
            throw new \UnexpectedValueException('a typed value has exactly one key');
        }
        $kind = array_key_first($fields);

        return [(string) $kind, $fields[$kind]];
    }

    private static function str(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('expected a JSON string, got ' . get_debug_type($value));
        }

        return $value;
    }

    private static function hex(mixed $value): string
    {
        $bytes = hex2bin(self::str($value));
        if ($bytes === false) {
            throw new \UnexpectedValueException('invalid hex');
        }

        return $bytes;
    }

    private static function object(mixed $value): \stdClass
    {
        if (!$value instanceof \stdClass) {
            throw new \UnexpectedValueException('expected a JSON object, got ' . get_debug_type($value));
        }

        return $value;
    }

    private static function intField(\stdClass $object, string $field): int
    {
        $value = $object->{$field} ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException("expected an int field $field");
        }

        return $value;
    }

    /** @return list<\stdClass> */
    private static function typedList(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('expected a JSON array');
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = self::object($item);
        }

        return $items;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('expected a JSON array');
        }

        return array_values(array_map(self::str(...), $value));
    }
}

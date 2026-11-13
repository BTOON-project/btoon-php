<?php

declare(strict_types=1);

namespace BTOON;

use BTOON\Internal\BigIntMath;
use BTOON\Internal\Clock;
use BTOON\Internal\Envelope;
use BTOON\Internal\Runtime;

/**
 * Hardened BTOON decoder (SPEC §6–§8).
 *
 * Accepts every valid MessagePack encoding of a value, canonical or not. Every read
 * is bounds-checked against the remaining input, nesting is limited by `max_depth`,
 * element counts are checked against the remaining bytes before looping, strings,
 * keys and column names must be well-formed UTF-8, duplicate keys and trailing bytes
 * are rejected (strict mode), and compression envelopes are size-limited before and
 * during decompression. A document decodes to at most `max_values` values, and a table's
 * rows × column-name bytes stay within `max_decompressed_size` (SPEC §7.1), which bounds
 * the memory a decoded document takes.
 *
 * PHP type mapping:
 * - nil, bool, float → `null`, `bool`, `float`
 * - integer → `int`; uint64 above PHP_INT_MAX and bigints outside int64 → {@see BigInt}
 * - str → `string`; bin → `string`, or {@see Binary} with `binary_as_object`
 * - array → list array; tabular → list of maps
 * - map → associative array, or `\ArrayObject` (see below and {@see MapMode})
 * - timestamp, date, datetime → `\DateTimeImmutable` (UTC, or the timestamp's fixed offset),
 *   or {@see Timestamp} / {@see Date} / {@see DateTime} with `timestamps => 'object'`
 * - float32/float64 vectors → {@see VectorF32} / {@see VectorF64}
 * - other extensions → {@see Ext}
 *
 * PHP turns integer-like map keys ("1", "-5") into int array keys; the encoder turns them
 * back into strings. An array whose keys are 0…n-1 in order is a list, which the encoder
 * writes as an array. So with the default `maps => 'auto'`, the maps that PHP would turn
 * into a list (the empty map, and keys "0"…"n-1" in order) decode to `\ArrayObject`, which
 * the encoder writes as a map, and maps and arrays keep their type through a decode and
 * encode round trip. With `maps => 'array'`, those maps decode to lists and re-encode as
 * arrays. Binaries and the exact timestamp types survive a round trip only with
 * `binary_as_object` and `timestamps => 'object'`; with those, encoding what strict mode
 * decoded gives back the same value.
 */
final class Decoder
{
    private const KEY_CACHE_LIMIT = 4096;

    private readonly DecodeOptions $options;
    private readonly bool $strict;
    private readonly int $maxDepth;
    private readonly bool $temporalObjects;
    private readonly bool $binaryObjects;
    /** Every map becomes an ArrayObject (`maps => 'object'`). */
    private readonly bool $mapObjects;
    /** Maps that PHP would turn into a list become ArrayObject (`maps => 'auto'`). */
    private readonly bool $listLikeMapObjects;
    private readonly bool $mbstring;
    /** `max_values` (SPEC §7.1). */
    private readonly int $maxValues;
    /** A table's rows × column-name bytes may not exceed this (`max_decompressed_size`). */
    private readonly int $maxNameBytes;

    private string $data = '';
    private int $pos = 0;
    private int $end = 0;
    /** How many more values the document may decode to. */
    private int $valuesLeft = 0;
    /** @var array<array-key, true> keys already validated as UTF-8 during this decode */
    private array $validKeys = [];

    /** @param DecodeOptions|array<string, mixed> $options */
    public function __construct(DecodeOptions|array $options = [])
    {
        Runtime::check();
        $this->options = $options instanceof DecodeOptions ? $options : DecodeOptions::fromArray($options);
        $this->strict = $this->options->strict;
        $this->maxDepth = $this->options->maxDepth;
        $this->temporalObjects = $this->options->timestamps === TimestampMode::Object;
        $this->binaryObjects = $this->options->binaryAsObject;
        $this->mapObjects = $this->options->maps === MapMode::Object;
        $this->listLikeMapObjects = $this->options->maps === MapMode::Auto;
        $this->mbstring = \function_exists('mb_check_encoding');
        $this->maxValues = $this->options->maxValues;
        $this->maxNameBytes = $this->options->maxDecompressedSize;
    }

    public function getOptions(): DecodeOptions
    {
        return $this->options;
    }

    /**
     * Decodes one BTOON document, unwrapping signed and compression envelopes.
     *
     * @throws BtoonException
     */
    public function decode(string $data): mixed
    {
        $document = Envelope::open($data, $this->options);
        $state = [$this->data, $this->pos, $this->end, $this->validKeys, $this->valuesLeft];
        $this->data = $document;
        $this->pos = 0;
        $this->end = \strlen($document);
        $this->validKeys = [];
        $this->valuesLeft = $this->maxValues;
        try {
            $value = $this->value(0);
            if ($this->pos !== $this->end && $this->strict) {
                throw $this->error(
                    ErrorCode::TrailingData,
                    sprintf('%d trailing bytes after the top-level value', $this->end - $this->pos),
                );
            }

            return $value;
        } finally {
            [$this->data, $this->pos, $this->end, $this->validKeys, $this->valuesLeft] = $state;
        }
    }

    private function value(int $depth): mixed
    {
        // A value counts when it starts, before its marker is examined and before any value
        // inside it (SPEC §7.1).
        if (--$this->valuesLeft < 0) {
            throw $this->tooManyValues();
        }
        if ($this->pos >= $this->end) {
            throw $this->truncated('a value');
        }
        $marker = \ord($this->data[$this->pos++]);
        if ($marker <= 0x7f) {
            return $marker;
        }
        if ($marker >= 0xe0) {
            return $marker - 0x100;
        }
        if ($marker >= 0xa0 && $marker <= 0xbf) {
            return $this->str($marker & 0x1f);
        }
        if ($marker <= 0x8f) {
            return $this->map($marker & 0x0f, $depth);
        }
        if ($marker <= 0x9f) {
            return $this->list($marker & 0x0f, $depth);
        }

        return match ($marker) {
            0xc0 => null,
            0xc2 => false,
            0xc3 => true,
            0xc4 => $this->bin($this->u8()),
            0xc5 => $this->bin($this->u16()),
            0xc6 => $this->bin($this->u32()),
            0xc7 => $this->ext($this->u8(), $depth),
            0xc8 => $this->ext($this->u16(), $depth),
            0xc9 => $this->ext($this->u32(), $depth),
            0xca => $this->float32(),
            0xcb => $this->float64(),
            0xcc => $this->u8(),
            0xcd => $this->u16(),
            0xce => $this->u32(),
            0xcf => $this->uint64(),
            0xd0 => ($v = $this->u8()) >= 0x80 ? $v - 0x100 : $v,
            0xd1 => ($v = $this->u16()) >= 0x8000 ? $v - 0x10000 : $v,
            0xd2 => ($v = $this->u32()) >= 0x80000000 ? $v - 0x100000000 : $v,
            0xd3 => $this->int64(),
            0xd4 => $this->ext(1, $depth),
            0xd5 => $this->ext(2, $depth),
            0xd6 => $this->ext(4, $depth),
            0xd7 => $this->ext(8, $depth),
            0xd8 => $this->ext(16, $depth),
            0xd9 => $this->str($this->u8()),
            0xda => $this->str($this->u16()),
            0xdb => $this->str($this->u32()),
            0xdc => $this->list($this->u16(), $depth),
            0xdd => $this->list($this->u32(), $depth),
            0xde => $this->map($this->u16(), $depth),
            0xdf => $this->map($this->u32(), $depth),
            default => throw $this->error(ErrorCode::InvalidMarker, 'reserved marker 0xc1', $this->pos - 1),
        };
    }

    private function str(int $length): string
    {
        if ($length > $this->end - $this->pos) {
            throw $this->truncated(sprintf('a string of %d bytes', $length));
        }
        $string = substr($this->data, $this->pos, $length);
        // Non-strict mode skips this check for string values only; keys and column names
        // are always checked.
        if ($this->strict && !$this->validUtf8($string)) {
            throw $this->error(ErrorCode::InvalidUtf8, 'string is not valid UTF-8');
        }
        $this->pos += $length;

        return $string;
    }

    private function bin(int $length): string|Binary
    {
        if ($length > $this->end - $this->pos) {
            throw $this->truncated(sprintf('a binary of %d bytes', $length));
        }
        $bytes = substr($this->data, $this->pos, $length);
        $this->pos += $length;

        return $this->binaryObjects ? new Binary($bytes) : $bytes;
    }

    /** @return list<mixed> */
    private function list(int $count, int $depth): array
    {
        if ($depth >= $this->maxDepth) {
            throw $this->tooDeep();
        }
        if ($count > $this->end - $this->pos) {
            throw $this->truncated(sprintf('an array of %d elements', $count));
        }
        $list = [];
        for ($i = 0; $i < $count; ++$i) {
            $list[] = $this->value($depth + 1);
        }

        return $list;
    }

    /** @return array<array-key, mixed>|\ArrayObject<array-key, mixed> */
    private function map(int $count, int $depth): array|\ArrayObject
    {
        if ($depth >= $this->maxDepth) {
            throw $this->tooDeep();
        }
        if ($count > ($this->end - $this->pos) >> 1) {
            throw $this->truncated(sprintf('a map of %d entries', $count));
        }
        /** @var array<array-key, mixed> $map integer-like keys become int keys */
        $map = [];
        for ($i = 0; $i < $count; ++$i) {
            $at = $this->pos;
            $key = $this->key();
            $value = $this->value($depth + 1);
            if ($this->strict && \array_key_exists($key, $map)) {
                throw $this->error(ErrorCode::DuplicateKey, sprintf('duplicate map key %s', self::quote($key)), $at);
            }
            $map[$key] = $value;
        }
        if ($this->mapObjects || ($this->listLikeMapObjects && array_is_list($map))) {
            return new \ArrayObject($map);
        }

        return $map;
    }

    private function key(): string
    {
        if ($this->pos >= $this->end) {
            throw $this->truncated('a map key');
        }
        $marker = \ord($this->data[$this->pos]);
        if ($marker >= 0xa0 && $marker <= 0xbf) {
            ++$this->pos;
            $length = $marker & 0x1f;
        } elseif ($marker >= 0xd9 && $marker <= 0xdb) {
            ++$this->pos;
            $length = match ($marker) {
                0xd9 => $this->u8(),
                0xda => $this->u16(),
                default => $this->u32(),
            };
        } else {
            throw $this->error(ErrorCode::NonStringKey, sprintf('map key is not a string (marker 0x%02x)', $marker));
        }
        if ($length > $this->end - $this->pos) {
            throw $this->truncated(sprintf('a map key of %d bytes', $length));
        }
        $key = substr($this->data, $this->pos, $length);
        // Keys must be UTF-8 in non-strict mode too: they compare by their bytes, and a
        // decoder that replaced invalid sequences could merge two keys (SPEC §7).
        if (!isset($this->validKeys[$key])) {
            if (!$this->validUtf8($key)) {
                throw $this->error(ErrorCode::InvalidUtf8, 'map key is not valid UTF-8');
            }
            if (\count($this->validKeys) >= self::KEY_CACHE_LIMIT) {
                $this->validKeys = [];
            }
            $this->validKeys[$key] = true;
        }
        $this->pos += $length;

        return $key;
    }

    private function ext(int $length, int $depth): mixed
    {
        if ($this->pos >= $this->end) {
            throw $this->truncated('an extension type');
        }
        $type = \ord($this->data[$this->pos++]);
        if ($length > $this->end - $this->pos) {
            throw $this->truncated(sprintf('an extension payload of %d bytes', $length));
        }
        $start = $this->pos;
        switch ($type) {
            case 0xff: // -1 timestamp
                $value = $this->timestamp($start, $length);
                break;
            case 0xfe: // -2 date
            case 0xfd: // -3 datetime
                if ($length !== 8) {
                    throw $this->malformed(sprintf('%s payload must be 8 bytes, got %d', $type === 0xfe ? 'date' : 'datetime', $length));
                }
                $count = $this->readInt64($start);
                if ($type === 0xfe) {
                    $value = $this->temporalObjects ? new Date($count) : (new Date($count))->toDateTimeImmutable();
                } else {
                    $value = $this->temporalObjects ? new DateTime($count) : (new DateTime($count))->toDateTimeImmutable();
                }
                break;
            case 0xfc: // -4 bigint
                if ($length === 0) {
                    throw $this->malformed('bigint payload must not be empty');
                }
                $bytes = BigIntMath::minimal(substr($this->data, $start, $length));
                $value = \strlen($bytes) <= 8 ? BigIntMath::bytesToInt($bytes) : BigInt::fromBytes($bytes);
                break;
            case 0xfb: // -5 float32 vector
                if ($length % 4 !== 0) {
                    throw $this->malformed(sprintf('float32 vector payload of %d bytes is not a multiple of 4', $length));
                }
                $value = VectorF32::fromBytes(substr($this->data, $start, $length));
                break;
            case 0xfa: // -6 float64 vector
                if ($length % 8 !== 0) {
                    throw $this->malformed(sprintf('float64 vector payload of %d bytes is not a multiple of 8', $length));
                }
                $value = VectorF64::fromBytes(substr($this->data, $start, $length));
                break;
            case 0xf6: // -10 tabular
                return $this->tabular($start, $start + $length, $depth);
            default:
                $value = new Ext($type >= 0x80 ? $type - 0x100 : $type, substr($this->data, $start, $length));
        }
        $this->pos = $start + $length;

        return $value;
    }

    private function timestamp(int $start, int $length): \DateTimeImmutable|Timestamp
    {
        $offset = null;
        switch ($length) {
            case 4:
                $seconds = $this->readUint32($start);
                $nanos = 0;
                break;
            case 8:
                $data64 = $this->readInt64($start);
                $nanos = ($data64 >> 34) & 0x3fffffff;
                $seconds = $data64 & 0x3ffffffff;
                break;
            case 12:
            case 14:
                $nanos = $this->readUint32($start);
                $seconds = $this->readInt64($start + 4);
                if ($length === 14) {
                    $offset = (\ord($this->data[$start + 12]) << 8) | \ord($this->data[$start + 13]);
                    if ($offset >= 0x8000) {
                        $offset -= 0x10000;
                    }
                    if (abs($offset) > Timestamp::MAX_OFFSET_MINUTES) {
                        throw $this->malformed(sprintf('timestamp offset %d is outside ±1080 minutes', $offset));
                    }
                }
                break;
            default:
                throw $this->malformed(sprintf('timestamp payload must be 4, 8, 12 or 14 bytes, got %d', $length));
        }
        if ($nanos >= 1_000_000_000) {
            throw $this->malformed(sprintf('timestamp nanoseconds %d are not below 10^9', $nanos));
        }

        return $this->temporalObjects
            ? new Timestamp($seconds, $nanos, $offset)
            : Clock::fromParts($seconds, intdiv($nanos, 1000), $offset);
    }

    /**
     * Decodes a tabular payload spanning [$start, $end) into a list of maps (SPEC §5.6.3).
     *
     * @return list<array<array-key, mixed>>|list<\ArrayObject<array-key, mixed>>
     */
    private function tabular(int $start, int $end, int $depth): array
    {
        // The array and its row maps are two nesting levels.
        if ($depth + 1 >= $this->maxDepth) {
            throw $this->tooDeep();
        }
        $this->pos = $start;
        if ($end - $start < 12) {
            throw $this->badTable('header is truncated');
        }
        $version = $this->readUint32($start);
        $columns = $this->readUint32($start + 4);
        $rows = $this->readUint32($start + 8);
        $p = $start + 12;
        if ($version !== 1) {
            throw $this->badTable(sprintf('version %d is not supported', $version));
        }
        if ($columns === 0 && $rows > 0) {
            throw $this->badTable(sprintf('%d rows without columns', $rows));
        }
        // Each column needs at least 9 bytes: name length, type code and column length.
        if ($columns > intdiv($end - $p, 9)) {
            throw $this->badTable(sprintf('%d columns do not fit in the payload', $columns));
        }

        // Names are checked front to back (SPEC §5.6.3): each must be UTF-8, in non-strict
        // mode too, and differ from every earlier name. The first name that fails sets the error.
        $names = [];
        $seen = [];
        $nameBytes = 0;
        for ($c = 0; $c < $columns; ++$c) {
            if ($end - $p < 4) {
                throw $this->badTable('column names are truncated');
            }
            $length = $this->readUint32($p);
            $p += 4;
            if ($length > $end - $p) {
                throw $this->badTable('a column name runs past the payload');
            }
            $name = substr($this->data, $p, $length);
            if (!$this->validUtf8($name)) {
                $this->pos = $p;
                throw $this->error(ErrorCode::InvalidUtf8, 'tabular column name is not valid UTF-8');
            }
            if (isset($seen[$name])) {
                throw $this->badTable(sprintf('duplicate column name %s', self::quote($name)));
            }
            $seen[$name] = true;
            $names[] = $name;
            $nameBytes += $length;
            $p += $length;
        }
        if ($end - $p < $columns) {
            throw $this->badTable('type codes are truncated');
        }
        $p += $columns; // type codes are informational (SPEC §5.6.1)

        // Then, in this order (SPEC §5.6.3): every cell is at least one byte, so N × C cells
        // must fit in what's left;
        if ($columns > 0 && $rows > intdiv($end - $p, $columns)) {
            throw $this->badTable(sprintf('%d rows × %d columns do not fit in the payload', $rows, $columns));
        }
        $this->pos = $p;
        // every row holds the column names, so rows × name bytes stay within the size limit
        // (rows > ⌊limit / bytes⌋ is rows × bytes > limit, without overflow);
        if ($nameBytes > 0 && $rows > intdiv($this->maxNameBytes, $nameBytes)) {
            throw $this->error(ErrorCode::SizeLimit, sprintf(
                'tabular %d rows × %d bytes of column names exceed max_decompressed_size (%d)',
                $rows,
                $nameBytes,
                $this->maxNameBytes,
            ));
        }
        // and the row maps count against max_values, all together. Each cell counts as it starts.
        if ($rows > $this->valuesLeft) {
            throw $this->tooManyValues();
        }
        $this->valuesLeft -= $rows;
        // Each row is created once its first cell is decoded. Building all the rows the header
        // announces up front let nested tables count the same bytes at every level: 64
        // levels in 1 MiB built 2 GB of empty rows (review 2026-11-13, X1).
        /** @var list<array<array-key, mixed>> $table */
        $table = [];
        $outerEnd = $this->end;
        foreach ($names as $column => $name) {
            if ($end - $p < 4) {
                throw $this->badTable('column data is truncated');
            }
            $length = $this->readUint32($p);
            $p += 4;
            if ($length > $end - $p) {
                throw $this->badTable('a column runs past the payload');
            }
            $columnEnd = $p + $length;
            $this->pos = $p;
            $this->end = $columnEnd;
            try {
                if ($column === 0) {
                    for ($r = 0; $r < $rows; ++$r) {
                        $table[] = [$name => $this->value($depth + 2)];
                    }
                } else {
                    for ($r = 0; $r < $rows; ++$r) {
                        $table[$r][$name] = $this->value($depth + 2);
                    }
                }
            } catch (BtoonException $e) {
                if ($e->error === ErrorCode::Truncated) {
                    throw new BtoonException(
                        ErrorCode::InvalidTabular,
                        sprintf('tabular column %s holds fewer than %d values: %s', self::quote($name), $rows, $e->getMessage()),
                        $e,
                    );
                }
                throw $e;
            } finally {
                $this->end = $outerEnd;
            }
            if ($this->pos !== $columnEnd) {
                throw $this->badTable(sprintf(
                    'column %s has %d bytes left over after %d values',
                    self::quote($name),
                    $columnEnd - $this->pos,
                    $rows,
                ));
            }
            $p = $columnEnd;
        }
        if ($p !== $end) {
            $this->pos = $p;
            throw $this->badTable(sprintf('%d bytes left over after the last column', $end - $p));
        }
        $this->pos = $end;
        // The rows are maps too, each with the column names as its keys, in column order.
        if ($this->mapObjects || ($this->listLikeMapObjects && $rows > 0 && self::isListLike($names))) {
            return array_map(static fn (array $row): \ArrayObject => new \ArrayObject($row), $table);
        }

        return $table;
    }

    /**
     * Whether an array with these keys, in this order, would be a list (PHP turns the keys
     * "0", "1", … into ints).
     *
     * @param list<string> $keys
     */
    private static function isListLike(array $keys): bool
    {
        /** @var array<array-key, null> $array */
        $array = array_fill_keys($keys, null);

        return array_is_list($array);
    }

    // --- Primitive reads (all bounds-checked) ----------------------------------------

    private function u8(): int
    {
        if ($this->pos >= $this->end) {
            throw $this->truncated('a 1-byte field');
        }

        return \ord($this->data[$this->pos++]);
    }

    private function u16(): int
    {
        if ($this->end - $this->pos < 2) {
            throw $this->truncated('a 2-byte field');
        }
        $value = (\ord($this->data[$this->pos]) << 8) | \ord($this->data[$this->pos + 1]);
        $this->pos += 2;

        return $value;
    }

    private function u32(): int
    {
        if ($this->end - $this->pos < 4) {
            throw $this->truncated('a 4-byte field');
        }
        $value = $this->readUint32($this->pos);
        $this->pos += 4;

        return $value;
    }

    private function int64(): int
    {
        if ($this->end - $this->pos < 8) {
            throw $this->truncated('an 8-byte field');
        }
        $value = $this->readInt64($this->pos);
        $this->pos += 8;

        return $value;
    }

    private function uint64(): int|BigInt
    {
        $value = $this->int64();
        if ($value >= 0) {
            return $value;
        }

        // Above PHP_INT_MAX: the 8 bytes read as an unsigned magnitude.
        return BigInt::fromBytes("\x00" . substr($this->data, $this->pos - 8, 8));
    }

    private function float32(): float
    {
        if ($this->end - $this->pos < 4) {
            throw $this->truncated('a float32');
        }
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('G', $this->data, $this->pos);
        $this->pos += 4;

        return $unpacked[1];
    }

    private function float64(): float
    {
        if ($this->end - $this->pos < 8) {
            throw $this->truncated('a float64');
        }
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('E', $this->data, $this->pos);
        $this->pos += 8;

        return $unpacked[1];
    }

    /** Reads a u32 at an offset the caller has bounds-checked. */
    private function readUint32(int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $this->data, $offset);

        return $unpacked[1];
    }

    /** Reads an i64 at an offset the caller has bounds-checked. */
    private function readInt64(int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', $this->data, $offset);

        return $unpacked[1];
    }

    private function validUtf8(string $value): bool
    {
        return $this->mbstring ? mb_check_encoding($value, 'UTF-8') : preg_match('//u', $value) === 1;
    }

    // --- Errors ---------------------------------------------------------------------

    private function error(ErrorCode $code, string $message, ?int $at = null): BtoonException
    {
        return new BtoonException($code, sprintf('%s (at byte %d)', $message, $at ?? $this->pos));
    }

    private function truncated(string $what): BtoonException
    {
        return $this->error(ErrorCode::Truncated, 'unexpected end of input while reading ' . $what);
    }

    private function malformed(string $message): BtoonException
    {
        return $this->error(ErrorCode::InvalidExtension, $message);
    }

    private function badTable(string $message): BtoonException
    {
        return $this->error(ErrorCode::InvalidTabular, 'tabular ' . $message);
    }

    private function tooDeep(): BtoonException
    {
        return $this->error(ErrorCode::MaxDepth, sprintf('nesting deeper than max_depth (%d)', $this->maxDepth));
    }

    private function tooManyValues(): BtoonException
    {
        return $this->error(
            ErrorCode::SizeLimit,
            sprintf('the document decodes to more than max_values (%d) values', $this->maxValues),
        );
    }

    private static function quote(int|string $value): string
    {
        $value = (string) $value;

        return \strlen($value) > 40 ? var_export(substr($value, 0, 40), true) . '…' : var_export($value, true);
    }
}

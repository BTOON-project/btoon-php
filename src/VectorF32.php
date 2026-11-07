<?php

declare(strict_types=1);

namespace BTOON;

/**
 * A packed float32 vector, BTOON extension -5 (SPEC §5.5).
 *
 * The values are stored as little-endian IEEE 754 binary32 bytes, exactly as on the
 * wire, so decoding and re-encoding is bit-exact (NaN payloads included) and costs a
 * single copy. {@see toArray()} converts to PHP floats on demand.
 *
 * @implements \IteratorAggregate<int, float>
 */
final class VectorF32 implements \Countable, \IteratorAggregate
{
    private string $bytes = '';

    /** @param array<int|float> $values rounded to the nearest float32 */
    public function __construct(array $values = [])
    {
        if ($values !== []) {
            self::assertNumbers($values);
            $this->bytes = pack('g*', ...array_values($values));
        }
    }

    /** From packed little-endian binary32 bytes (length must be a multiple of 4). */
    public static function fromBytes(string $bytes): self
    {
        if (\strlen($bytes) % 4 !== 0) {
            throw new BtoonException(
                ErrorCode::InvalidArgument,
                sprintf('a float32 vector needs a multiple of 4 bytes, got %d', \strlen($bytes)),
            );
        }
        $vector = new self();
        $vector->bytes = $bytes;

        return $vector;
    }

    /** The packed little-endian binary32 bytes. */
    public function toBytes(): string
    {
        return $this->bytes;
    }

    /** @return list<float> */
    public function toArray(): array
    {
        if ($this->bytes === '') {
            return [];
        }
        /** @var array<int, float> $values */
        $values = unpack('g*', $this->bytes);

        return array_values($values);
    }

    public function count(): int
    {
        return \strlen($this->bytes) >> 2;
    }

    /** @return \ArrayIterator<int, float> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->toArray());
    }

    public function equals(self $other): bool
    {
        return $this->bytes === $other->bytes;
    }

    /** @param array<mixed> $values */
    private static function assertNumbers(array $values): void
    {
        foreach ($values as $value) {
            if (!\is_float($value) && !\is_int($value)) {
                throw new BtoonException(
                    ErrorCode::InvalidArgument,
                    'vector elements must be int or float, got ' . get_debug_type($value),
                );
            }
        }
    }
}

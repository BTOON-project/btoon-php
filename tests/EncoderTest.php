<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\BigInt;
use BTOON\Binary;
use BTOON\BTOON;
use BTOON\BtoonException;
use BTOON\Compression;
use BTOON\Date;
use BTOON\DateTime;
use BTOON\EncodeOptions;
use BTOON\Encoder;
use BTOON\ErrorCode;
use BTOON\Ext;
use BTOON\Timestamp;
use BTOON\VectorF32;
use BTOON\VectorF64;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function BTOON\decode;
use function BTOON\encode;

enum Suit: string
{
    case Hearts = 'H';
    case Spades = 'S';
}

enum Level: int
{
    case Low = 1;
    case High = 200;
}

enum Pure
{
    case Thing;
}

final class EncoderTest extends TestCase
{
    // --- Ambiguity rules of the implementation guide (§5) -----------------------------

    public function testEmptyArrayIsAnArrayAndObjectsGiveEmptyMaps(): void
    {
        self::assertSame('90', self::hex([]));
        self::assertSame('80', self::hex(new \ArrayObject()));
        self::assertSame('80', self::hex(new \stdClass()));
        self::assertSame('80', self::hex(new \ArrayIterator([])));
    }

    public function testListArraysAreArraysAndOtherArraysAreMaps(): void
    {
        self::assertSame('92a161a162', self::hex(['a', 'b']));
        // Keys out of order: not a list, so a map with string keys "0" and "1".
        self::assertSame('82a130a162a131a161', self::hex([1 => 'a', 0 => 'b']));
        self::assertSame('81a131a161', self::hex([1 => 'a']));
        // ArrayObject and stdClass always encode as maps, even with list-like keys.
        self::assertSame('82a130a161a131a162', self::hex(new \ArrayObject(['a', 'b'])));
        self::assertSame('82a130a161a131a162', self::hex((object) ['a', 'b']));
    }

    public function testStringsThatAreNotUtf8BecomeBinary(): void
    {
        self::assertSame('a3e697a5', self::hex('日'));
        self::assertSame('c401ff', self::hex("\xff"));
        self::assertSame('c403eda080', self::hex("\xed\xa0\x80")); // surrogate
        self::assertSame('c402c080', self::hex("\xc0\x80")); // overlong
        self::assertSame('c40161', self::hex(new Binary('a'))); // forced binary
        self::assertSame('a0', self::hex(''));
        self::assertSame('c400', self::hex(new Binary('')));
    }

    public function testInvalidUtf8MapKeysAreRejected(): void
    {
        $this->expectBtoonError(ErrorCode::InvalidUtf8, static fn () => encode(["\xff" => 1]));
    }

    public function testInvalidUtf8TabularColumnNamesAreRejected(): void
    {
        $this->expectBtoonError(ErrorCode::InvalidUtf8, static fn () => encode(array_fill(0, 10, ["\xff" => 1])));
    }

    // --- Canonical forms ------------------------------------------------------------

    public function testMapKeysAreSortedByUtf8BytesIncludingIntegerLikeKeys(): void
    {
        // PHP stores "10", "9" and "-1" as int keys; they must sort as strings, bytewise.
        $map = ['a' => 3, '10' => 1, '9' => 2, '-1' => 4, '01' => 5, '' => 6];
        self::assertSame('86a006a22d3104a2303105a2313001a13902a16103', self::hex($map));
        // Non-BMP characters sort after U+FFFF in UTF-8 (unlike UTF-16 order).
        self::assertSame('83a3ee808003a3efbfbf02a4f09f988001', self::hex(["😀" => 1, "\u{FFFF}" => 2, "\u{E000}" => 3]));
        // Insertion order never matters.
        self::assertSame(self::hex(['b' => 1, 'a' => 2]), self::hex(['a' => 2, 'b' => 1]));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function canonicalScalars(): iterable
    {
        yield 'NaN' => [NAN, 'cb7ff8000000000000'];
        yield '-NaN' => [-NAN, 'cb7ff8000000000000'];
        yield 'NaN with payload' => [unpack('E', "\x7f\xf0\x00\x00\x00\x00\x00\x01")[1], 'cb7ff8000000000000'];
        yield '-0.0' => [-0.0, 'cb8000000000000000'];
        yield 'INF' => [INF, 'cb7ff0000000000000'];
        yield 'integral float stays float' => [2.0, 'cb4000000000000000'];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX, 'cf7fffffffffffffff'];
        yield 'PHP_INT_MIN' => [PHP_INT_MIN, 'd38000000000000000'];
        yield 'BigInt inside int range' => [new BigInt('5'), '05'];
        yield 'BigInt -1' => [new BigInt('-1'), 'ff'];
        yield 'BigInt 2^63' => [new BigInt('9223372036854775808'), 'cf8000000000000000'];
        yield 'BigInt 2^64-1' => [new BigInt('18446744073709551615'), 'cfffffffffffffffff'];
        yield 'BigInt 2^64' => [new BigInt('18446744073709551616'), 'c709fc010000000000000000'];
        yield 'BigInt -2^63' => [new BigInt('-9223372036854775808'), 'd38000000000000000'];
        yield 'BigInt -2^63-1' => [new BigInt('-9223372036854775809'), 'c709fcff7fffffffffffffff'];
        yield 'BackedEnum string' => [Suit::Hearts, 'a148'];
        yield 'BackedEnum int' => [Level::High, 'ccc8'];
    }

    #[DataProvider('canonicalScalars')]
    public function testCanonicalScalarEncodings(mixed $value, string $hex): void
    {
        self::assertSame($hex, self::hex($value));
    }

    #[RequiresPhpExtension('gmp')]
    public function testGmpNumbersEncodeLikeIntegers(): void
    {
        self::assertSame('05', self::hex(gmp_init(5)));
        self::assertSame('d38000000000000000', self::hex(gmp_init('-9223372036854775808')));
        self::assertSame('cfffffffffffffffff', self::hex(gmp_init('18446744073709551615')));
        self::assertSame('c709fc010000000000000000', self::hex(gmp_init('18446744073709551616')));
        self::assertSame(self::hex(new BigInt('-' . str_repeat('9', 60))), self::hex(gmp_init('-' . str_repeat('9', 60))));
    }

    // --- Dates and times ---------------------------------------------------------------

    public function testDateTimesBecomeUtcTimestampsWithMicroseconds(): void
    {
        self::assertSame('d6ff65920080', self::hex(new \DateTimeImmutable('2024-01-01T00:00:00Z')));
        // Any time zone is normalized to the same UTC instant, without an offset.
        self::assertSame('d6ff65920080', self::hex(new \DateTimeImmutable('2024-01-01T02:00:00+02:00')));
        self::assertSame('d6ff65920080', self::hex(new \DateTime('2023-12-31 19:00:00', new \DateTimeZone('America/New_York'))));
        // Microseconds become nanoseconds.
        self::assertSame(
            self::hex(new Timestamp(1704067200, 123456000)),
            self::hex(new \DateTimeImmutable('2024-01-01T00:00:00.123456Z')),
        );
        // Before 1970 with a fraction: seconds = -1, nanoseconds = 500000000.
        self::assertSame(
            'c70cff1dcd6500ffffffffffffffff',
            self::hex(new \DateTimeImmutable('1969-12-31T23:59:59.5Z')),
        );
    }

    public function testOffsetsAreOnlyWrittenWhenAskedFor(): void
    {
        $local = new \DateTimeImmutable('2024-01-01T02:00:00+02:00');
        $ts = Timestamp::fromDateTime($local, keepOffset: true);
        self::assertSame(120, $ts->offsetMinutes);
        self::assertSame('c70eff0000000000000000659200800078', self::hex($ts));
        self::assertNull(Timestamp::fromDateTime($local)->offsetMinutes);

        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => Timestamp::fromDateTime(
            new \DateTimeImmutable('1900-01-01T00:00:00', new \DateTimeZone('Europe/Amsterdam')), // +00:19:32 LMT
            keepOffset: true,
        ));
    }

    public function testDateAndDateTimeValueClasses(): void
    {
        self::assertSame('d7fe0000018bcfe5687b', self::hex(new Date(1700000000123)));
        self::assertSame('d7fd17979cfe3d85cd15', self::hex(new DateTime(1700000000123456789)));
        $instant = new \DateTimeImmutable('2023-11-14T22:13:20.123456Z');
        self::assertSame(1700000000123, Date::fromDateTime($instant)->milliseconds);
        self::assertSame(1700000000123456000, DateTime::fromDateTime($instant)->nanoseconds);
        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => DateTime::fromDateTime(new \DateTimeImmutable('2263-01-01T00:00:00Z')));
    }

    // --- Objects ------------------------------------------------------------------------

    public function testObjectsThatEncodeAsMaps(): void
    {
        $object = new \stdClass();
        $object->name = 'Alice';
        $object->age = 30;
        self::assertSame('82a36167651ea46e616d65a5416c696365', self::hex($object));
        self::assertSame(self::hex(['age' => 30, 'name' => 'Alice']), self::hex(new \ArrayObject(['name' => 'Alice', 'age' => 30])));
    }

    public function testJsonSerializableEncodesWhatJsonSerializeReturns(): void
    {
        self::assertSame('9201a3726177', self::hex(new JsonValue([1, 'raw'])));
        self::assertSame('81a16101', self::hex(new JsonValue(['a' => 1])));
        self::assertSame('2a', self::hex(new JsonValue(42)));
        self::assertSame('81a16101', self::hex(new JsonValue(new JsonValue(['a' => 1])))); // nested
        self::assertSame('80', self::hex(new JsonValue(new \stdClass())));
    }

    public function testJsonSerializeIsCalledOncePerObjectEvenWhenTabularFallsBack(): void
    {
        $rows = [];
        for ($i = 0; $i < 10; ++$i) {
            $rows[] = new JsonValue(['id' => $i]);
        }
        $rows[] = new JsonValue('not a map'); // makes the tabular check fail at the end
        encode($rows);
        foreach ($rows as $row) {
            self::assertSame(1, $row->calls);
        }

        $table = [];
        for ($i = 0; $i < 10; ++$i) {
            $table[] = new JsonValue(['id' => $i]);
        }
        self::assertStringStartsWith('c7', self::hex($table)); // tabular
        foreach ($table as $row) {
            self::assertSame(1, $row->calls);
        }
    }

    public function testDateTimeInterfaceWinsOverJsonSerializable(): void
    {
        self::assertSame('d6ff65920080', self::hex(new JsonDate('2024-01-01T00:00:00Z')));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unsupportedValues(): iterable
    {
        yield 'closure' => [static fn () => 1];
        yield 'plain object' => [new \SplObjectStorage()];
        yield 'generator' => [(static function () { yield 1; })()];
        yield 'pure enum' => [Pure::Thing];
        yield 'nested pure enum' => [['x' => [Pure::Thing]]];
    }

    #[DataProvider('unsupportedValues')]
    public function testUnsupportedValuesRaiseInvalidArgument(mixed $value): void
    {
        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => encode($value));
    }

    public function testResourcesRaiseInvalidArgument(): void
    {
        $handle = fopen('php://memory', 'r');
        try {
            $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => encode(['h' => $handle]));
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function testExtensionsUseTheSmallestHeaderAndRejectBtoonDefinedIds(): void
    {
        self::assertSame('d401aa', self::hex(new Ext(1, "\xaa")));
        self::assertSame('c70000', self::hex(new Ext(0, '')));
        self::assertSame('d59c0102', self::hex(new Ext(-100, "\x01\x02")));
        // The ids BTOON defines can't be written as raw extensions (SPEC §5.7).
        foreach ([-1, -2, -3, -4, -5, -6, -10] as $type) {
            $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => encode(new Ext($type, "\x00")));
            $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => encode([new Ext($type, '')]));
        }
        // Every other id is written as given, including those reserved for future versions
        // (-9…-7 and -128…-11), so that documents using them round-trip.
        for ($type = -128; $type <= 127; ++$type) {
            if (($type >= -6 && $type <= -1) || $type === -10) {
                continue;
            }
            $bytes = encode(new Ext($type, "\x07"));
            self::assertSame("\xd4" . \chr($type & 0xff) . "\x07", $bytes, "id $type");
            self::assertEquals(new Ext($type, "\x07"), decode($bytes));
        }
        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => new Ext(128, ''));
        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => new Ext(-129, ''));
    }

    public function testVectorsAreBitExactIncludingNanPayloads(): void
    {
        $signalingNan = "\x01\x00\x80\x7f"; // float32 sNaN, must not be quieted
        $vector = VectorF32::fromBytes($signalingNan . "\x00\x00\x80\x3f");
        $bytes = encode($vector);
        self::assertSame('d7fb' . bin2hex($signalingNan) . '0000803f', bin2hex($bytes));
        $decoded = decode($bytes);
        self::assertInstanceOf(VectorF32::class, $decoded);
        self::assertSame($vector->toBytes(), $decoded->toBytes());
        self::assertCount(2, $decoded);
        self::assertSame(1.0, $decoded->toArray()[1]);

        self::assertSame('d7fa000000000000f03f', self::hex(new VectorF64([1])));
        self::assertSame('c700fa', self::hex(new VectorF64()));
        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => new VectorF32(['x']));
        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => VectorF64::fromBytes('1234567'));
    }

    // --- Tabular ----------------------------------------------------------------------

    public function testTabularCanBeDisabled(): void
    {
        $rows = [];
        for ($i = 0; $i < 8; ++$i) {
            $rows[] = ['ab' => 1];
        }
        self::assertStringStartsWith('c71ff6', self::hex($rows));
        self::assertSame('98' . str_repeat('81a2616201', 8), bin2hex(encode($rows, ['auto_tabular' => false])));
        // Nested arrays too.
        self::assertSame('91' . '98' . str_repeat('81a2616201', 8), bin2hex(encode([$rows], ['auto_tabular' => false])));
    }

    public function testTabularRowsMayMixMapRepresentations(): void
    {
        $rows = [];
        for ($i = 0; $i < 8; ++$i) {
            $row = ['id' => $i, 'name' => "user$i", 'active' => $i % 2 === 0];
            $rows[] = match ($i % 3) {
                0 => $row,
                1 => (object) $row,
                default => new \ArrayObject($row),
            };
        }
        $plain = array_map(static fn (int $i): array => ['id' => $i, 'name' => "user$i", 'active' => $i % 2 === 0], range(0, 7));
        self::assertSame(self::hex($plain), self::hex($rows));
    }

    public function testListsOfEmptyMapsAreNotTabular(): void
    {
        self::assertSame('92' . '8080', self::hex([new \stdClass(), new \stdClass()]));
    }

    // --- Depth and cycles -------------------------------------------------------------

    public function testMaxDepthMatchesTheDecoderRule(): void
    {
        $value = null;
        for ($i = 0; $i < 128; ++$i) {
            $value = [$value];
        }
        self::assertSame(str_repeat('91', 128) . 'c0', self::hex($value));
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode([$value]));

        self::assertSame('9191c0', bin2hex(encode([[null]], ['max_depth' => 2])));
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode([[[null]]], ['max_depth' => 2]));
        self::assertSame('c0', bin2hex(encode(null, ['max_depth' => 0])));
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode([], ['max_depth' => 0]));
        // At most 1000 (SPEC §7).
        self::assertSame(1000, EncodeOptions::MAX_DEPTH_LIMIT);
        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => new EncodeOptions(maxDepth: 1001));
        $deep = null;
        for ($i = 0; $i < 1000; ++$i) {
            $deep = [$deep];
        }
        self::assertSame(str_repeat('91', 1000) . 'c0', bin2hex(encode($deep, ['max_depth' => 1000])));
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode([$deep], ['max_depth' => 1000]));
    }

    public function testTabularCountsTwoLevels(): void
    {
        $rows = array_fill(0, 10, ['a' => 1]);
        self::assertStringStartsWith('c7', bin2hex(encode($rows, ['max_depth' => 2])));
        // One level left: the rows themselves would be too deep, tabular or not.
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode([$rows], ['max_depth' => 2]));
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode([$rows], ['max_depth' => 2, 'auto_tabular' => false]));
    }

    public function testCyclesBecomeMaxDepthErrors(): void
    {
        $object = new \stdClass();
        $object->self = $object;
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode($object));

        $array = ['x' => 1];
        $array['self'] = &$array;
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode($array));

        $loop = new JsonValue(null);
        $loop->value = [$loop];
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode($loop));

        $self = new JsonValue(null);
        $self->value = $self; // jsonSerialize() returns itself forever
        $this->expectBtoonError(ErrorCode::MaxDepth, static fn () => encode($self));
    }

    // --- API and options --------------------------------------------------------------

    public function testEncoderIsReusableAndReentrant(): void
    {
        $encoder = new Encoder();
        $inner = new JsonCallback(static fn (): string => bin2hex($encoder->encode(['inner' => 1])));
        self::assertSame(
            bin2hex(encode(['a' => 1, 'b' => bin2hex(encode(['inner' => 1]))])),
            bin2hex($encoder->encode(['a' => 1, 'b' => $inner])),
        );
        self::assertSame('81a16101', bin2hex($encoder->encode(['a' => 1])));
    }

    public function testOptionsAsArrayOrObject(): void
    {
        $rows = array_fill(0, 10, ['a' => 1]);
        $expected = bin2hex(encode($rows, ['auto_tabular' => false]));
        self::assertSame($expected, bin2hex(encode($rows, new EncodeOptions(autoTabular: false))));
        self::assertSame($expected, bin2hex((new Encoder(['auto_tabular' => false]))->encode($rows)));
        self::assertSame($expected, bin2hex(BTOON::encode($rows, ['auto_tabular' => false])));
        self::assertSame(Compression::Zstd, EncodeOptions::fromArray(['compression' => 'ZSTD'])->compression);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown key' => [['compres' => true]];
        yield 'decode-only key' => [['strict' => true]];
        yield 'compress not bool' => [['compress' => 1]];
        yield 'unknown algorithm' => [['compression' => 'gzip']];
        yield 'algorithm not string' => [['compression' => 0]];
        yield 'negative depth' => [['max_depth' => -1]];
        yield 'depth not int' => [['max_depth' => '128']];
        yield 'depth above 1000' => [['max_depth' => 1001]];
        yield 'empty hmac key' => [['hmac_key' => '']];
        yield 'hmac key not string' => [['hmac_key' => 123]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsRaiseInvalidArgument(array $options): void
    {
        $this->expectBtoonError(ErrorCode::InvalidArgument, static fn () => encode(1, $options));
    }

    public function testUnavailableCompressionIsReportedNotSkipped(): void
    {
        $this->expectBtoonError(
            ErrorCode::UnsupportedCompression,
            static fn () => encode('tiny', ['compress' => true, 'compression' => 'brotli']),
        );
        // Without compress => true the algorithm is irrelevant.
        self::assertSame('a474696e79', bin2hex(encode('tiny', ['compression' => 'brotli'])));
    }

    public function testRandomValuesRoundTripCanonically(): void
    {
        mt_srand(20261107);
        for ($i = 0; $i < 400; ++$i) {
            $value = self::randomValue(4);
            $bytes = encode($value);
            $again = encode(decode($bytes, ['timestamps' => 'object', 'binary_as_object' => true]));
            self::assertSame(bin2hex($bytes), bin2hex($again), 'iteration ' . $i);
        }
    }

    // --- helpers ----------------------------------------------------------------------

    private static function randomValue(int $depth): mixed
    {
        $pick = mt_rand(0, $depth > 0 ? 15 : 9);

        return match ($pick) {
            0 => null,
            1 => mt_rand(0, 1) === 1,
            2 => mt_rand(PHP_INT_MIN, PHP_INT_MAX) >> mt_rand(0, 63),
            3 => mt_rand(-40, 300),
            4 => [NAN, INF, -INF, -0.0, 0.0, 1.5, mt_rand() / 7][mt_rand(0, 6)],
            5 => ['', 'a', 'é', '日本', '🎉', str_repeat('x', mt_rand(0, 300))][mt_rand(0, 5)],
            6 => self::bytes(mt_rand(0, 40)),
            7 => new BigInt((mt_rand(0, 1) === 1 ? '-' : '') . mt_rand(1, 9) . str_repeat('7', mt_rand(17, 40))),
            8 => new Timestamp(mt_rand(-(2 ** 40), 2 ** 40), mt_rand(0, 999_999_999), mt_rand(0, 3) === 0 ? mt_rand(-1080, 1080) : null),
            9 => [new Ext([mt_rand(0, 127), -100, -7, -128][mt_rand(0, 3)], self::bytes(mt_rand(0, 20))), new VectorF32([1.5, -2]), new Date(mt_rand()), new DateTime(-mt_rand()), new Binary('x')][mt_rand(0, 4)],
            10, 11 => array_map(static fn () => self::randomValue($depth - 1), range(1, mt_rand(0, 6))),
            12, 13 => self::randomMap($depth - 1),
            default => array_map(static fn () => ['id' => mt_rand(), 'name' => 'n' . mt_rand(0, 9), 'v' => self::randomValue(0)], range(1, mt_rand(2, 12))),
        };
    }

    /**
     * Never empty: an empty map decodes to [] and re-encodes as an empty array (the
     * documented PHP ambiguity), which this round-trip property can't express.
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function randomMap(int $depth): array|\stdClass
    {
        $map = [];
        foreach (range(1, mt_rand(1, 6)) as $_) {
            $key = ['', 'a', 'b', '10', '9', '-1', 'é', 'key' . mt_rand(0, 99)][mt_rand(0, 7)];
            $map[$key] = self::randomValue($depth);
        }

        return $map === [] || array_is_list($map) ? (object) $map : $map;
    }

    private static function hex(mixed $value): string
    {
        return bin2hex(encode($value));
    }

    private static function bytes(int $length): string
    {
        return $length > 0 ? random_bytes($length) : '';
    }

    private function expectBtoonError(ErrorCode $code, callable $operation): void
    {
        try {
            $operation();
        } catch (BtoonException $e) {
            self::assertSame($code, $e->error, $e->getMessage());

            return;
        }
        self::fail('expected BtoonException ' . $code->value);
    }
}

final class JsonValue implements \JsonSerializable
{
    public int $calls = 0;

    public function __construct(public mixed $value)
    {
    }

    public function jsonSerialize(): mixed
    {
        ++$this->calls;

        return $this->value;
    }
}

final class JsonCallback implements \JsonSerializable
{
    /** @param \Closure(): mixed $callback */
    public function __construct(private \Closure $callback)
    {
    }

    public function jsonSerialize(): mixed
    {
        return ($this->callback)();
    }
}

final class JsonDate extends \DateTimeImmutable implements \JsonSerializable
{
    public function jsonSerialize(): string
    {
        return 'serialized';
    }
}

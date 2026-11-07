<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\BigInt;
use BTOON\Binary;
use BTOON\BTOON;
use BTOON\BtoonException;
use BTOON\Date;
use BTOON\DateTime;
use BTOON\DecodeOptions;
use BTOON\Decoder;
use BTOON\ErrorCode;
use BTOON\Ext;
use BTOON\Timestamp;
use BTOON\TimestampMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function BTOON\decode;
use function BTOON\encode;

final class DecoderTest extends TestCase
{
    public function testIntegerLikeKeysBecomeIntKeysAndRoundTrip(): void
    {
        $bytes = encode(['10' => 'a', 'x' => 'b', '-1' => 'c', '01' => 'd']);
        $decoded = decode($bytes);
        self::assertSame([-1 => 'c', '01' => 'd', 10 => 'a', 'x' => 'b'], $decoded);
        self::assertSame(bin2hex($bytes), bin2hex(encode($decoded)));
    }

    public function testLargeIntegersBecomeBigInt(): void
    {
        $value = decode(hex2bin('cfffffffffffffffff'));
        self::assertInstanceOf(BigInt::class, $value);
        self::assertSame('18446744073709551615', (string) $value);
        self::assertSame(PHP_INT_MAX, decode(hex2bin('cf7fffffffffffffff')));
        self::assertSame(PHP_INT_MIN, decode(hex2bin('d38000000000000000')));

        // A bigint extension decodes to the same logical integer as any other encoding.
        self::assertSame(5, decode(hex2bin('c701fc05')));
        self::assertSame(-128, decode(hex2bin('c703fcffff80')));
        $big = decode(hex2bin('c709fc010000000000000000'));
        self::assertInstanceOf(BigInt::class, $big);
        self::assertTrue($big->equals('18446744073709551616'));
        $uint = decode(hex2bin('c709fc008000000000000000'));
        self::assertInstanceOf(BigInt::class, $uint);
        self::assertSame('9223372036854775808', (string) $uint);
        // A huge bigint decodes in linear time; the decimal form is computed on demand.
        $huge = decode("\xc9" . pack('N', 100000) . "\xfc" . "\x7f" . str_repeat("\xff", 99999));
        self::assertInstanceOf(BigInt::class, $huge);
        self::assertSame(100000, \strlen($huge->toBytes()));
    }

    public function testTemporalTypesDefaultToDateTimeImmutable(): void
    {
        $ts = decode(hex2bin('d7ff7735940065920080')); // 2024-01-01T00:00:00.5Z
        self::assertInstanceOf(\DateTimeImmutable::class, $ts);
        self::assertSame('2024-01-01T00:00:00.500000+00:00', $ts->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('UTC', $ts->getTimezone()->getName());

        $withOffset = decode(hex2bin('c70eff0000000000000000659200800078'));
        self::assertInstanceOf(\DateTimeImmutable::class, $withOffset);
        self::assertSame('2024-01-01T02:00:00+02:00', $withOffset->format(\DATE_RFC3339));

        $negative = decode(hex2bin('c70cff075bcd15fffffff1886e0900'));
        self::assertInstanceOf(\DateTimeImmutable::class, $negative);
        self::assertSame('0001-01-01T00:00:00.123456+00:00', $negative->format('Y-m-d\TH:i:s.uP'));

        $date = decode(hex2bin('d7feffffffffffffffff')); // -1 ms
        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('1969-12-31T23:59:59.999000', $date->format('Y-m-d\TH:i:s.u'));

        $datetime = decode(hex2bin('d7fd17979cfe3d85cd15')); // nanoseconds are truncated to microseconds
        self::assertInstanceOf(\DateTimeImmutable::class, $datetime);
        self::assertSame('2023-11-14T22:13:20.123456', $datetime->format('Y-m-d\TH:i:s.u'));
    }

    public function testExtremeTimestampsStillDecode(): void
    {
        foreach ([PHP_INT_MIN, PHP_INT_MAX, -1, 2 ** 34] as $seconds) {
            $bytes = encode(new Timestamp($seconds, 999_999_999));
            $default = decode($bytes);
            self::assertInstanceOf(\DateTimeImmutable::class, $default);
            self::assertSame($seconds, $default->getTimestamp());
            self::assertSame('999999', $default->format('u'));
            self::assertEquals(new Timestamp($seconds, 999_999_999), decode($bytes, ['timestamps' => 'object']));
        }
    }

    public function testTimestampsAsObjectsAreLossless(): void
    {
        $options = new DecodeOptions(timestamps: TimestampMode::Object);
        self::assertEquals(new Timestamp(1704067200, 999, -330), decode(hex2bin('c70eff000003e70000000065920080feb6'), $options));
        self::assertEquals(new Date(-1), decode(hex2bin('d7feffffffffffffffff'), $options));
        self::assertEquals(new DateTime(1700000000123456789), decode(hex2bin('d7fd17979cfe3d85cd15'), $options));
        $ts = new Timestamp(1704067200, 123456789, 60);
        self::assertSame('2024-01-01T01:00:00.123456+01:00', $ts->toDateTimeImmutable()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testBinaryAsObject(): void
    {
        self::assertSame("\x01", decode(hex2bin('c40101')));
        self::assertEquals(new Binary("\x01"), decode(hex2bin('c40101'), ['binary_as_object' => true]));
        // Binary that happens to be valid UTF-8 keeps its type through a round trip only as an object.
        self::assertSame('c40161', bin2hex(encode(decode(hex2bin('c40161'), ['binary_as_object' => true]))));
        self::assertSame('a161', bin2hex(encode(decode(hex2bin('c40161')))));
    }

    public function testUnknownAndReservedExtensionsAreGeneric(): void
    {
        self::assertEquals(new Ext(-100, "\x01\x02"), decode(hex2bin('d59c0102')));
        self::assertEquals(new Ext(-7, ''), decode(hex2bin('c700f9')));
        self::assertEquals(new Ext(127, "\xaa"), decode(hex2bin('d47faa')));
    }

    public function testNonStrictModeRelaxesOnlyWhatTheSpecAllows(): void
    {
        $lax = ['strict' => false];
        self::assertSame(1, decode(hex2bin('0102'), $lax)); // trailing bytes ignored
        self::assertSame(['a' => 2], decode(hex2bin('82a16101a16102'), $lax)); // last wins
        self::assertSame("\xff", decode(hex2bin('a1ff'), $lax)); // no UTF-8 check
        self::assertSame(["\xff" => 1], decode(hex2bin('81a1ff01'), $lax));
        $table = hex2bin('c718f600000001000000010000000200000001ff02000000020102');
        self::assertSame([["\xff" => 1], ["\xff" => 2]], decode($table, $lax));

        // Bounds, depth, keys, tabular structure and extensions stay strict.
        foreach (['a561' => 'truncated', '9201' => 'truncated', 'c1' => 'invalid_marker', '810101' => 'non_string_key',
            'c703ff000000' => 'invalid_extension', 'c70cf6000000010000000000000005' => 'invalid_tabular',
            str_repeat('91', 129) . 'c0' => 'max_depth'] as $hex => $code) {
            $this->assertDecodeError($code, (string) hex2bin((string) $hex), $lax);
        }
        // Duplicate column names are malformed in either mode (SPEC §5.6.3).
        $payload = pack('NNN', 1, 2, 2) . pack('N', 1) . 'a' . pack('N', 1) . 'a' . "\x02\x02"
            . pack('N', 2) . "\x01\x02" . pack('N', 2) . "\x01\x02";
        $this->assertDecodeError('invalid_tabular', "\xc7" . \chr(\strlen($payload)) . "\xf6" . $payload, $lax);
    }

    public function testCustomMaxDepth(): void
    {
        self::assertSame([[null]], decode(hex2bin('9191c0'), ['max_depth' => 2]));
        $this->assertDecodeError('max_depth', (string) hex2bin('919191c0'), ['max_depth' => 2]);
        self::assertSame(1, decode("\x01", ['max_depth' => 0]));
        $this->assertDecodeError('max_depth', "\x90", ['max_depth' => 0]);
        // A tabular array is two levels: array and row maps.
        $table = encode(array_fill(0, 10, ['a' => 1]));
        self::assertCount(10, decode($table, ['max_depth' => 2]));
        $this->assertDecodeError('max_depth', $table, ['max_depth' => 1]);
        $this->assertDecodeError('max_depth', "\x91" . $table, ['max_depth' => 2]);
    }

    /** @return iterable<string, array{int}> */
    public static function markers(): iterable
    {
        for ($marker = 0; $marker <= 0xff; ++$marker) {
            yield sprintf('0x%02x', $marker) => [$marker];
        }
    }

    #[DataProvider('markers')]
    public function testEverySingleByteInputDecodesOrFailsCleanly(int $marker): void
    {
        try {
            $value = decode(\chr($marker));
        } catch (BtoonException $e) {
            self::assertContains($e->errorCode, $marker === 0xc1 ? ['invalid_marker'] : ['truncated']);

            return;
        }
        $expected = match (true) {
            $marker <= 0x7f => $marker,
            $marker >= 0xe0 => $marker - 256,
            $marker === 0x80, $marker === 0x90 => [],
            $marker === 0xa0 => '',
            $marker === 0xc0 => null,
            $marker === 0xc2 => false,
            $marker === 0xc3 => true,
            default => self::fail(sprintf('marker 0x%02x should not decode on its own', $marker)),
        };
        self::assertSame($expected, $value);
    }

    public function testErrorsReportTheirByteOffset(): void
    {
        try {
            decode(hex2bin('930102c1'));
            self::fail('expected an error');
        } catch (BtoonException $e) {
            self::assertSame(ErrorCode::InvalidMarker, $e->error);
            self::assertStringContainsString('at byte 3', $e->getMessage());
        }
    }

    public function testDecoderIsReusable(): void
    {
        $decoder = new Decoder(['timestamps' => 'object']);
        self::assertSame(['a' => 1], $decoder->decode(hex2bin('81a16101')));
        try {
            $decoder->decode(hex2bin('81a161'));
        } catch (BtoonException) {
        }
        self::assertSame([1, 2], $decoder->decode(hex2bin('920102')));
        self::assertSame(TimestampMode::Object, $decoder->getOptions()->timestamps);
        self::assertSame([1], BTOON::decode(hex2bin('9101')));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidOptions(): iterable
    {
        yield 'unknown key' => [['strikt' => false]];
        yield 'encode-only key' => [['compress' => true]];
        yield 'strict not bool' => [['strict' => 0]];
        yield 'negative size' => [['max_decompressed_size' => -1]];
        yield 'bad timestamps mode' => [['timestamps' => 'carbon']];
        yield 'empty hmac key' => [['hmac_key' => '']];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsRaiseInvalidArgument(array $options): void
    {
        $this->assertDecodeError('invalid_argument', "\x01", $options);
    }

    public function testTimestampsModeAcceptsStringsAndEnums(): void
    {
        self::assertSame(TimestampMode::Object, DecodeOptions::fromArray(['timestamps' => 'OBJECT'])->timestamps);
        self::assertSame(TimestampMode::DateTime, DecodeOptions::fromArray(['timestamps' => TimestampMode::DateTime])->timestamps);
    }

    /** @param array<string, mixed> $options */
    private function assertDecodeError(string $code, string $bytes, array $options = []): void
    {
        try {
            decode($bytes, $options);
        } catch (BtoonException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());

            return;
        }
        self::fail(sprintf('0x%s decoded, expected %s', bin2hex($bytes), $code));
    }
}

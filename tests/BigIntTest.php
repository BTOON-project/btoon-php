<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\BigInt;
use BTOON\BtoonException;
use BTOON\Internal\BigIntMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class BigIntTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function knownValues(): iterable
    {
        yield '0' => ['0', '00'];
        yield '1' => ['1', '01'];
        yield '-1' => ['-1', 'ff'];
        yield '127' => ['127', '7f'];
        yield '128' => ['128', '0080'];
        yield '-128' => ['-128', '80'];
        yield '-129' => ['-129', 'ff7f'];
        yield '255' => ['255', '00ff'];
        yield '256' => ['256', '0100'];
        yield '2^63-1' => ['9223372036854775807', '7fffffffffffffff'];
        yield '-2^63' => ['-9223372036854775808', '8000000000000000'];
        yield '2^63' => ['9223372036854775808', '008000000000000000'];
        yield '2^64' => ['18446744073709551616', '010000000000000000'];
        yield '-2^63-1' => ['-9223372036854775809', 'ff7fffffffffffffff'];
        yield '-2^64' => ['-18446744073709551616', 'ff0000000000000000'];
        yield '10^30' => ['1000000000000000000000000000000', '0c9f2c9cd04674edea40000000'];
        yield '-2^100' => ['-1267650600228229401496703205376', 'f0000000000000000000000000'];
    }

    #[DataProvider('knownValues')]
    public function testConversionsWithAndWithoutGmp(string $decimal, string $hex): void
    {
        $bytes = (string) hex2bin($hex);
        self::assertSame($hex, bin2hex(BigIntMath::decimalToBytes($decimal, false)));
        self::assertSame($decimal, BigIntMath::bytesToDecimal($bytes, false));
        if (\extension_loaded('gmp')) {
            self::assertSame($hex, bin2hex(BigIntMath::decimalToBytes($decimal, true)));
            self::assertSame($decimal, BigIntMath::bytesToDecimal($bytes, true));
        }
        self::assertSame($hex, bin2hex((new BigInt($decimal))->toBytes()));
        self::assertSame($decimal, BigInt::fromBytes($bytes)->toString());
    }

    #[RequiresPhpExtension('gmp')]
    public function testPurePhpMatchesGmpOnRandomValues(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 3000; ++$i) {
            $length = mt_rand(1, $i < 2500 ? 24 : 300);
            $bytes = '';
            for ($j = 0; $j < $length; ++$j) {
                $bytes .= \chr(mt_rand(0, 255));
            }
            $decimal = BigIntMath::bytesToDecimal($bytes, true);
            self::assertSame($decimal, BigIntMath::bytesToDecimal($bytes, false), bin2hex($bytes));
            $minimal = BigIntMath::minimal($bytes);
            self::assertSame(bin2hex($minimal), bin2hex(BigIntMath::decimalToBytes($decimal, false)), $decimal);
            self::assertSame(bin2hex($minimal), bin2hex(BigIntMath::decimalToBytes($decimal, true)), $decimal);
        }
    }

    public function testMinimalForm(): void
    {
        foreach (['000005' => '05', 'ffff80' => '80', 'ff7f' => 'ff7f', '0080' => '0080', '000080' => '0080',
            'ffffff' => 'ff', '000000' => '00', '7f' => '7f', 'ff' => 'ff', '00ff' => '00ff'] as $in => $out) {
            self::assertSame($out, bin2hex(BigIntMath::minimal((string) hex2bin((string) $in))), (string) $in);
        }
    }

    public function testNormalizationAndValidation(): void
    {
        self::assertSame('5', (string) new BigInt('+0005'));
        self::assertSame('0', (string) new BigInt('-0'));
        self::assertSame('0', (string) new BigInt('000'));
        self::assertSame('-12', (string) new BigInt('-012'));
        self::assertSame((string) PHP_INT_MIN, (string) new BigInt(PHP_INT_MIN));
        foreach (['', '-', '+', '1.5', ' 1', '1 ', '0x10', '1e5', '--1', '١٢'] as $invalid) {
            try {
                new BigInt($invalid);
                self::fail(var_export($invalid, true) . ' was accepted');
            } catch (BtoonException $e) {
                self::assertSame('invalid_argument', $e->errorCode);
            }
        }
        $this->expectException(BtoonException::class);
        BigInt::fromBytes('');
    }

    public function testComparisonsAndIntConversion(): void
    {
        $big = new BigInt('18446744073709551616');
        self::assertTrue($big->equals(BigInt::fromBytes("\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00")));
        self::assertTrue($big->equals('18446744073709551616'));
        self::assertFalse($big->equals(PHP_INT_MAX));
        self::assertTrue((new BigInt('-5'))->equals(-5));
        self::assertFalse($big->fitsInt());
        self::assertFalse($big->isNegative());
        self::assertTrue(BigInt::fromBytes("\xff\x00")->isNegative());
        self::assertSame(-256, BigInt::fromBytes("\xff\x00")->toInt());
        self::assertSame(PHP_INT_MAX, (new BigInt((string) PHP_INT_MAX))->toInt());
        $this->expectException(BtoonException::class);
        $big->toInt();
    }

    /**
     * toInt() describes a value that doesn't fit by its size once it is longer than 128 bits.
     * The message used to hold the whole decimal value: formatting a 100 KB bigint, which a
     * 100 KB document carries, took 15.6 s without ext-gmp (review 2026-11-13).
     */
    public function testToIntDescribesLargeValuesBySize(): void
    {
        $cases = [
            '2^64, decoded' => [BigInt::fromBytes("\x01" . str_repeat("\x00", 8)), '18446744073709551616 does not fit in a 64-bit PHP int'],
            '-2^127, decoded' => [BigInt::fromBytes("\x80" . str_repeat("\x00", 15)), '-170141183460469231731687303715884105728 does not fit in a 64-bit PHP int'],
            '100 KB, decoded' => [BigInt::fromBytes("\x7f" . str_repeat("\xab", 99_999)), 'an integer of 799999 bits does not fit in a 64-bit PHP int'],
            '-2^799999, decoded' => [BigInt::fromBytes("\x80" . str_repeat("\x00", 99_999)), 'an integer of 800000 bits does not fit in a 64-bit PHP int'],
            '39 digits' => [new BigInt('-' . str_repeat('9', 39)), '-' . str_repeat('9', 39) . ' does not fit in a 64-bit PHP int'],
            '2^63, 19 digits' => [new BigInt('9223372036854775808'), '9223372036854775808 does not fit in a 64-bit PHP int'],
            '100,001 digits' => [new BigInt('1' . str_repeat('0', 100_000)), 'an integer of 100001 digits does not fit in a 64-bit PHP int'],
        ];
        foreach ($cases as $case => [$value, $message]) {
            self::assertFalse($value->fitsInt(), $case);
            try {
                $value->toInt();
                self::fail("$case: toInt() returned");
            } catch (BtoonException $e) {
                self::assertSame('invalid_argument', $e->errorCode, $case);
                self::assertSame($message, $e->getMessage(), $case);
            }
        }
        self::assertSame(PHP_INT_MIN, (new BigInt((string) PHP_INT_MIN))->toInt());
        self::assertSame(PHP_INT_MAX, BigInt::fromBytes("\x00\x7f" . str_repeat("\xff", 7))->toInt());
        self::assertTrue((new BigInt('-0000000000000000000000001'))->fitsInt());
    }

    public function testBitLength(): void
    {
        foreach (['00' => 0, '01' => 1, 'ff' => 1, '7f' => 7, '0080' => 8, '80' => 8, 'ff7f' => 8, '00ff' => 8,
            '0100' => 9, '010000000000000000' => 65, '8000000000000000' => 64, '000000' => 0, 'ffff80' => 8] as $hex => $bits) {
            self::assertSame($bits, BigIntMath::bitLength((string) hex2bin((string) $hex)), (string) $hex);
        }
    }

    #[RequiresPhpExtension('gmp')]
    public function testGmpInterop(): void
    {
        $value = BigInt::fromGmp(gmp_pow(2, 200));
        self::assertSame(gmp_strval(gmp_pow(2, 200)), (string) $value);
        self::assertSame(0, gmp_cmp($value->toGmp(), gmp_pow(2, 200)));
        self::assertSame(0, gmp_cmp((new BigInt('-7'))->toGmp(), -7));
    }
}

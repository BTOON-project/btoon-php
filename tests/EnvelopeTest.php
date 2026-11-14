<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\BtoonException;
use BTOON\Compression;
use BTOON\DecodeOptions;
use BTOON\EncodeOptions;
use BTOON\Internal\Codec;
use BTOON\Internal\ZstdFrame;
use BTOON\Tests\Support\Vectors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function BTOON\decode;
use function BTOON\encode;

final class EnvelopeTest extends TestCase
{
    private const KEY = 'btoon-conformance-key-0123456789';

    /** @return list<array<string, mixed>> */
    private static function document(int $rows = 50): array
    {
        $out = [];
        for ($i = 0; $i < $rows; ++$i) {
            $out[] = ['id' => $i, 'name' => "user$i", 'email' => "user$i@example.com"];
        }

        return $out;
    }

    private static function envelope(int $algorithm, string $payload, int $size, int $version = 1, int $reserved = 0, ?int $compressed = null): string
    {
        return 'BTON' . pack('CCnNN', $version, $algorithm, $reserved, $compressed ?? \strlen($payload), $size) . $payload;
    }

    /**
     * One LZ4 sequence (lz4_Block_format.md): literals, then a match of $matchLength bytes
     * from $offset bytes back, or no match when $offset is null.
     */
    private static function lz4Sequence(string $literals, ?int $offset = null, int $matchLength = 4): string
    {
        $length = static fn (int $n): string => $n < 15 ? '' : str_repeat("\xff", intdiv($n - 15, 255)) . \chr(($n - 15) % 255);
        $match = $offset === null ? 0 : $matchLength - 4;
        $sequence = \chr(min(\strlen($literals), 15) << 4 | min($match, 15)) . $length(\strlen($literals)) . $literals;

        return $offset === null ? $sequence : $sequence . pack('v', $offset) . $length($match);
    }

    // --- Compression --------------------------------------------------------------------

    public function testZlibIsTheDefaultAlgorithm(): void
    {
        $value = self::document();
        $plain = encode($value);
        $packed = encode($value, ['compress' => true]);
        self::assertSame('BTON', substr($packed, 0, 4));
        /** @var array{v: int, a: int, r: int, c: int, u: int} $header */
        $header = unpack('Cv/Ca/nr/Nc/Nu', $packed, 4);
        self::assertSame(['v' => 1, 'a' => 0, 'r' => 0, 'c' => \strlen($packed) - 16, 'u' => \strlen($plain)], $header);
        self::assertSame($plain, gzuncompress(substr($packed, 16)));
        self::assertSame(decode($plain), decode($packed));
    }

    public function testSmallOrIncompressibleDocumentsStayUncompressed(): void
    {
        $small = ['a' => str_repeat('x', 200)];
        self::assertSame(encode($small), encode($small, ['compress' => true]));
        mt_srand(7);
        $noise = '';
        for ($i = 0; $i < 4000; ++$i) {
            $noise .= \chr(mt_rand(0, 255));
        }
        $value = new \BTOON\Binary($noise);
        self::assertSame(encode($value), encode($value, ['compress' => true]));
    }

    public function testZlibPayloadMustBeExactAndComplete(): void
    {
        $plain = encode(self::document());
        $z = gzcompress($plain, 6);
        self::assertSame(decode($plain), decode(self::envelope(0, $z, \strlen($plain))));

        $cases = [
            'declared size too big' => self::envelope(0, $z, \strlen($plain) + 1),
            'declared size too small' => self::envelope(0, $z, \strlen($plain) - 1),
            'trailing bytes after the stream' => self::envelope(0, $z . "\x00", \strlen($plain)),
            'two concatenated streams' => self::envelope(0, $z . $z, \strlen($plain)),
            'truncated stream' => self::envelope(0, substr($z, 0, -5), \strlen($plain)),
            'bad checksum' => self::envelope(0, substr($z, 0, -1) . \chr(\ord($z[-1]) ^ 1), \strlen($plain)),
            'raw deflate, not zlib' => self::envelope(0, gzdeflate($plain), \strlen($plain)),
            'gzip, not zlib' => self::envelope(0, gzencode($plain), \strlen($plain)),
            'empty payload' => self::envelope(0, '', \strlen($plain)),
            'preset dictionary' => self::envelope(0, self::zlibWithDictionary($plain), \strlen($plain)),
            // FDICT set with DICTID 1, the Adler-32 of an empty dictionary, which Go's
            // compress/zlib accepted: FDICT is invalid_envelope whatever the DICTID (SPEC §6).
            'FDICT with the empty dictionary\'s id' => self::envelope(0, "\x78\x20\x00\x00\x00\x01" . substr(gzcompress('hello'), 2), 5),
            'FDICT with another FLEVEL' => self::envelope(0, "\x78\xbb\x00\x00\x00\x01" . substr(gzcompress('hello'), 2), 5),
            'compressed_size mismatch' => self::envelope(0, $z, \strlen($plain), compressed: \strlen($z) - 1),
            'version 0' => self::envelope(0, $z, \strlen($plain), version: 0),
            'reserved bits' => self::envelope(0, $z, \strlen($plain), reserved: 0x8000),
        ];
        foreach ($cases as $name => $bytes) {
            $this->assertDecodeError('invalid_envelope', $bytes, [], $name);
        }
    }

    public function testDecompressionBombsStopNearTheDeclaredSize(): void
    {
        // 256 MiB of zeros in ~250 KiB, built in 1 MiB steps so the test itself stays small.
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        self::assertNotFalse($deflate);
        $zeros = str_repeat("\x00", 1 << 20);
        $bomb = '';
        for ($i = 0; $i < 256; ++$i) {
            $bomb .= (string) deflate_add($deflate, $zeros, $i === 255 ? ZLIB_FINISH : ZLIB_NO_FLUSH);
        }
        unset($zeros);
        self::assertLessThan(300_000, \strlen($bomb));

        foreach ([1000, 1 << 20, 8 << 20] as $declared) {
            $envelope = self::envelope(0, $bomb, $declared);
            memory_reset_peak_usage();
            $baseline = memory_get_usage();
            $this->assertDecodeError('invalid_envelope', $envelope);
            $used = memory_get_peak_usage() - $baseline;
            // Output may overshoot the declared size by one input slice (~16 KiB of output).
            self::assertLessThan($declared + (1 << 20), $used, "inflation was not bounded for $declared bytes");
        }

        // Declared sizes above the limit are rejected before anything is inflated.
        $this->assertDecodeError('size_limit', self::envelope(0, $bomb, 256 << 20), ['max_decompressed_size' => (256 << 20) - 1]);
    }

    public function testSizeLimitOption(): void
    {
        $plain = encode(self::document());
        $packed = encode(self::document(), ['compress' => true]);
        self::assertSame(decode($plain), decode($packed, ['max_decompressed_size' => \strlen($plain)]));
        $this->assertDecodeError('size_limit', $packed, ['max_decompressed_size' => \strlen($plain) - 1]);
        $this->assertDecodeError('size_limit', $packed, ['max_decompressed_size' => 0]);
        // The default is 64 MiB (SPEC §7.1; 256 MiB before 0.2), checked before inflating.
        // Under phpunit.xml.dist's memory_limit of 1G it isn't lowered (LimitsTest).
        $z = gzcompress($plain);
        self::assertSame(64 << 20, (new DecodeOptions())->maxDecompressedSize);
        $this->assertDecodeError('size_limit', self::envelope(0, $z, (64 << 20) + 1));
        $this->assertDecodeError('invalid_envelope', self::envelope(0, $z, 64 << 20));
    }

    public function testEnvelopeDetectionRules(): void
    {
        $packed = encode(self::document(), ['compress' => true]);
        $this->assertDecodeError('invalid_envelope', $packed, ['auto_decompress' => false]);
        // Shorter than 16 bytes: a bare document (66 followed by trailing bytes).
        $this->assertDecodeError('trailing_data', 'BTON' . str_repeat("\x00", 11));
        // Envelopes don't nest.
        $inner = encode(self::document(), ['compress' => true]);
        $this->assertDecodeError('trailing_data', self::envelope(0, (string) gzcompress($inner), \strlen($inner)));
    }

    /** @return iterable<string, array{Compression}> */
    public static function algorithms(): iterable
    {
        foreach (Compression::cases() as $algorithm) {
            yield $algorithm->value => [$algorithm];
        }
    }

    #[DataProvider('algorithms')]
    public function testEachAlgorithmRoundTripsOrIsReportedUnsupported(Compression $algorithm): void
    {
        $value = self::document();
        if (!$algorithm->isAvailable()) {
            self::assertNotContains($algorithm, Compression::available());
            $this->assertEncodeError('unsupported_compression', $value, ['compress' => true, 'compression' => $algorithm]);
            $this->assertDecodeError('unsupported_compression', self::envelope($algorithm->id(), 'xx', 100));

            return;
        }
        self::assertContains($algorithm, Compression::available());
        $packed = encode($value, ['compress' => true, 'compression' => $algorithm->value]);
        self::assertSame($algorithm->id(), \ord($packed[5]));
        self::assertSame(decode(encode($value)), decode($packed));
        // A wrong declared size is caught by every codec.
        $declared = unpack('N', $packed, 12)[1];
        $this->assertDecodeError('invalid_envelope', substr_replace($packed, pack('N', $declared + 1), 12, 4));
        $this->assertDecodeError('invalid_envelope', substr_replace($packed, pack('N', $declared - 1), 12, 4));
    }

    public function testUnknownAlgorithmIds(): void
    {
        self::assertNull(Compression::fromId(5));
        $this->assertDecodeError('unsupported_compression', self::envelope(5, 'xx', 1));
        $this->assertDecodeError('unsupported_compression', self::envelope(255, 'xx', 1));
        self::assertTrue(Compression::Zlib->isAvailable());
        self::assertFalse(Compression::Brotli->isAvailable(), 'ext-brotli cannot bound decompression (see Codec)');
    }

    // --- Zstandard frame validation (pure PHP, runs without ext-zstd) -------------------------

    public function testZstdFrameValidationOnTheConformanceVector(): void
    {
        [$payload, $size] = self::vectorPayload('compression/zstd');
        $chunks = ZstdFrame::chunks($payload, $size);
        self::assertSame(\strlen($payload), $chunks[\count($chunks) - 1]);
        self::assertSame($chunks, array_values(array_unique($chunks)));

        $bad = [
            'trailing byte' => [$payload . "\x00", $size],
            'second frame' => [$payload . $payload, $size],
            'truncated' => [substr($payload, 0, -1), $size],
            'content size mismatch' => [$payload, $size + 1],
            'not zstd' => ["\x28\xb5\x2f\xfe" . substr($payload, 4), $size],
            'reserved bit' => [substr_replace($payload, \chr(\ord($payload[4]) | 0x08), 4, 1), $size],
            'skippable frame' => ["\x50\x2a\x4d\x18" . pack('V', 0), 0],
        ];
        foreach ($bad as $name => [$frame, $declared]) {
            try {
                ZstdFrame::chunks($frame, $declared);
                self::fail("$name was accepted");
            } catch (BtoonException $e) {
                self::assertSame('invalid_envelope', $e->errorCode, $name);
            }
        }
    }

    public function testZstdFrameValidationOnHandBuiltFrames(): void
    {
        // Single segment, 1-byte content size, one raw last block of 3 bytes, no checksum.
        $frame = "\x28\xb5\x2f\xfd" . "\x20" . "\x03" . self::blockHeader(true, 0, 3) . 'abc';
        self::assertSame([\strlen($frame)], ZstdFrame::chunks($frame, 3));
        // Two blocks (RLE then raw) plus a checksum: the checksum joins the last chunk.
        $frame = "\x28\xb5\x2f\xfd" . "\x24" . "\x09" . self::blockHeader(false, 1, 5) . 'x' . self::blockHeader(true, 0, 4) . 'abcd' . 'CSUM';
        self::assertSame([10, 17 + 4], ZstdFrame::chunks($frame, 9));
        // Raw/RLE blocks that regenerate more than the declared size are rejected up front.
        $frame = "\x28\xb5\x2f\xfd" . "\x00" . "\x00" . self::blockHeader(true, 1, 131072) . 'x';
        $this->assertZstdError($frame, 1000);
        // Reserved block type, or a dictionary id.
        $this->assertZstdError("\x28\xb5\x2f\xfd" . "\x20" . "\x03" . self::blockHeader(true, 3, 3) . 'abc', 3);
        $this->assertZstdError("\x28\xb5\x2f\xfd" . "\x21" . "\x07" . "\x03" . self::blockHeader(true, 0, 3) . 'abc', 3);
    }

    public function testZstdWindowIsBounded(): void
    {
        // Window_Descriptor = exponent << 3 (window 2^(10 + exponent)); no content size field.
        $frame = static fn (int $exponent): string => "\x28\xb5\x2f\xfd" . "\x00" . \chr($exponent << 3)
            . self::blockHeader(true, 0, 3) . 'abc';
        self::assertSame([\strlen($frame(13))], ZstdFrame::chunks($frame(13), 3)); // 8 MiB: always allowed
        $this->assertZstdError($frame(14), 3); // 16 MiB window for 3 bytes of content
        // A 16 MiB window is fine once the declared content is that large.
        self::assertSame([\strlen($frame(14))], ZstdFrame::chunks($frame(14), 16 << 20));
        $this->assertZstdError($frame(18), 200 << 20); // 256 MiB window: above the 128 MiB cap
        // Single-segment frames use the content size as their window.
        $big = "\x28\xb5\x2f\xfd" . "\xa0" . pack('V', 200 << 20) . self::blockHeader(true, 0, 3) . 'abc';
        $this->assertZstdError($big, 200 << 20);
    }

    /** zstd frames from python-zstandard: streamed without content size (8 flushed blocks) and one-shot, both with checksums. */
    private const ZSTD_STREAMED = '28b52ffd0458040200c40281a4726f7773dc002882a2696400a46e616d65a5757365723082a26964013102320333043405a46e616d65a508002008d803968115600f2c035638db7402840100a8757365723506360737083809390aa6310ba67573650d0020423277c0186801eb0030c004c001c60008600780b1e00dec0100c872310ca6310da6310ea6310fa63110a63111a46e616d65a67510002a2302e2e058cab48075200c654e801d34763222200e8e994c0b5807c24a9769039c0100b07365723112a63113a63114321532163217a46e616d650d004008d8038d0128401c2c6b9916b00e84b1cc09b083c65682c902ec000080a632183219321a321b321c321da46e610c500100621b14dba0d85e03fc0000986d321e331f332033213322333482a2696423a40b500100dba0d806c5362cb40000586e3324332533263327333909500100dba0d806051101000001bb9b30';
    private const ZSTD_ONESHOT = '28b52ffd64a701a50400a40681a4726f7773dc002882a2696400a46e616d65a575736572300131023203330434053506360737083809390aa6310b310c320d330e340f351036113712381339143230153116321733183419351a361b371c381d391e33301f31203221332234233524362537263827394fa811e05bff37f0a535109012530ffab75ef0dcabffba99ffea0d26d94845c5881821c2b41a20ab01bb9b30';

    public function testZstdFramesWithChecksumsAndWithoutContentSize(): void
    {
        $expected = ['rows' => array_map(static fn (int $i): array => ['id' => $i, 'name' => "user$i"], range(0, 39))];
        foreach ([self::ZSTD_STREAMED, self::ZSTD_ONESHOT] as $hex) {
            $frame = (string) hex2bin($hex);
            $chunks = ZstdFrame::chunks($frame, 679); // structure checks need no extension
            self::assertSame(\strlen($frame), $chunks[\count($chunks) - 1]);
            if (!Compression::Zstd->isAvailable()) {
                continue;
            }
            self::assertSame($expected, decode(self::envelope(2, $frame, 679)));
            $badChecksum = substr($frame, 0, -1) . \chr(\ord($frame[-1]) ^ 0x01);
            $this->assertDecodeError('invalid_envelope', self::envelope(2, $badChecksum, 679));
            $this->assertDecodeError('invalid_envelope', self::envelope(2, $frame, 678));
        }
        self::assertGreaterThan(5, \count(ZstdFrame::chunks((string) hex2bin(self::ZSTD_STREAMED), 679)));
    }

    public function testLz4AndSnappyRejectMalformedPayloads(): void
    {
        $plain = encode(self::document());
        $size = \strlen($plain);
        if (Compression::Lz4->isAvailable()) {
            $block = (string) lz4_compress_raw($plain);
            self::assertSame(decode($plain), decode(self::envelope(1, $block, $size)));
            $this->assertDecodeError('invalid_envelope', self::envelope(1, $block . "\x00", $size));
            $this->assertDecodeError('invalid_envelope', self::envelope(1, substr($block, 0, -2), $size));
            $this->assertDecodeError('invalid_envelope', self::envelope(1, 'x', 1000)); // impossible ratio
        }
        if (Compression::Snappy->isAvailable()) {
            $raw = (string) snappy_compress($plain);
            self::assertSame(decode($plain), decode(self::envelope(4, $raw, $size)));
            $this->assertDecodeError('invalid_envelope', self::envelope(4, $raw, $size + 1)); // preamble mismatch
            $this->assertDecodeError('invalid_envelope', self::envelope(4, $raw . "\x00", $size));
            $this->assertDecodeError('invalid_envelope', self::envelope(4, "\xff\xff\xff\xff\xff\xff", 1));
        }
        if (!Compression::Lz4->isAvailable() && !Compression::Snappy->isAvailable()) {
            self::markTestSkipped('needs ext-lz4 (>= 0.8) or ext-snappy');
        }
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function lz4Blocks(): iterable
    {
        // A block without a match is one run of literals, valid at any length.
        foreach ([0, 1, 4, 5, 14, 15, 16, 269, 270, 271, 525, 1000] as $n) {
            $block = self::lz4Sequence(str_repeat('x', $n));
            yield "$n literals" => [$block, $n, true];
            yield "$n literals, size + 1" => [$block, $n + 1, false];
            if ($n > 0) {
                yield "$n literals, size - 1" => [$block, $n - 1, false];
            }
        }
        $typical = self::lz4Sequence('abcdefgh', 8, 20) . self::lz4Sequence(str_repeat('z', 12));
        yield 'literals, a match, literals' => [$typical, 40, true];
        yield 'a long overlapping match' => [self::lz4Sequence('a', 1, 300) . self::lz4Sequence('bbbbb'), 306, true];
        yield 'offset back to the first byte' => [self::lz4Sequence('abcd', 4, 4) . self::lz4Sequence(str_repeat('z', 12)), 20, true];
        yield 'offset 0' => [self::lz4Sequence('abcd', 0, 4) . self::lz4Sequence(str_repeat('z', 12)), 20, false];
        yield 'offset before the start' => [self::lz4Sequence('abcd', 5, 4) . self::lz4Sequence(str_repeat('z', 12)), 20, false];
        // A match starts at least 12 bytes before the end and ends at least 5 before it.
        yield 'match 12 bytes before the end' => [self::lz4Sequence('ab', 1, 4) . self::lz4Sequence(str_repeat('c', 8)), 14, true];
        yield 'match 11 bytes before the end' => [self::lz4Sequence('ab', 1, 4) . self::lz4Sequence(str_repeat('c', 7)), 13, false];
        yield '5 literals after the match' => [self::lz4Sequence('ab', 1, 7) . self::lz4Sequence('ccccc'), 14, true];
        yield '4 literals after the match' => [self::lz4Sequence('ab', 1, 10) . self::lz4Sequence('cccc'), 16, false];
        yield 'ends with a match' => [self::lz4Sequence(str_repeat('a', 20), 1, 4), 32, false];
        yield 'ends with a match and an empty sequence' => [self::lz4Sequence(str_repeat('a', 20), 1, 4) . "\x00", 24, false];
        // The final token's match-length bits are ignored, also in an empty block.
        yield 'match bits in the final token' => ["\x5f" . 'aaaaa', 5, true];
        yield 'empty, match bits set' => ["\x0f", 0, true];
        yield 'no block' => ['', 0, false];
        yield 'a byte after the block' => [$typical . "\x00", 40, false];
        yield 'literal length past the input' => ["\xf0\xff\xff\xff" . 'abc', 800, false];
        yield 'match length cut off' => ["\x4f" . 'aaaa' . "\x01\x00", 100, false];
        yield 'offset cut off' => ["\x40" . 'aaaa' . "\x01", 100, false];
        for ($cut = 1; $cut < \strlen($typical); ++$cut) {
            yield "first $cut bytes of a block" => [substr($typical, 0, $cut), 40, false];
        }
    }

    /**
     * SPEC §6: an LZ4 block must be valid under the block format, end-of-block conditions
     * included, and no match offset may be 0 or reach back before the start of the output.
     * liblz4, which ext-lz4 uses, skips some of these checks on its fast path, so the block's
     * structure is checked before ext-lz4 decodes it. The check needs no extension.
     */
    #[DataProvider('lz4Blocks')]
    public function testLz4BlockStructure(string $block, int $size, bool $valid): void
    {
        try {
            Codec::checkLz4Block($block, $size);
            self::assertTrue($valid, 'accepted');
        } catch (BtoonException $e) {
            self::assertFalse($valid, $e->getMessage());
            self::assertSame('invalid_envelope', $e->errorCode);
        }
    }

    /** Every lz4-block vector, through the structure check alone. */
    public function testLz4BlockVectorsWithoutTheExtension(): void
    {
        $checked = 0;
        foreach (Vectors::all() as $vector) {
            if (!str_starts_with((string) $vector->id, 'lz4-block/')) {
                continue;
            }
            $bytes = Vectors::bytes($vector);
            /** @var array{1: int} $size */
            $size = unpack('N', $bytes, 12);
            try {
                Codec::checkLz4Block(substr($bytes, 16), $size[1]);
                self::assertFalse(isset($vector->error), "$vector->id: accepted");
            } catch (BtoonException $e) {
                self::assertSame($vector->error ?? null, $e->errorCode, "$vector->id: {$e->getMessage()}");
            }
            ++$checked;
        }
        self::assertSame(8, $checked);
    }

    /** What LZ4 compressors write always passes the structure check (needs ext-lz4). */
    public function testLz4CompressorOutputPassesTheStructureCheck(): void
    {
        if (!Compression::Lz4->isAvailable()) {
            self::markTestSkipped('needs ext-lz4 (>= 0.8)');
        }
        mt_srand(11);
        $inputs = [encode(self::document(300)), str_repeat('a', 100_000), str_repeat("\x00\x01\x02", 30_000)];
        for ($i = 0; $i < 300; ++$i) {
            $input = '';
            $length = mt_rand(1, $i < 250 ? 300 : 20_000);
            while (\strlen($input) < $length) {
                $input .= mt_rand(0, 2) === 0 ? random_bytes(mt_rand(1, 20)) : str_repeat(\chr(mt_rand(97, 100)), mt_rand(1, 40));
            }
            $inputs[] = substr($input, 0, $length);
        }
        foreach ($inputs as $input) {
            foreach ([0, 9, 12] as $level) {
                $block = (string) lz4_compress_raw($input, $level);
                Codec::checkLz4Block($block, \strlen($input));
                self::assertSame($input, Codec::decompress(Compression::Lz4, $block, \strlen($input)));
            }
        }
    }

    // --- Signed envelope ---------------------------------------------------------------

    public function testSignedEnvelopeLayout(): void
    {
        $plain = encode(['a' => 1]);
        $signed = encode(['a' => 1], ['hmac_key' => self::KEY]);
        self::assertSame("BTSG\x01\x01\x00\x00", substr($signed, 0, 8));
        self::assertSame(hash_hmac('sha256', substr($signed, 0, 8) . $plain, self::KEY, true), substr($signed, 8, 32));
        self::assertSame($plain, substr($signed, 40));
        self::assertSame(['a' => 1], decode($signed, ['hmac_key' => self::KEY]));
    }

    public function testSignatureWrapsCompression(): void
    {
        $value = self::document();
        $bytes = encode($value, ['compress' => true, 'hmac_key' => self::KEY]);
        self::assertSame('BTSG', substr($bytes, 0, 4));
        self::assertSame('BTON', substr($bytes, 40, 4));
        self::assertSame(decode(encode($value)), decode($bytes, ['hmac_key' => self::KEY]));
    }

    public function testSignatureFailures(): void
    {
        $signed = encode(['a' => 1], ['hmac_key' => self::KEY]);
        $this->assertDecodeError('authentication_failed', $signed); // no key
        $this->assertDecodeError('authentication_failed', $signed, ['hmac_key' => strrev(self::KEY)]);
        $this->assertDecodeError('authentication_failed', encode(['a' => 1]), ['hmac_key' => self::KEY]);
        $tampered = $signed;
        $tampered[-1] = \chr(\ord($tampered[-1]) ^ 1);
        $this->assertDecodeError('authentication_failed', $tampered, ['hmac_key' => self::KEY]);
        $this->assertDecodeError('invalid_envelope', substr($signed, 0, 39), ['hmac_key' => self::KEY]);
        $this->assertDecodeError('invalid_envelope', substr_replace($signed, "\x02", 4, 1), ['hmac_key' => self::KEY]);
        $this->assertDecodeError('invalid_envelope', substr_replace($signed, "\x01", 7, 1), ['hmac_key' => self::KEY]);
        // Short "BTSG…" input without a key is just a bare document (66 + trailing bytes).
        $this->assertDecodeError('trailing_data', 'BTSG' . str_repeat("\x00", 10));
    }

    public function testEmptyHmacKeyIsAnErrorNotNoKey(): void
    {
        // SPEC §8: an empty key is a configuration error. "No key" is null.
        $signed = encode(['a' => 1], ['hmac_key' => self::KEY]);
        $attempts = [
            'EncodeOptions' => static fn () => new EncodeOptions(hmacKey: ''),
            'DecodeOptions' => static fn () => new DecodeOptions(hmacKey: ''),
            'encode' => static fn () => encode(['a' => 1], ['hmac_key' => '']),
            'encode, compressed' => static fn () => encode(self::document(), ['compress' => true, 'hmac_key' => '']),
            'decode of signed input' => static fn () => decode($signed, ['hmac_key' => '']),
            'decode of unsigned input' => static fn () => decode(encode(['a' => 1]), ['hmac_key' => '']),
        ];
        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                self::fail("$case: accepted an empty key");
            } catch (BtoonException $e) {
                self::assertSame('invalid_argument', $e->errorCode, $case);
            }
        }
        self::assertSame(['a' => 1], decode(encode(['a' => 1], ['hmac_key' => null]), ['hmac_key' => null]));
        self::assertSame(['a' => 1], decode(encode(['a' => 1]), new DecodeOptions(hmacKey: null)));
    }

    // --- helpers ----------------------------------------------------------------------

    private static function blockHeader(bool $last, int $type, int $size): string
    {
        $value = ($size << 3) | ($type << 1) | ($last ? 1 : 0);

        return \chr($value & 0xff) . \chr(($value >> 8) & 0xff) . \chr(($value >> 16) & 0xff);
    }

    private static function zlibWithDictionary(string $data): string
    {
        $context = deflate_init(ZLIB_ENCODING_DEFLATE, ['dictionary' => 'user@example.com']);
        self::assertNotFalse($context);
        $out = deflate_add($context, $data, ZLIB_FINISH);
        self::assertIsString($out);

        return $out;
    }

    /** @return array{string, int} */
    private static function vectorPayload(string $id): array
    {
        foreach (Vectors::all() as $vector) {
            if ($vector->id === $id) {
                $bytes = Vectors::bytes($vector);

                return [substr($bytes, 16), unpack('N', $bytes, 12)[1]];
            }
        }
        self::fail("vector $id not found");
    }

    private function assertZstdError(string $frame, int $size): void
    {
        try {
            ZstdFrame::chunks($frame, $size);
        } catch (BtoonException $e) {
            self::assertSame('invalid_envelope', $e->errorCode);

            return;
        }
        self::fail('zstd frame was accepted: 0x' . bin2hex($frame));
    }

    /** @param array<string, mixed> $options */
    private function assertDecodeError(string $code, string $bytes, array $options = [], string $case = ''): void
    {
        try {
            decode($bytes, $options);
        } catch (BtoonException $e) {
            self::assertSame($code, $e->errorCode, trim($case . ': ' . $e->getMessage(), ': '));

            return;
        }
        self::fail(sprintf('%s: decoded, expected %s', $case, $code));
    }

    /** @param array<string, mixed> $options */
    private function assertEncodeError(string $code, mixed $value, array $options): void
    {
        try {
            encode($value, $options);
        } catch (BtoonException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());

            return;
        }
        self::fail('encoded, expected ' . $code);
    }
}

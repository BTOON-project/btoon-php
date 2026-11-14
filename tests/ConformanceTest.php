<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\BTOON;
use BTOON\BtoonException;
use BTOON\DecodeOptions;
use BTOON\MapMode;
use BTOON\Tests\Support\TypedValue;
use BTOON\Tests\Support\Vectors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function BTOON\decode;
use function BTOON\encode;

use const BTOON\SPEC_VERSION;
use const BTOON\VERSION;

/**
 * Runs the conformance vectors as SPEC §10 and IMPLEMENTATION-GUIDE §1 require:
 * every non-error vector decodes to its value, every canonical vector re-encodes to
 * exactly its bytes, every error vector fails with its error code, and every
 * encode_error vector's value is refused by the encoder with its code. Each vector is
 * decoded with its options.
 */
final class ConformanceTest extends TestCase
{
    /** @return iterable<string, array{\stdClass}> */
    public static function decodeVectors(): iterable
    {
        foreach (Vectors::all() as $vector) {
            if (!isset($vector->error) && !isset($vector->encode_error)) {
                yield (string) $vector->id => [$vector];
            }
        }
    }

    /** @return iterable<string, array{\stdClass}> */
    public static function canonicalVectors(): iterable
    {
        foreach (Vectors::all() as $vector) {
            if (!isset($vector->error) && !isset($vector->encode_error) && ($vector->canonical ?? false) === true) {
                yield (string) $vector->id => [$vector];
            }
        }
    }

    /** @return iterable<string, array{\stdClass}> */
    public static function encodeErrorVectors(): iterable
    {
        foreach (Vectors::all() as $vector) {
            if (isset($vector->encode_error)) {
                yield (string) $vector->id => [$vector];
            }
        }
    }

    /** @return iterable<string, array{\stdClass}> */
    public static function errorVectors(): iterable
    {
        foreach (Vectors::all() as $vector) {
            if (isset($vector->error)) {
                yield (string) $vector->id => [$vector];
            }
        }
    }

    public function testVectorFileTargetsThisSpecVersion(): void
    {
        $document = Vectors::document();
        self::assertSame(SPEC_VERSION, $document->spec);
        self::assertSame(SPEC_VERSION, BTOON::SPEC_VERSION);
        self::assertSame(VERSION, BTOON::VERSION);
        self::assertStringStartsWith(SPEC_VERSION . '.', VERSION); // SPEC §12: 0.N.x implements 0.N
        $defaults = new DecodeOptions();
        self::assertSame($defaults->maxDepth, $document->defaults->max_depth);
        self::assertSame($defaults->strict, $document->defaults->strict);
        // SPEC's size defaults. DecodeOptions lowers them when memory_limit is low
        // (LimitsTest), so the vectors are decoded with the file's defaults given explicitly.
        self::assertSame(DecodeOptions::DEFAULT_MAX_DECOMPRESSED_SIZE, $document->defaults->max_decompressed_size);
        self::assertSame(DecodeOptions::DEFAULT_MAX_VALUES, $document->defaults->max_values);
    }

    #[DataProvider('decodeVectors')]
    public function testDecodesToTheVectorValue(\stdClass $vector): void
    {
        $this->skipUnlessCodecAvailable($vector);
        $bytes = Vectors::bytes($vector);
        $options = Vectors::options($vector);

        // Lossless mode: Timestamp/Date/DateTime objects, Binary, and maps that PHP would
        // turn into lists as ArrayObject.
        $precise = decode($bytes, $options + ['timestamps' => 'object', 'binary_as_object' => true]);
        self::assertNull(TypedValue::diff($vector->value, $precise, true, MapMode::Auto));

        // Default PHP mapping: DateTimeImmutable, plain strings, and maps => 'auto'.
        $default = decode($bytes, $options);
        self::assertNull(TypedValue::diff($vector->value, $default, false, MapMode::Auto));

        // Maps always as arrays, as 0.1.x did by default.
        $arrays = decode($bytes, $options + ['maps' => 'array']);
        self::assertNull(TypedValue::diff($vector->value, $arrays, false, MapMode::Array));

        // Every map as an ArrayObject.
        $objects = decode($bytes, $options + ['maps' => 'object']);
        self::assertNull(TypedValue::diff($vector->value, $objects, false, MapMode::Object));
    }

    #[DataProvider('canonicalVectors')]
    public function testReencodesCanonicalVectorsByteForByte(\stdClass $vector): void
    {
        $value = TypedValue::build($vector->value);

        self::assertSame($vector->hex, bin2hex(encode($value, Vectors::encodeOptions($vector))));
        // What the default options decode re-encodes to the same bytes: maps => 'auto' keeps
        // maps and arrays apart (binaries need binary_as_object, timestamps 'object').
        $decoded = decode(Vectors::bytes($vector), Vectors::options($vector) + ['timestamps' => 'object', 'binary_as_object' => true]);
        self::assertSame($vector->hex, bin2hex(encode($decoded, Vectors::encodeOptions($vector))));
    }

    #[DataProvider('encodeErrorVectors')]
    public function testRefusesToEncodeEncodeErrorVectors(\stdClass $vector): void
    {
        $value = TypedValue::build($vector->value);
        try {
            $bytes = encode($value);
        } catch (BtoonException $e) {
            self::assertSame($vector->encode_error, $e->errorCode, $e->getMessage());

            return;
        }
        self::fail(sprintf('vector %s encoded to %s, expected %s', $vector->id, bin2hex($bytes), $vector->encode_error));
    }

    #[DataProvider('errorVectors')]
    public function testRejectsErrorVectorsWithTheirCode(\stdClass $vector): void
    {
        $this->skipUnlessCodecAvailable($vector);
        try {
            decode(Vectors::bytes($vector), Vectors::options($vector));
        } catch (BtoonException $e) {
            self::assertSame($vector->error, $e->errorCode, $e->getMessage());
            self::assertSame($vector->error, $e->getErrorCode());
            self::assertSame($vector->error, $e->error->value);

            return;
        }
        self::fail(sprintf('vector %s decoded, expected %s', $vector->id, $vector->error));
    }

    private function skipUnlessCodecAvailable(\stdClass $vector): void
    {
        $codec = Vectors::requiredCodec($vector);
        if ($codec !== null && !$codec->isAvailable()) {
            self::markTestSkipped(sprintf('%s needs %s, which is not available here', $vector->id, $codec->value));
        }
    }
}

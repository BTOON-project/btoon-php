<?php

declare(strict_types=1);

namespace BTOON\Tests;

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

/**
 * Runs the conformance vectors as SPEC §10 and IMPLEMENTATION-GUIDE §1 require:
 * every non-error vector decodes to its value, every canonical vector re-encodes to
 * exactly its bytes, and every error vector fails with its error code.
 */
final class ConformanceTest extends TestCase
{
    /** @return iterable<string, array{\stdClass}> */
    public static function decodeVectors(): iterable
    {
        foreach (Vectors::all() as $vector) {
            if (!isset($vector->error)) {
                yield (string) $vector->id => [$vector];
            }
        }
    }

    /** @return iterable<string, array{\stdClass}> */
    public static function canonicalVectors(): iterable
    {
        foreach (Vectors::all() as $vector) {
            if (!isset($vector->error) && ($vector->canonical ?? false) === true) {
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
        $defaults = new DecodeOptions();
        self::assertSame($defaults->maxDepth, $document->defaults->max_depth);
        self::assertSame($defaults->maxDecompressedSize, $document->defaults->max_decompressed_size);
        self::assertSame($defaults->strict, $document->defaults->strict);
    }

    #[DataProvider('decodeVectors')]
    public function testDecodesToTheVectorValue(\stdClass $vector): void
    {
        $this->skipUnlessCodecAvailable($vector);
        $bytes = Vectors::bytes($vector);
        $options = Vectors::options($vector);

        // Lossless mode: Timestamp/Date/DateTime objects, Binary, and maps that PHP would
        // turn into lists as ArrayObject.
        $precise = decode($bytes, $options + ['timestamps' => 'object', 'binary_as_object' => true, 'maps' => 'auto']);
        self::assertNull(TypedValue::diff($vector->value, $precise, true, MapMode::Auto));

        // Default PHP mapping: DateTimeImmutable, plain strings and arrays.
        $default = decode($bytes, $options);
        self::assertNull(TypedValue::diff($vector->value, $default, false));

        // Every map as an ArrayObject.
        $objects = decode($bytes, $options + ['maps' => 'object']);
        self::assertNull(TypedValue::diff($vector->value, $objects, false, MapMode::Object));
    }

    #[DataProvider('canonicalVectors')]
    public function testReencodesCanonicalVectorsByteForByte(\stdClass $vector): void
    {
        $value = TypedValue::build($vector->value);
        $options = Vectors::options($vector); // hmac_key: canonical signed vectors are signed on encode

        self::assertSame($vector->hex, bin2hex(encode($value, $options)));
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

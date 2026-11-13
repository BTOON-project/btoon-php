<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\Binary;
use BTOON\Ext;
use BTOON\MapMode;
use BTOON\Tests\Support\TypedValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function BTOON\decode;
use function BTOON\encode;

/**
 * Extra canonical cases produced by btoon-core's independent Python encoder
 * (tests/Fixtures/generate-reference-cases.py). They cover what the conformance
 * vectors don't: every tabular type code, UTF-8 column order inside a tabular
 * payload, header-size boundaries, and large containers.
 */
final class ReferenceCasesTest extends TestCase
{
    /** @return iterable<string, array{\stdClass}> */
    public static function cases(): iterable
    {
        $json = file_get_contents(__DIR__ . '/Fixtures/reference-cases.json');
        self::assertIsString($json);
        $document = json_decode($json, false, 4096, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);
        foreach ($document->cases as $case) {
            yield (string) $case->id => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testMatchesThePythonReferenceEncoder(\stdClass $case): void
    {
        if (isset($case->recipe)) {
            $value = self::recipe((string) $case->recipe, (int) $case->n);
            $bytes = encode($value);
            self::assertSame($case->length, \strlen($bytes));
            self::assertSame($case->sha256, hash('sha256', $bytes));
            // Decoding and re-encoding must reproduce the canonical bytes.
            self::assertSame($bytes, encode(decode($bytes, ['binary_as_object' => true])));

            return;
        }

        $value = TypedValue::build($case->value);
        self::assertSame($case->hex, bin2hex(encode($value)));
        $bytes = hex2bin((string) $case->hex);
        self::assertIsString($bytes);
        self::assertNull(TypedValue::diff($case->value, decode($bytes, ['timestamps' => 'object', 'binary_as_object' => true]), true, MapMode::Auto));
        self::assertNull(TypedValue::diff($case->value, decode($bytes), false, MapMode::Auto));
        self::assertNull(TypedValue::diff($case->value, decode($bytes, ['maps' => 'array']), false, MapMode::Array));
    }

    private static function recipe(string $kind, int $n): mixed
    {
        switch ($kind) {
            case 'str':
                return str_repeat('x', $n);
            case 'bin':
                return new Binary(str_repeat("\x00", $n));
            case 'array':
                return array_fill(0, $n, 0);
            case 'map':
                $map = [];
                for ($i = 0; $i < $n; ++$i) {
                    $map[sprintf('k%05d', $i)] = $i;
                }

                return $map;
            case 'ext':
                return new Ext(1, str_repeat("\x00", $n));
            case 'table':
                $rows = [];
                for ($i = 0; $i < $n; ++$i) {
                    $rows[] = ['id' => $i, 'name' => "user$i"];
                }

                return $rows;
        }
        throw new \UnexpectedValueException("unknown recipe $kind");
    }
}

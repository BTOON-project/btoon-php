<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\BtoonException;
use BTOON\Decoder;
use BTOON\Tests\Support\Vectors;
use PHPUnit\Framework\TestCase;

/**
 * Randomized robustness test: mutated vectors (and random noise) must either decode or
 * fail with a BtoonException. Any other Throwable, and any PHP warning, notice or
 * deprecation, fails the test.
 *
 * Iterations default to 30 per vector and option set; override with BTOON_MUTATIONS.
 */
final class MutationTest extends TestCase
{
    private const SEED = 0xb7004;
    private const KEY = 'btoon-conformance-key-0123456789';

    public function testMutatedVectorsNeverFailOutsideBtoonException(): void
    {
        $perVector = (int) (getenv('BTOON_MUTATIONS') ?: 30);
        mt_srand(self::SEED);
        $decoders = [
            new Decoder(),
            new Decoder(['strict' => false, 'timestamps' => 'object', 'binary_as_object' => true]),
            new Decoder(['hmac_key' => self::KEY]),
            new Decoder(['max_depth' => 4, 'max_decompressed_size' => 4096]),
        ];
        $seeds = array_map(Vectors::bytes(...), array_values(array_filter(Vectors::all(), static fn (\stdClass $v): bool => isset($v->hex))));
        // Valid zlib envelopes around mutated documents reach the decoder through decompression.
        $wrapped = [];
        foreach ($seeds as $seed) {
            if ($seed !== '' && !str_starts_with($seed, 'BT')) {
                $wrapped[] = $seed;
            }
        }

        $runs = 0;
        $outcomes = ['ok' => 0];
        $this->withStrictErrors(function () use ($seeds, $wrapped, $decoders, $perVector, &$runs, &$outcomes): void {
            foreach ($seeds as $index => $seed) {
                foreach ($decoders as $decoder) {
                    for ($i = 0; $i < $perVector; ++$i) {
                        $this->decodeOne($decoder, self::mutate($seed), "vector #$index", $outcomes);
                        ++$runs;
                    }
                }
            }
            foreach ($wrapped as $document) {
                for ($i = 0; $i < $perVector; ++$i) {
                    $mutated = self::mutate($document);
                    $z = (string) gzcompress($mutated);
                    $envelope = 'BTON' . pack('CCnNN', 1, 0, 0, \strlen($z), \strlen($mutated)) . $z;
                    $this->decodeOne($decoders[0], $envelope, 'zlib-wrapped mutation', $outcomes);
                    ++$runs;
                }
            }
            for ($i = 0; $i < 20 * $perVector; ++$i) {
                $this->decodeOne($decoders[$i % 4], self::noise(mt_rand(0, 48)), 'noise', $outcomes);
                ++$runs;
            }
        });

        self::assertGreaterThan(10000, $runs);
        // Both outcomes must actually occur, or the mutations are too weak or too strong.
        self::assertGreaterThan($runs / 50, $outcomes['ok']);
        self::assertGreaterThan(8, \count($outcomes), 'expected most error codes to be exercised: ' . json_encode($outcomes));
        fwrite(STDERR, sprintf("\n[mutation] %d decodes: %s\n", $runs, json_encode($outcomes)));
    }

    /** @param array<string, int> $outcomes */
    private function decodeOne(Decoder $decoder, string $bytes, string $origin, array &$outcomes): void
    {
        try {
            $decoder->decode($bytes);
            ++$outcomes['ok'];
        } catch (BtoonException $e) {
            $outcomes[$e->errorCode] = ($outcomes[$e->errorCode] ?? 0) + 1;
        } catch (\Throwable $e) {
            self::fail(sprintf(
                "%s: %s escaped for input 0x%s: %s\n%s",
                $origin,
                $e::class,
                bin2hex($bytes),
                $e->getMessage(),
                $e->getTraceAsString(),
            ));
        }
    }

    /** Runs $test with every PHP error turned into a failure (even @-suppressed ones). */
    private function withStrictErrors(callable $test): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException("PHP error during decode: $message", 0, $severity, $file, $line);
        });
        try {
            $test();
        } finally {
            restore_error_handler();
        }
    }

    private static function mutate(string $input): string
    {
        $operations = mt_rand(1, 3);
        for ($n = 0; $n < $operations; ++$n) {
            $length = \strlen($input);
            $at = $length > 0 ? mt_rand(0, $length - 1) : 0;
            switch (mt_rand(0, 8)) {
                case 0: // flip one bit
                    if ($length > 0) {
                        $input[$at] = \chr(\ord($input[$at]) ^ (1 << mt_rand(0, 7)));
                    }
                    break;
                case 1: // random byte
                    if ($length > 0) {
                        $input[$at] = \chr(mt_rand(0, 255));
                    }
                    break;
                case 2: // boundary byte (lengths, markers)
                    if ($length > 0) {
                        $input[$at] = \chr([0x00, 0x01, 0x7f, 0x80, 0xc1, 0xdd, 0xdf, 0xf6, 0xfc, 0xff][mt_rand(0, 9)]);
                    }
                    break;
                case 3: // truncate
                    $input = substr($input, 0, mt_rand(0, $length));
                    break;
                case 4: // insert a byte
                    $input = substr($input, 0, $at) . \chr(mt_rand(0, 255)) . substr($input, $at);
                    break;
                case 5: // delete a byte
                    $input = substr($input, 0, $at) . substr($input, $at + 1);
                    break;
                case 6: // duplicate a slice
                    $input = substr($input, 0, $at) . substr($input, $at, mt_rand(1, 8)) . substr($input, $at);
                    break;
                case 7: // overwrite four bytes with a big length
                    $input = substr($input, 0, $at) . pack('N', [0xffffffff, 0x7fffffff, 0x10000, 0x100][mt_rand(0, 3)]) . substr($input, $at + 4);
                    break;
                default: // append garbage
                    $input .= self::noise(mt_rand(1, 4));
            }
        }

        return $input;
    }

    private static function noise(int $length): string
    {
        $bytes = '';
        for ($i = 0; $i < $length; ++$i) {
            $bytes .= \chr(mt_rand(0, 255));
        }

        return $bytes;
    }
}

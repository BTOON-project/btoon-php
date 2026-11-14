<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\Decoder;
use PHPUnit\Framework\TestCase;

use function BTOON\decode;
use function BTOON\encode;

/**
 * PHP hashes array keys with DJBX33A and no secret seed, so keys can be chosen to share one
 * hash ("Ez" and "FY" hash alike, and so does every string of such blocks), and then every key
 * added to an array is compared with all the keys before it (review 2026-11-13). Within the
 * value budget, such keys must cost about what ordinary keys cost wherever the decoder can
 * avoid hashing them; a map itself is bounded by max_map_entries (LimitsTest).
 *
 * The timing tests compare colliding keys with ordinary keys of the same length in the same
 * document shape. Before these fixes the colliding documents took 8 to 19 times as long.
 */
final class HashCollisionTest extends TestCase
{
    /**
     * A wide table's rows are copies of a template row filled in column order, so its names
     * are hashed once per table, not once per row: 4,096 colliding names × 250 rows took 54 s
     * in the review, and 4 s on a fast machine.
     */
    public function testWideTablesWithCollidingNamesDecodeInLinearTime(): void
    {
        $rows = 128;
        $this->assertNotSlower(
            static fn (): mixed => decode(self::table(self::colliding(1024), $rows), ['max_values' => 1 << 20]),
            static fn (): mixed => decode(self::table(self::distinct(1024), $rows), ['max_values' => 1 << 20]),
        );
    }

    /**
     * Keys validated as UTF-8 were cached in a PHP array of up to 4,096 keys, so with colliding
     * keys every lookup walked up to 4,096 of them: 131,072 one-entry maps took 0.7 s instead
     * of 0.04 s. The cache now holds 64.
     */
    public function testTheKeyCacheDoesNotAmplifyCollisions(): void
    {
        $maps = static function (array $keys): string {
            $doc = "\xdd" . pack('N', 65_535);
            for ($i = 0; $i < 65_535; ++$i) {
                $doc .= "\x81\xb8" . $keys[$i % 4096] . "\xc0";
            }

            return $doc;
        };
        $colliding = $maps(self::colliding(4096));
        $distinct = $maps(self::distinct(4096));
        $this->assertNotSlower(
            static fn (): mixed => decode($colliding, ['max_values' => 1 << 17]),
            static fn (): mixed => decode($distinct, ['max_values' => 1 << 17]),
        );
    }

    /**
     * A table without rows counts as one value, so its names weren't bounded by max_values:
     * 61 MB of empty tables of 1,024 colliding names took 4.6 s (0.7 s now). Such a table now
     * compares keyed digests of its names, which can't be chosen to collide.
     */
    public function testEmptyTablesWithCollidingNamesDecodeInLinearTime(): void
    {
        $tables = static fn (array $names): string => "\xdc" . pack('n', 256) . str_repeat(self::table($names, 0), 256);
        $colliding = $tables(self::colliding(1024));
        $distinct = $tables(self::distinct(1024));
        self::assertSame(array_fill(0, 256, []), decode($colliding));
        $this->assertNotSlower(static fn (): mixed => decode($colliding), static fn (): mixed => decode($distinct));
    }

    /**
     * The encoder cached encoded keys in a PHP array of up to 4,096 keys too: re-encoding
     * 1,023 decoded maps of 1,024 colliding keys took 6.8 s instead of 0.4 s.
     */
    public function testTheEncoderKeyCacheDoesNotAmplifyCollisions(): void
    {
        $maps = static function (array $pool): array {
            $maps = [];
            for ($m = 0; $m < 256; ++$m) {
                $map = [];
                for ($i = 0; $i < 512; ++$i) {
                    $map[$pool[($m * 7 + $i) % 4096]] = $i; // a different window each time: not a table
                }
                $maps[] = $map;
            }

            return $maps;
        };
        $colliding = $maps(self::colliding(4096));
        $distinct = $maps(self::distinct(4096));
        $this->assertNotSlower(static fn (): string => encode($colliding), static fn (): string => encode($distinct));
    }

    /**
     * Wide tables build their rows differently from narrow ones; both give the same rows:
     * keys in column order, integer-like names as int keys, and no PHP references left over
     * from filling the template (they would cost 32 bytes per cell).
     */
    public function testWideTableRows(): void
    {
        foreach ([3, 16, 17, 40] as $width) {
            $names = array_map(static fn (int $c): string => $c % 3 === 0 ? (string) (100 - $c) : "col$c", range(0, $width - 1));
            $records = [];
            for ($r = 0; $r < 5; ++$r) {
                $row = [];
                foreach ($names as $c => $name) {
                    $row[$name] = match ($c % 4) {
                        0 => $r * 100 + $c,
                        1 => "s$r.$c",
                        2 => [$r, $c],
                        default => null,
                    };
                }
                $records[] = $row;
            }
            $bytes = encode($records);
            self::assertSame("\xf6", $bytes[match ($bytes[0]) { "\xc7" => 2, "\xc8" => 3, default => 5 }], "$width columns: tabular");
            $decoded = decode($bytes);
            self::assertIsArray($decoded);
            self::assertCount(5, $decoded);
            foreach ($decoded as $r => $row) {
                // The encoder sorts the columns, so compare the keys in that order.
                $expected = $records[$r];
                ksort($expected, SORT_STRING);
                self::assertSame($expected, $row, "$width columns, row $r");
                self::assertSame(array_keys($expected), array_keys($row));
                ob_start();
                debug_zval_dump($row);
                self::assertStringNotContainsString('reference', (string) ob_get_clean(), "$width columns, row $r");
            }
            self::assertSame(bin2hex($bytes), bin2hex(encode($decoded)));
            // Every row as an ArrayObject.
            $objects = decode($bytes, ['maps' => 'object']);
            self::assertIsArray($objects);
            self::assertContainsOnlyInstancesOf(\ArrayObject::class, $objects);
            self::assertSame(bin2hex($bytes), bin2hex(encode($objects)));
        }

        // Rows that PHP would hold as lists (columns "0"…"n-1" in that order, which only a
        // non-canonical table has when it is this wide) are ArrayObject with maps => 'auto'.
        $listLike = self::table(array_map(strval(...), range(0, 19)), 3);
        $decoded = decode($listLike);
        self::assertIsArray($decoded);
        self::assertCount(3, $decoded);
        self::assertContainsOnlyInstancesOf(\ArrayObject::class, $decoded);
        self::assertSame(array_fill(0, 20, null), $decoded[0]->getArrayCopy());
        $lists = decode($listLike, ['maps' => 'array']);
        self::assertSame(array_fill(0, 3, array_fill(0, 20, null)), $lists);
        ob_start();
        debug_zval_dump($lists);
        self::assertStringNotContainsString('reference', (string) ob_get_clean());

        // A reused decoder gives the same rows each time.
        $decoder = new Decoder();
        $table = self::table(self::colliding(64), 3);
        self::assertSame($decoder->decode($table), $decoder->decode($table));
    }

    /**
     * Runs both in turn, five times, and fails when the first's best time is more than three
     * times the second's, plus 30 ms for timer noise on fast runs. Alternating them makes a
     * busy machine slow both down alike.
     *
     * @param \Closure(): mixed $colliding
     * @param \Closure(): mixed $ordinary
     */
    private function assertNotSlower(\Closure $colliding, \Closure $ordinary): void
    {
        $time = static function (\Closure $run): float {
            gc_collect_cycles();
            $start = hrtime(true);
            $run();

            return (hrtime(true) - $start) / 1e9;
        };
        $ordinaryTime = $collidingTime = INF;
        for ($i = 0; $i < 5; ++$i) {
            $ordinaryTime = min($ordinaryTime, $time($ordinary));
            $collidingTime = min($collidingTime, $time($colliding));
        }
        self::assertLessThan(
            3 * $ordinaryTime + 0.03,
            $collidingTime,
            sprintf('colliding keys took %.3f s, ordinary keys %.3f s', $collidingTime, $ordinaryTime),
        );
    }

    /**
     * $n strings of equal length that share one PHP hash: each is a sequence of "Ez" and "FY"
     * blocks, which DJBX33A hashes alike.
     *
     * @return list<string>
     */
    private static function colliding(int $n): array
    {
        $blocks = (int) ceil(log($n, 2));
        $keys = [];
        for ($i = 0; $i < $n; ++$i) {
            $key = '';
            for ($b = 0; $b < $blocks; ++$b) {
                $key .= ($i >> $b) & 1 ? 'Ez' : 'FY';
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * $n ordinary strings of the same length as {@see colliding()}'s.
     *
     * @return list<string>
     */
    private static function distinct(int $n): array
    {
        $length = 2 * (int) ceil(log($n, 2));

        return array_map(static fn (int $i): string => substr(md5("key $i"), 0, $length), range(0, $n - 1));
    }

    /**
     * A tabular extension with these column names and $rows rows of nil.
     *
     * @param list<string> $names
     */
    private static function table(array $names, int $rows): string
    {
        $payload = pack('NNN', 1, \count($names), $rows);
        foreach ($names as $name) {
            $payload .= pack('N', \strlen($name)) . $name;
        }
        $payload .= str_repeat("\x00", \count($names));
        $payload .= str_repeat(pack('N', $rows) . str_repeat("\xc0", $rows), \count($names));

        return "\xc9" . pack('N', \strlen($payload)) . "\xf6" . $payload;
    }
}

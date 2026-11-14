<?php

declare(strict_types=1);

namespace BTOON\Tests;

use BTOON\BtoonException;
use BTOON\DecodeOptions;
use BTOON\Decoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function BTOON\decode;
use function BTOON\encode;

/**
 * SPEC 0.2's decoder resource limits (§5.6.3, §7.1): `max_values`, the bound on a table's
 * rows × column-name bytes, and their defaults under PHP's memory_limit.
 */
final class LimitsTest extends TestCase
{
    /** Values counted: a value counts once, when it starts. Map keys don't count. */
    public function testMaxValuesCountsEveryValue(): void
    {
        $this->assertValues('c0', 1);
        $this->assertValues('90', 1);
        $this->assertValues('93010203', 4);
        $this->assertValues('9291019102', 5);
        $this->assertValues('82a161c0a162c0', 3); // the map and its two values
        $this->assertValues('81a1619101', 3); // map, array, element
        $this->assertValues('81a161a0', 2); // a string value counts, its key doesn't
        $this->assertValues('92d40100d6ff65920080', 3); // a generic extension and a timestamp
        $this->assertValues('93c701fc05d7fe0000000000000000c700fb', 4); // bigint, date, float32 vector

        // A value counts before its marker is examined: the third value exceeds the limit
        // before 0xc1 is rejected, and the array before its elements are looked at.
        $this->assertDecodeError('size_limit', '9201c1', ['max_values' => 2]);
        $this->assertDecodeError('invalid_marker', '9201c1', ['max_values' => 3]);
        $this->assertDecodeError('size_limit', '91c1', ['max_values' => 1]);
        // Even the top-level value counts.
        $this->assertDecodeError('size_limit', 'c0', ['max_values' => 0]);
        $this->assertDecodeError('size_limit', 'c1', ['max_values' => 0]);
    }

    /**
     * A table counts once when it starts, its N rows together once its header is checked,
     * and each cell when it starts. Column names don't count.
     */
    public function testMaxValuesInTables(): void
    {
        // 1 array + 4 rows + 2 × 4 cells. The second column's last cell is the one over.
        $two = self::table(4, ['a', 'b'], ["\x01\x02\x03\x04", "\x05\x06\x07\x08"]);
        $this->assertValues($two, 13);
        // The budget carries on after the table.
        $this->assertValues('93' . $two . 'c0c0', 16);
        // Rows count before any cell: with room for the array and its rows but not the
        // first cell, the bad cell is never reached.
        $bad = self::table(4, ['a'], ["\xc1\x02\x03\x04"]);
        $this->assertDecodeError('size_limit', $bad, ['max_values' => 5]);
        $this->assertDecodeError('size_limit', $bad, ['max_values' => 1]);
        $this->assertDecodeError('invalid_marker', $bad, ['max_values' => 6]);
        // Cells inside cells count too, and an empty table is just the array.
        $this->assertValues(self::table(2, ['k'], ["\x92\x01\x02\x92\x03\x04"]), 1 + 2 + 2 + 4);
        $this->assertValues(self::table(0, ['a'], ['']), 1);
        // The same with every map as an ArrayObject.
        self::assertCount(4, decode((string) hex2bin($two), ['max_values' => 13, 'maps' => 'object']));
    }

    /** The values inside a compression envelope count; the envelope doesn't. */
    public function testMaxValuesInsideEnvelopes(): void
    {
        $values = array_map(static fn (int $i): int => $i % 7, range(1, 1000));
        $envelope = encode($values, ['compress' => true]);
        self::assertStringStartsWith('BTON', $envelope);
        self::assertSame($values, decode($envelope, ['max_values' => 1001]));
        $this->assertDecodeError('size_limit', bin2hex($envelope), ['max_values' => 1000]);
        $key = str_repeat('k', 32);
        $signed = encode($values, ['compress' => true, 'hmac_key' => $key]);
        $this->assertDecodeError('size_limit', bin2hex($signed), ['max_values' => 1000, 'hmac_key' => $key]);
    }

    /** Every decode starts with a full budget, also when a Decoder is reused. */
    public function testEachDecodeHasItsOwnBudget(): void
    {
        $decoder = new Decoder(['max_values' => 3]);
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame([1, 2], $decoder->decode((string) hex2bin('920102')));
        }
        try {
            $decoder->decode((string) hex2bin('93010203'));
            self::fail('expected size_limit');
        } catch (BtoonException $e) {
            self::assertSame('size_limit', $e->errorCode);
            self::assertStringContainsString('max_values (3)', $e->getMessage());
        }
        self::assertSame([1, 2], $decoder->decode((string) hex2bin('920102')));
        self::assertSame(3, $decoder->getOptions()->maxValues);
    }

    /**
     * A table's rows × column-name bytes may not exceed max_decompressed_size, because the
     * decoder copies the names into every row (SPEC §5.6.3, §7.1).
     */
    public function testTableColumnNameBytes(): void
    {
        $name = str_repeat('k', 1 << 16);
        $spec = ['max_decompressed_size' => DecodeOptions::DEFAULT_MAX_DECOMPRESSED_SIZE];
        // 1,024 rows × 64 KiB = 64 MiB: accepted.
        self::assertCount(1024, decode((string) hex2bin(self::table(1024, [$name], [str_repeat("\xc0", 1024)])), $spec));
        // One more row is over it, from 66 KB of input.
        $over = self::table(1025, [$name], [str_repeat("\xc0", 1025)]);
        $this->assertDecodeError('size_limit', $over, $spec);
        self::assertCount(1025, decode((string) hex2bin($over), ['max_decompressed_size' => 1025 << 16]));
        // The names' total counts: 2 rows × (4 + 4) bytes.
        $two = self::table(2, ['abcd', 'efgh'], ["\xc0\xc0", "\xc0\xc0"]);
        self::assertCount(2, decode((string) hex2bin($two), ['max_decompressed_size' => 16]));
        $this->assertDecodeError('size_limit', $two, ['max_decompressed_size' => 15]);
        // Each table is bounded on its own, also inside a cell.
        $outer = self::table(1, ['x'], [(string) hex2bin(self::table(2, ['abcd'], ["\xc0\xc0"]))]);
        self::assertCount(1, decode((string) hex2bin($outer), ['max_decompressed_size' => 8]));
        $this->assertDecodeError('size_limit', $outer, ['max_decompressed_size' => 7]);
    }

    /**
     * After the names and type codes: rows × columns against the remaining payload
     * (invalid_tabular), then the column-name bytes, then the row count (SPEC §5.6.3).
     */
    public function testTableHeaderCheckOrder(): void
    {
        // 2,000 rows over 64 MiB of names, but only 1,500 cell bytes.
        $short = self::table(2000, [str_repeat('k', 1 << 16)], [str_repeat("\xc0", 1500)]);
        $this->assertDecodeError('invalid_tabular', $short);
        // Over both the name bytes and max_values: the name bytes are checked first.
        $two = self::table(2, ['abcd', 'efgh'], ["\xc0\xc0", "\xc0\xc0"]);
        $this->assertDecodeError('size_limit', $two, ['max_decompressed_size' => 15, 'max_values' => 1], 'max_decompressed_size');
        $this->assertDecodeError('size_limit', $two, ['max_values' => 2], 'max_values');
    }

    /** @return iterable<string, array{list<string>, string, int}> */
    public static function columnNames(): iterable
    {
        $cases = [
            'duplicate before invalid UTF-8' => [['a', 'a', "\xff"], 'invalid_tabular'],
            'invalid UTF-8 before duplicate' => [["\xff", 'a', 'a'], 'invalid_utf8'],
            'invalid UTF-8 between duplicates' => [['a', "\xff", 'a'], 'invalid_utf8'],
            'duplicate of the first name' => [['a', 'b', 'a'], 'invalid_tabular'],
            'names a replacing decoder would merge' => [["\xff", "\xfe", 'c'], 'invalid_utf8'],
            'integer-like duplicate' => [['7', 'x', '7'], 'invalid_tabular'],
            'integer-like names that differ' => [['1', '01', '1'], 'invalid_tabular'],
        ];
        // A table wider than 16 columns builds its rows from a template, and a table without
        // rows compares digests of its names: each finds duplicates its own way.
        $filler = array_map(static fn (int $i): string => sprintf('f%02d', $i), range(0, 15));
        foreach ($cases as $case => [$names, $code]) {
            foreach ([1, 0] as $rows) {
                yield "$case, $rows rows" => [$names, $code, $rows];
                yield "$case, $rows rows, wide" => [[...$filler, ...$names], $code, $rows];
            }
        }
    }

    /**
     * Column names are checked front to back, each for UTF-8 (in both modes) and then
     * against the earlier names; the first name that fails sets the error (SPEC §5.6.3).
     *
     * @param list<string> $names
     */
    #[DataProvider('columnNames')]
    public function testColumnNamesAreCheckedFrontToBack(array $names, string $code, int $rows): void
    {
        $table = self::table($rows, $names, array_fill(0, \count($names), str_repeat("\xc0", $rows)));
        $this->assertDecodeError($code, $table);
        $this->assertDecodeError($code, $table, ['strict' => false]);
        // Without the bad names, the same table decodes.
        $good = array_values(array_unique(array_filter($names, static fn (string $name): bool => preg_match('//u', $name) === 1)));
        $decoded = decode((string) hex2bin(self::table($rows, $good, array_fill(0, \count($good), str_repeat("\xc0", $rows)))));
        self::assertIsArray($decoded);
        self::assertCount($rows, $decoded);
    }

    /**
     * A map may have at most max_map_entries entries, and a table at most that many columns:
     * PHP's arrays hash keys without a secret seed, so keys can be chosen to collide, and then
     * every key added to a map is compared with all the keys before it (review 2026-11-13).
     * The default is 4096; more raises size_limit, after the announced count has been checked
     * against the input (truncated).
     */
    public function testMaxMapEntries(): void
    {
        self::assertSame(4096, DecodeOptions::DEFAULT_MAX_MAP_ENTRIES);
        self::assertSame(4096, (new DecodeOptions())->maxMapEntries);
        self::assertSame(4096, DecodeOptions::fromArray([])->maxMapEntries);
        self::assertSame(7, DecodeOptions::fromArray(['max_map_entries' => 7])->maxMapEntries);
        self::assertSame(7, (new Decoder(new DecodeOptions(maxMapEntries: 7)))->getOptions()->maxMapEntries);

        // The default: 4,096 entries decode, 4,097 don't, and the message says how to change that.
        $map = static function (int $entries): string {
            $hex = $entries <= 15 ? dechex(0x80 | $entries) : 'de' . sprintf('%04x', $entries);
            for ($i = 0; $i < $entries; ++$i) {
                $hex .= bin2hex(sprintf("\xa5k%04d", $i)) . 'c0';
            }

            return $hex;
        };
        self::assertCount(4096, (array) decode((string) hex2bin($map(4096))));
        $this->assertDecodeError('size_limit', $map(4097), [], 'a map of 4097 entries exceeds the max_map_entries option (4096); raise it');
        self::assertCount(4097, (array) decode((string) hex2bin($map(4097)), ['max_map_entries' => 4097]));

        // Set explicitly, also for nested maps and with every map an ArrayObject.
        self::assertSame(['a' => 1, 'b' => 2], decode((string) hex2bin('82a16101a16202'), ['max_map_entries' => 2]));
        $this->assertDecodeError('size_limit', '83a16101a16202a16303', ['max_map_entries' => 2]);
        $this->assertDecodeError('size_limit', '9181a16183a161c0a162c0a163c0', ['max_map_entries' => 2]);
        $this->assertDecodeError('size_limit', '83a16101a16202a16303', ['max_map_entries' => 2, 'maps' => 'object']);
        $this->assertDecodeError('size_limit', '81a16101', ['max_map_entries' => 0]);
        self::assertEquals(new \ArrayObject(), decode("\x80", ['max_map_entries' => 0]));
        // In non-strict mode too, which counts entries, not distinct keys.
        $this->assertDecodeError('size_limit', '83a16101a16102a16103', ['max_map_entries' => 2, 'strict' => false]);

        // A table's rows are maps of its columns, with or without rows.
        $three = self::table(2, ['a', 'b', 'c'], ["\x01\x02", "\x03\x04", "\x05\x06"]);
        self::assertSame([['a' => 1, 'b' => 3, 'c' => 5], ['a' => 2, 'b' => 4, 'c' => 6]], decode((string) hex2bin($three), ['max_map_entries' => 3]));
        $this->assertDecodeError('size_limit', $three, ['max_map_entries' => 2], 'a table of 3 columns exceeds the max_map_entries option (2)');
        $this->assertDecodeError('size_limit', self::table(0, ['a', 'b', 'c'], ['', '', '']), ['max_map_entries' => 2]);

        // The announced count is checked against the input first.
        $this->assertDecodeError('truncated', 'dfffffffff', ['max_map_entries' => 2]);
        $this->assertDecodeError('invalid_tabular', 'c70cf6' . '00000001' . '0000ffff' . '00000000', ['max_map_entries' => 2]);
    }

    /**
     * Without explicit limits, max_values is at most memory_limit / 1024 and
     * max_decompressed_size at most memory_limit / 8, so that a document within the limits
     * can't exhaust memory_limit. Explicit limits are used as given.
     */
    public function testDefaultsFollowMemoryLimit(): void
    {
        $saved = (string) ini_get('memory_limit');
        $cases = [
            '128M' => [131_072, 16 << 20],
            '256M' => [262_144, 32 << 20],
            '512M' => [524_288, 64 << 20],
            '1G' => [1_048_576, 64 << 20],
            '2G' => [1_048_576, 64 << 20],
            '-1' => [1_048_576, 64 << 20],
        ];
        try {
            foreach ($cases as $limit => [$values, $size]) {
                self::assertNotFalse(ini_set('memory_limit', (string) $limit));
                foreach ([new DecodeOptions(), DecodeOptions::fromArray([]), (new Decoder())->getOptions(),
                    DecodeOptions::fromArray(['max_values' => null, 'max_decompressed_size' => null])] as $options) {
                    self::assertSame($values, $options->maxValues, "max_values at $limit");
                    self::assertSame($size, $options->maxDecompressedSize, "max_decompressed_size at $limit");
                }
                $explicit = new DecodeOptions(maxDecompressedSize: 1 << 30, maxValues: 1 << 30);
                self::assertSame(1 << 30, $explicit->maxValues);
                self::assertSame(1 << 30, $explicit->maxDecompressedSize);
                $fromArray = DecodeOptions::fromArray(['max_values' => 5, 'max_decompressed_size' => 0]);
                self::assertSame(5, $fromArray->maxValues);
                self::assertSame(0, $fromArray->maxDecompressedSize);
            }
        } finally {
            ini_set('memory_limit', $saved);
        }
        self::assertSame(1_048_576, DecodeOptions::DEFAULT_MAX_VALUES);
        self::assertSame(64 << 20, DecodeOptions::DEFAULT_MAX_DECOMPRESSED_SIZE);
    }

    /** @return iterable<string, array{string}> */
    public static function hostileEnvelopes(): iterable
    {
        foreach (['timestamps', 'empty-maps', 'rows', 'large-envelope'] as $case) {
            yield $case => [$case];
        }
    }

    /**
     * A few KB of zlib can describe 2 million timestamps, 15 million empty maps or 8 million
     * table rows, which take far more than 128M as PHP values: 0.1.x died with an uncatchable
     * "Allowed memory size exhausted". With the defaults derived from memory_limit, each
     * fails with size_limit instead (review 2026-11-13, X1).
     */
    #[DataProvider('hostileEnvelopes')]
    public function testHostileEnvelopesFailCleanlyUnderMemoryLimit128M(string $case): void
    {
        $command = [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/Fixtures/memory-limit-probe.php', $case];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame('size_limit', $stdout, $stderr);
        self::assertSame(0, $status, $stderr);
    }

    private function assertValues(string $hex, int $values): void
    {
        $bytes = (string) hex2bin($hex);
        try {
            decode($bytes, ['max_values' => $values]);
        } catch (BtoonException $e) {
            self::fail(sprintf('%s with max_values %d: %s', $hex, $values, $e->getMessage()));
        }
        $this->assertDecodeError('size_limit', $hex, ['max_values' => $values - 1]);
    }

    /** @param array<string, mixed> $options */
    private function assertDecodeError(string $code, string $hex, array $options = [], ?string $message = null): void
    {
        try {
            decode((string) hex2bin($hex), $options);
        } catch (BtoonException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            if ($message !== null) {
                self::assertStringContainsString($message, $e->getMessage());
            }

            return;
        }
        self::fail(sprintf('%s decoded, expected %s', substr($hex, 0, 64), $code));
    }

    /**
     * The hex of a tabular extension (SPEC §5.6.1) whose columns hold the given encoded cells.
     *
     * @param list<string> $names
     * @param list<string> $columns
     */
    private static function table(int $rows, array $names, array $columns): string
    {
        $payload = pack('NNN', 1, \count($names), $rows);
        foreach ($names as $name) {
            $payload .= pack('N', \strlen($name)) . $name;
        }
        $payload .= str_repeat("\x00", \count($names)); // type codes (informational)
        foreach ($columns as $column) {
            $payload .= pack('N', \strlen($column)) . $column;
        }

        return bin2hex("\xc9" . pack('N', \strlen($payload)) . "\xf6" . $payload);
    }
}

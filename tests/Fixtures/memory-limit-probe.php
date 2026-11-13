<?php

declare(strict_types=1);

// Decodes a hostile zlib envelope with the default options and prints the outcome: the
// BtoonException's error code, or "ok". LimitsTest runs it under a low memory_limit, where
// a fatal error ("Allowed memory size exhausted") would mean the default limits let a small
// input exhaust memory_limit (review 2026-11-13, X1).
//
//   php -d memory_limit=128M tests/Fixtures/memory-limit-probe.php <case>

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use BTOON\BtoonException;

use function BTOON\decode;

/**
 * A zlib compression envelope of $head followed by $count copies of $unit, compressed in
 * chunks so that the probe itself never holds the uncompressed document.
 */
function envelope(string $head, string $unit, int $count): string
{
    $context = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    if ($context === false) {
        throw new RuntimeException('deflate_init failed');
    }
    $perChunk = intdiv(1 << 20, \strlen($unit));
    $chunk = str_repeat($unit, $perChunk);
    $payload = (string) deflate_add($context, $head, ZLIB_NO_FLUSH);
    for ($left = $count; $left > 0; $left -= $perChunk) {
        $payload .= (string) deflate_add($context, $left >= $perChunk ? $chunk : str_repeat($unit, $left), ZLIB_NO_FLUSH);
    }
    $payload .= (string) deflate_add($context, '', ZLIB_FINISH);
    $size = \strlen($head) + $count * \strlen($unit);

    return 'BTON' . pack('CCnNN', 1, 0, 0, \strlen($payload), $size) . $payload;
}

/** The ext32 header and table header of a one-column table of $rows rows, before its cells. */
function tableHead(int $rows, int $cellBytes): string
{
    $payload = pack('NNNN', 1, 1, $rows, 1) . "a\x00" . pack('N', $cellBytes);

    return "\xc9" . pack('N', \strlen($payload) + $cellBytes) . "\xf6" . $payload;
}

$input = match ($argv[1] ?? '') {
    // 2,000,000 timestamps (12 MB): about 390 bytes each as DateTimeImmutable.
    'timestamps' => envelope("\xdd" . pack('N', 2_000_000), "\xd6\xff\x65\x92\x00\x80", 2_000_000),
    // 15,000,000 empty maps (15 MB): ArrayObject with maps => 'auto', about 290 bytes each.
    'empty-maps' => envelope("\xdd" . pack('N', 15_000_000), "\x80", 15_000_000),
    // A table of 8,000,000 one-cell rows (8 MB): about 200 bytes per row.
    'rows' => envelope(tableHead(8_000_000, 8_000_000), "\xc0", 8_000_000),
    // A 60 MiB binary: inflating holds up to twice that while the string grows.
    'large-envelope' => envelope("\xc6" . pack('N', 60 << 20), "\x00", 60 << 20),
    default => throw new InvalidArgumentException('unknown case ' . ($argv[1] ?? '')),
};
memory_reset_peak_usage();
try {
    decode($input);
    echo 'ok';
} catch (BtoonException $e) {
    echo $e->errorCode;
}
fprintf(STDERR, "%s: %d-byte input, peak %.1f MB\n", $argv[1], \strlen($input), memory_get_peak_usage() / 1e6);

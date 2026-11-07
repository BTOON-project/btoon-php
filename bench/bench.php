<?php

declare(strict_types=1);

// Size and speed for 10,000 records, the dataset the other BTOON READMEs use.
// Run: php bench/bench.php   (best of 7 rounds after a warm-up)

require dirname(__DIR__) . '/vendor/autoload.php';

use function BTOON\decode;
use function BTOON\encode;

$records = [];
for ($i = 0; $i < 10000; ++$i) {
    $records[] = [
        'id' => $i,
        'name' => "user_$i",
        'email' => "user$i@example.com",
        'age' => 18 + $i % 60,
        'score' => $i * 0.37,
        'active' => $i % 3 === 0,
    ];
}

function best(callable $fn, int $rounds = 7): float
{
    $fn();
    $best = INF;
    for ($r = 0; $r < $rounds; ++$r) {
        $t = hrtime(true);
        $fn();
        $best = min($best, (hrtime(true) - $t) / 1e6);
    }

    return $best;
}

$cases = [
    'btoon' => [fn () => encode($records), fn (string $b) => decode($b)],
    'btoon, auto_tabular off' => [fn () => encode($records, ['auto_tabular' => false]), fn (string $b) => decode($b)],
    'json_encode / json_decode' => [
        fn () => json_encode($records, JSON_THROW_ON_ERROR),
        fn (string $j) => json_decode($j, true, 512, JSON_THROW_ON_ERROR),
    ],
];
if (function_exists('msgpack_pack')) {
    $cases['msgpack (PECL)'] = [fn () => msgpack_pack($records), fn (string $m) => msgpack_unpack($m)];
}

$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
printf("PHP %s, %s, JIT %s\n", PHP_VERSION, php_uname('m'), is_array($status) && !empty($status['jit']['on']) ? 'on' : 'off');
printf("%-28s %10s %10s %10s\n", 'library', 'size', 'encode', 'decode');
foreach ($cases as $name => [$enc, $dec]) {
    $bytes = $enc();
    printf("%-28s %8.0f KB %7.2f ms %7.2f ms\n", $name, strlen($bytes) / 1000, best($enc), best(fn () => $dec($bytes)));
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

// Run from the repository root after `composer install`:  php examples/01-basic.php

require __DIR__ . '/../vendor/autoload.php';

use BTOON\BigInt;
use BTOON\Binary;
use BTOON\BtoonException;
use BTOON\Timestamp;

use function BTOON\decode;
use function BTOON\encode;

echo 'BTOON for PHP ', BTOON\VERSION, ' (spec ', BTOON\SPEC_VERSION, ")\n\n";

// 1. Maps, lists and scalars. Keys are written in canonical (UTF-8 byte) order.
$user = ['name' => 'Alice', 'age' => 30, 'active' => true, 'tags' => ['admin', 'dev'], 'score' => 9.5];
$bytes = encode($user);
printf("1. %d bytes: %s\n", strlen($bytes), bin2hex($bytes));
var_export(decode($bytes));
echo "\n\n";

// 2. Arrays of uniform records use the columnar "tabular" encoding automatically
//    when that is smaller; they decode back to the same list of maps.
$rows = [];
for ($i = 1; $i <= 100; ++$i) {
    $rows[] = ['id' => $i, 'name' => "user$i", 'email' => "user$i@example.com"];
}
printf(
    "2. 100 records: %d bytes tabular, %d bytes as plain MessagePack, %d bytes of JSON\n",
    strlen(encode($rows)),
    strlen(encode($rows, ['auto_tabular' => false])),
    strlen((string) json_encode($rows)),
);
assert(decode(encode($rows))[99]['email'] === 'user100@example.com');

// 3. Types PHP can't express directly have value classes.
$value = [
    'big' => new BigInt('18446744073709551616'),            // 2^64, a bigint extension
    'blob' => new Binary("\x00\x01\x02"),                    // bytes, never a string
    'when' => new DateTimeImmutable('2024-01-01T12:00:00.25Z'), // UTC timestamp
    'local' => Timestamp::fromDateTime(new DateTimeImmutable('2024-01-01T14:00:00+02:00'), keepOffset: true),
];
$decoded = decode(encode($value));
printf(
    "3. big=%s blob=0x%s when=%s local=%s\n",
    $decoded['big'],
    bin2hex($decoded['blob']),
    $decoded['when']->format('Y-m-d\TH:i:s.vP'),
    $decoded['local']->format(DATE_RFC3339),
);
$exact = decode(encode($value), ['timestamps' => 'object', 'binary_as_object' => true]);
printf("   lossless: %s, %d ns\n", $exact['blob']::class, $exact['when']->nanoseconds);

// 4. Compression and signing: sign(compress(encode(value))).
$key = random_bytes(32);
$sealed = encode($rows, ['compress' => true, 'hmac_key' => $key]);
printf("4. compressed + signed: %d bytes, starts with %s\n", strlen($sealed), substr($sealed, 0, 4));
assert(decode($sealed, ['hmac_key' => $key]) === decode(encode($rows)));

// 5. Errors carry the spec's machine-readable code.
$attempts = [
    'truncated array' => ["\x92\x01", []],
    'tampered envelope' => [substr_replace($sealed, 'X', -1), ['hmac_key' => $key]],
    'invalid UTF-8' => ["\xa1\xff", []],
];
foreach ($attempts as $label => [$bad, $options]) {
    try {
        decode($bad, $options);
    } catch (BtoonException $e) {
        printf("5. %s: %s (%s)\n", $label, $e->errorCode, $e->getMessage());
    }
}

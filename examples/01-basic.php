#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use function BTOON\encode;
use function BTOON\decode;

echo "BTOON PHP Basic Example\n";
echo "========================\n\n";

// Simple data types
$simpleData = [
    'message' => 'Hello, BTOON!',
    'count' => 42,
    'pi' => 3.14159,
    'active' => true,
    'empty' => null,
];

echo "1. Simple data encoding:\n";
echo "Original: " . json_encode($simpleData) . "\n";

$encoded = encode($simpleData);
echo "Encoded size: " . strlen($encoded) . " bytes\n";
echo "Encoded (hex): " . substr(bin2hex($encoded), 0, 50) . "...\n";

$decoded = decode($encoded);
echo "Decoded: " . json_encode($decoded) . "\n";

// Verify data integrity
assert($decoded == $simpleData, "Decode mismatch!");

// Nested structures
$nestedData = [
    'user' => [
        'id' => 1001,
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'roles' => ['admin', 'user'],
        'settings' => [
            'theme' => 'dark',
            'notifications' => true,
            'language' => 'en',
        ],
    ],
    'metadata' => [
        'created' => '2024-01-01T00:00:00Z',
        'version' => '0.0.1',
    ],
];

echo "\n2. Nested structure encoding:\n";
$nestedEncoded = encode($nestedData);
$jsonSize = strlen(json_encode($nestedData));
$btoonSize = strlen($nestedEncoded);

echo "Original size (JSON): {$jsonSize} bytes\n";
echo "BTOON size: {$btoonSize} bytes\n";
echo "Size reduction: " . round((1 - $btoonSize / $jsonSize) * 100) . "%\n";

// Arrays of different types
$arrayData = [
    'numbers' => [1, 2, 3, 4, 5],
    'strings' => ['apple', 'banana', 'cherry'],
    'mixed' => [42, 'hello', true, null, ['key' => 'value']],
    'matrix' => [[1, 2], [3, 4], [5, 6]],
];

echo "\n3. Array encoding:\n";
$arrayEncoded = encode($arrayData);
$arrayDecoded = decode($arrayEncoded);

echo "Numbers preserved: " . json_encode($arrayDecoded['numbers']) . "\n";
echo "Strings preserved: " . json_encode($arrayDecoded['strings']) . "\n";
echo "Mixed types preserved: " . json_encode($arrayDecoded['mixed']) . "\n";
echo "Matrix preserved: " . json_encode($arrayDecoded['matrix']) . "\n";

// Binary data
$binaryData = [
    'id' => 'file-001',
    'content' => 'Binary content here',
    'checksum' => pack('C*', 0xDE, 0xAD, 0xBE, 0xEF),
];

echo "\n4. Binary data encoding:\n";
$binaryEncoded = encode($binaryData);
$binaryDecoded = decode($binaryEncoded);

echo "Content preserved: " . $binaryDecoded['content'] . "\n";
echo "Checksum preserved: ";
foreach (unpack('C*', $binaryDecoded['checksum']) as $byte) {
    printf("0x%02X ", $byte);
}
echo "\n";

// Large integers
$largeData = [
    'small_int' => 42,
    'large_int' => PHP_INT_MAX,
    'float_val' => 3.14159265359,
    'negative' => -9223372036854775808,
];

echo "\n5. Large number handling:\n";
$largeEncoded = encode($largeData);
$largeDecoded = decode($largeEncoded);

foreach ($largeDecoded as $key => $value) {
    echo "  {$key}: {$value} (" . gettype($value) . ")\n";
    assert($value === $largeData[$key], "Number {$key} not preserved!");
}

// Unicode and special characters
$unicodeData = [
    'english' => 'Hello World',
    'chinese' => '你好世界',
    'japanese' => 'こんにちは世界',
    'arabic' => 'مرحبا بالعالم',
    'emoji' => '🚀 🌟 🎉 🔥',
    'special' => 'café, naïve, résumé',
];

echo "\n6. Unicode handling:\n";
$unicodeEncoded = encode($unicodeData);
$unicodeDecoded = decode($unicodeEncoded);

foreach ($unicodeDecoded as $key => $value) {
    assert($value === $unicodeData[$key], "Unicode {$key} not preserved!");
    echo "  {$key}: {$value} ✓\n";
}

echo "\n✅ All basic examples completed successfully!\n";

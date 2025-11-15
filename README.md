# BTOON for PHP

[![Packagist Version](https://img.shields.io/packagist/v/btoon/btoon)](https://packagist.org/packages/btoon/btoon)
[![PHP Version](https://img.shields.io/packagist/php-v/btoon/btoon)](https://packagist.org/packages/btoon/btoon)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

High-performance binary serialization format for PHP applications with optional native C++ extension.

## Features

- 🚀 **High Performance** - Optional native C++ extension for 10x speed
- 📦 **Compact Binary Format** - Smaller than JSON, faster than serialize()
- 🗜️ **Built-in Compression** - ZLIB, LZ4, ZSTD, Brotli, Snappy support
- 📊 **Tabular Data Optimization** - Columnar storage for structured data
- 🔄 **Pure PHP Fallback** - Works without extension
- ⚡ **Zero Dependencies** - Minimal requirements
- 🛡️ **Type Safety** - Preserves PHP types accurately

## Installation

### Via Composer

```bash
composer require btoon/btoon
```

### Native Extension (Optional, Recommended)

For best performance, install the native extension:

```bash
# From source
git clone --recursive https://github.com/BTOON-project/btoon-php.git
cd btoon-php
make ext-install
```

Or via PECL (when available):
```bash
pecl install btoon
```

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use function BTOON\encode;
use function BTOON\decode;

// Encode data
$data = [
    'name' => 'BTOON',
    'version' => '0.0.1',
    'features' => ['fast', 'compact', 'typed'],
    'metrics' => [
        'speed' => 9000,
        'size' => 0.5
    ]
];

$encoded = encode($data);
echo 'Encoded size: ' . strlen($encoded) . " bytes\n";

// Decode data
$decoded = decode($encoded);
var_dump($decoded);
```

## Advanced Features

### Compression

```php
use BTOON\BTOON;

// Enable compression
$compressed = BTOON::encode($data, [
    'compress' => true,
    'algorithm' => 'zstd', // 'zlib', 'lz4', 'zstd', 'brotli', 'snappy'
    'level' => 3
]);

// Decode compressed data
$decoded = BTOON::decode($compressed, [
    'decompress' => true
]);
```

### Tabular Data

```php
// Automatically detect and optimize tabular data
$records = [
    ['id' => 1, 'name' => 'Alice', 'age' => 30],
    ['id' => 2, 'name' => 'Bob', 'age' => 25],
    ['id' => 3, 'name' => 'Charlie', 'age' => 35]
];

$tabular = encode($records, [
    'auto_tabular' => true // Automatically uses columnar encoding
]);
```

### Streaming

```php
use BTOON\Stream\Encoder;
use BTOON\Stream\Decoder;

// Stream encoding
$encoder = new Encoder();

foreach ($largeDataset as $item) {
    $encoder->encode($item);
}

$encodedData = $encoder->finish();

// Stream decoding  
$decoder = new Decoder($encodedData);

while ($item = $decoder->decode()) {
    processItem($item);
}
```

### Object Serialization

```php
class User {
    public int $id;
    public string $name;
    public string $email;
    public bool $active;
    public DateTime $createdAt;
}

$user = new User();
$user->id = 1;
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->active = true;
$user->createdAt = new DateTime();

// Serialize object
$encoded = encode($user);

// Deserialize to stdClass
$decoded = decode($encoded);

// Or deserialize to specific class
$decodedUser = decode($encoded, ['class' => User::class]);
```

## API Reference

### Functions

#### `encode($value, array $options = []): string`
Encode PHP value to BTOON format.

**Options:**
- `compress` (bool): Enable compression
- `algorithm` (string): Compression algorithm
- `level` (int): Compression level (1-9)
- `auto_tabular` (bool): Auto-detect tabular data

#### `decode(string $data, array $options = [])`
Decode BTOON data to PHP value.

**Options:**
- `decompress` (bool): Enable decompression
- `class` (string): Target class for object deserialization
- `associative` (bool): Decode objects as arrays

#### `has_native_extension(): bool`
Check if native extension is available.

#### `version(): string`
Get BTOON version.

### Classes

#### `BTOON\BTOON`
Main class with static methods for encoding/decoding.

#### `BTOON\Stream\Encoder`
Streaming encoder for large datasets.

#### `BTOON\Stream\Decoder`
Streaming decoder for large datasets.

## Performance

BTOON provides significant performance improvements:

| Operation | JSON | serialize() | BTOON (Pure) | BTOON (Native) |
|-----------|------|-------------|--------------|----------------|
| Encode 1MB | 45ms | 30ms | 25ms | 3ms |
| Decode 1MB | 40ms | 25ms | 20ms | 2ms |
| Size | 1024KB | 850KB | 412KB | 412KB |

### Benchmarks

Run benchmarks:
```bash
make bench
```

## Examples

See the [`examples/`](examples/) directory:
- [`01-basic.php`](examples/01-basic.php) - Basic encoding/decoding
- [`02-compression.php`](examples/02-compression.php) - Compression features
- [`03-streaming.php`](examples/03-streaming.php) - Stream processing
- [`04-objects.php`](examples/04-objects.php) - Object serialization

## Building from Source

### Requirements
- PHP >= 7.4
- Composer
- C++ compiler (for extension)
- CMake (for extension)

### Build Steps

```bash
# Clone repository
git clone --recursive https://github.com/BTOON-project/btoon-php.git
cd btoon-php

# Install PHP dependencies
composer install

# Build native extension (optional)
make ext

# Run tests
make test

# Run examples
make examples
```

## Development

```bash
# Run all checks
make check

# Fix code style
make format

# Run static analysis
make phpstan

# Generate coverage
make test-coverage
```

## Testing

```bash
# Run tests
vendor/bin/phpunit

# With coverage
vendor/bin/phpunit --coverage-html coverage

# Quick test
make test-quick
```

## Docker Support

```dockerfile
FROM php:8.2-cli

# Install extension
RUN pecl install btoon && \
    docker-php-ext-enable btoon

# Install library
RUN composer global require btoon/btoon
```

## Framework Integration

### Laravel

```php
// config/app.php
'providers' => [
    BTOON\Laravel\BTOONServiceProvider::class,
],

// Usage
use BTOON\Laravel\Facades\BTOON;

$encoded = BTOON::encode($data);
```

### Symfony

```yaml
# config/services.yaml
services:
    BTOON\BTOON:
        public: true
```

## Contributing

Contributions are welcome! Please read our [Contributing Guide](CONTRIBUTING.md) for details.

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Links

- [Website](https://btoon.net)
- [GitHub](https://github.com/BTOON-project/btoon-php)
- [Packagist](https://packagist.org/packages/btoon/btoon)
- [Documentation](https://btoon.net/docs/php)

---

Part of the BTOON project - High-performance binary serialization for modern applications.
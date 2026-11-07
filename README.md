# BTOON for PHP

[![CI](https://github.com/BTOON-project/btoon-php/actions/workflows/ci.yml/badge.svg)](https://github.com/BTOON-project/btoon-php/actions/workflows/ci.yml)

A pure-PHP implementation of [BTOON](https://github.com/BTOON-project/btoon-core), a
compact binary serialization format. BTOON is MessagePack plus:

- a **columnar ("tabular") encoding** for arrays of uniform records, so each key is
  written once instead of once per row;
- **extension types** for timestamps, dates, big integers and float vectors;
- an optional **compression envelope** and an optional **HMAC-signed envelope**.

Encoding is **canonical**: every value has exactly one encoding, so every BTOON
implementation produces the same bytes for the same value. Decoding is **hardened**
against hostile input: bounds-checked reads, a nesting limit, strict UTF-8, no trailing
bytes, and size-limited decompression.

|                    |                                                                     |
|--------------------|---------------------------------------------------------------------|
| Library version    | 0.1.0 (`BTOON\VERSION`)                                             |
| Spec version       | 0.1 (`BTOON\SPEC_VERSION`), [SPEC.md](https://github.com/BTOON-project/btoon-core/blob/main/docs/SPEC.md) |
| Conformance        | all 260 vectors of `btoon-core/tests/vectors` (the zstd and lz4 vectors need those extensions; brotli is not supported, see [Compression](#compression)) |
| Requirements       | PHP 8.2+ (64-bit), ext-zlib                                         |
| Optional           | ext-mbstring (faster UTF-8 checks), ext-gmp (faster big integers), ext-zstd, ext-lz4, ext-snappy |

There is no C extension: the library is plain PHP and runs anywhere PHP does.

## Installation

```bash
composer require btoon/btoon
```

The package is [`btoon/btoon` on Packagist](https://packagist.org/packages/btoon/btoon).

## Quick start

```php
use function BTOON\decode;
use function BTOON\encode;

$bytes = encode(['name' => 'Alice', 'age' => 30, 'active' => true]);
bin2hex($bytes); // 83a6616374697665c3a36167651ea46e616d65a5416c696365

decode($bytes);  // ['active' => true, 'age' => 30, 'name' => 'Alice']
```

Map keys are written in canonical order (sorted by their UTF-8 bytes), so the decoded
array's key order can differ from the original's.

Arrays of uniform records switch to the columnar encoding automatically when it is
smaller (the size rule of SPEC §5.6.2), and decode back to the same list of arrays:

```php
$rows = [];
for ($i = 1; $i <= 100; ++$i) {
    $rows[] = ['id' => $i, 'name' => "user$i", 'email' => "user$i@example.com"];
}
strlen(encode($rows));                           // 2738 (tabular)
strlen(encode($rows, ['auto_tabular' => false])); // 4187 (plain MessagePack)
strlen(json_encode($rows));                       // 5477
```

`examples/01-basic.php` walks through the main features (`php examples/01-basic.php`).

The same API is available as classes:

```php
use BTOON\{Decoder, DecodeOptions, Encoder, EncodeOptions};

$encoder = new Encoder(new EncodeOptions(compress: true));
$decoder = new Decoder(['max_depth' => 32, 'timestamps' => 'object']);
$value = $decoder->decode($encoder->encode($data));
```

`BTOON\BTOON::encode()` and `BTOON\BTOON::decode()` are static aliases of the functions.

## Type mapping

### Encoding

| PHP value | BTOON |
|---|---|
| `null`, `bool` | nil, bool |
| `int` | integer, in the smallest format for its value |
| `float` | float64 (always; NaN is written as `7ff8000000000000`; `-0.0` and ±INF are kept) |
| `string` that is valid UTF-8 | string |
| `string` that is not valid UTF-8 | binary |
| `BTOON\Binary` | binary (use it for bytes that happen to be valid UTF-8) |
| list array (keys `0…n-1` in order), including `[]` | array |
| any other array | map; keys become strings |
| `stdClass`, `ArrayObject`, `ArrayIterator` | map, even when empty or list-like |
| `JsonSerializable` | whatever `jsonSerialize()` returns (as with `json_encode`) |
| `BackedEnum` | its value |
| `DateTimeInterface` | timestamp in UTC, microsecond precision |
| `BTOON\Timestamp` | timestamp (nanoseconds, optional UTC offset) |
| `BTOON\Date`, `BTOON\DateTime` | date (-2, milliseconds), datetime (-3, nanoseconds) |
| `BTOON\BigInt`, `GMP` | integer inside [-2^63, 2^64-1], otherwise bigint (-4) |
| `BTOON\VectorF32`, `BTOON\VectorF64` | float32 / float64 vector |
| `BTOON\Ext` | generic extension (ids -1…-6 and -10 are reserved for the classes above) |

Anything else, such as resources, closures, pure enums and other objects, raises
`invalid_argument`. Implement `JsonSerializable` to make your own classes encodable.
Deep or cyclic structures raise `max_depth` (default limit 128).

### Decoding

| BTOON | PHP value |
|---|---|
| nil, bool, float | `null`, `bool`, `float` |
| integer | `int`; uint64 values above `PHP_INT_MAX` become `BTOON\BigInt` |
| bigint (-4) | `int` when it fits in 64 bits, otherwise `BTOON\BigInt` |
| string | `string` |
| binary | `string`, or `BTOON\Binary` with `binary_as_object => true` |
| array | list array |
| map | associative array |
| tabular | list of associative arrays |
| timestamp (-1) | `DateTimeImmutable` in UTC (or in the timestamp's fixed offset, such as `+02:00`); `BTOON\Timestamp` with `timestamps => 'object'` |
| date (-2), datetime (-3) | `DateTimeImmutable` in UTC; `BTOON\Date` / `BTOON\DateTime` with `timestamps => 'object'` |
| float32 / float64 vector | `BTOON\VectorF32` / `BTOON\VectorF64` |
| other extensions | `BTOON\Ext` |

`DateTimeImmutable` holds microseconds, so nanoseconds are truncated. Use
`timestamps => 'object'` when you need exact values.

### PHP-specific ambiguities

These follow the cross-language rules of the BTOON implementation guide:

- **An empty array encodes as an empty array.** Pass `new ArrayObject()` or
  `new stdClass()` for an empty map.
- **A string that is not valid UTF-8 encodes as binary**, because strict decoders reject
  invalid UTF-8 in strings. Wrap bytes in `BTOON\Binary` to force binary.
- **Integer-like map keys** such as `"10"` or `"-1"` become `int` array keys on decode,
  because that is how PHP arrays work. The encoder turns them back into strings, so the
  round trip is lossless.
- **Some maps decode to arrays that re-encode differently:** an empty map decodes to
  `[]`, and a map with keys `"0"…"n-1"` in order decodes to a list. Re-encoding those
  gives an array. Likewise a binary value that is valid UTF-8 decodes to a string and
  re-encodes as a string unless you decode with `binary_as_object => true`.

## Big integers

`BTOON\BigInt` holds an arbitrary-precision integer:

```php
$n = new BigInt('-340282366920938463463374607431768211456');
(string) $n;          // the decimal string
$n->toBytes();        // two's-complement big-endian bytes, as on the wire
$n->fitsInt();        // false
$n->equals('-340282366920938463463374607431768211456'); // true (compare with equals(), not ==)
BigInt::fromGmp(gmp_pow(2, 100));
$n->toGmp();          // needs ext-gmp
```

With ext-gmp, conversions use GMP; without it, a pure-PHP fallback is used. Decoding a
huge bigint is linear in its size, because the decimal string is computed only when you
ask for it.

## Timestamps

`DateTimeInterface` values encode as plain UTC timestamps, whatever their time zone.
To keep a UTC offset (the BTOON-only 14-byte form), say so explicitly:

```php
use BTOON\Timestamp;

$local = new DateTimeImmutable('2024-01-01T14:00:00+02:00');
encode($local);                                         // UTC instant, no offset
encode(Timestamp::fromDateTime($local, keepOffset: true)); // offset +120 minutes kept
encode(new Timestamp(1704067200, 500_000_000));         // nanosecond precision
```

## Compression

```php
$bytes = encode($value, ['compress' => true]);                          // zlib
$bytes = encode($value, ['compress' => true, 'compression' => 'zstd']); // needs ext-zstd
decode($bytes); // envelopes are detected automatically
```

Inputs under 256 bytes are never compressed, and the envelope is only used when it saves
at least 5% (`20 × envelope_size ≤ 19 × input_size`); otherwise the plain document is
returned. Asking for an algorithm that isn't available raises `unsupported_compression`
regardless of the input size.

| Algorithm | Needs | Status |
|---|---|---|
| `zlib` | ext-zlib (required by this package) | always available |
| `zstd` | [ext-zstd](https://github.com/kjdev/php-ext-zstd) 0.15 or newer | supported |
| `lz4` | [ext-lz4](https://github.com/kjdev/php-ext-lz4) 0.8 or newer (`lz4_compress_raw()`) | supported; older ext-lz4 versions only write a size-prefixed format that BTOON doesn't use |
| `snappy` | [ext-snappy](https://github.com/kjdev/php-ext-snappy) | supported |
| `brotli` | — | not supported: ext-brotli can't stop decompressing at a size limit or detect trailing bytes, which SPEC §6 requires |

`BTOON\Compression::available()` lists what works in the current installation.

Decompression is exact and bounded. The declared size is checked against
`max_decompressed_size` (default 256 MiB) before anything is decompressed, output is cut
off as soon as it exceeds the declared size, and payloads with trailing bytes, a wrong
size, a bad checksum or a preset dictionary are rejected with `invalid_envelope`. Zstd
frames are validated before decompression (a single frame, a matching content size, and a
window no larger than max(8 MiB, min(size, 128 MiB))).

## Signing

```php
$key = random_bytes(32);
$bytes = encode($value, ['hmac_key' => $key]);   // HMAC-SHA256 signed envelope
decode($bytes, ['hmac_key' => $key]);            // verifies before parsing

encode($value, ['compress' => true, 'hmac_key' => $key]); // sign(compress(encode(value)))
```

A decoder with a key rejects unsigned input and bad signatures (`authentication_failed`),
using a constant-time comparison. A decoder without a key rejects signed input instead of
ignoring the signature. An empty key string is an error (`invalid_argument`), so a missing
secret can't silently disable verification; pass `null` (or nothing) for "no key".

## Options

Options are a snake_case array, or the typed `EncodeOptions` / `DecodeOptions` objects
(constructor parameters in camelCase). Unknown keys and wrongly typed values raise
`invalid_argument`.

| Encode option | Default | Meaning |
|---|---|---|
| `compress` | `false` | Wrap the output in a compression envelope. |
| `compression` | `'zlib'` | `'zlib'`, `'zstd'`, `'lz4'`, `'snappy'` or `'brotli'` (or a `BTOON\Compression` case). Used when `compress` is true. |
| `auto_tabular` | `true` | Use the columnar encoding when the size rule says so. |
| `max_depth` | `128` | Nesting limit; deeper or cyclic data raises `max_depth`. |
| `hmac_key` | `null` | Sign the output. |

| Decode option | Default | Meaning |
|---|---|---|
| `strict` | `true` | Strict mode (SPEC §7). Non-strict mode accepts trailing bytes (ignored), duplicate map keys (last wins) and invalid UTF-8, and nothing else. |
| `max_depth` | `128` | Nesting limit. A tabular array counts as two levels. |
| `max_decompressed_size` | `268435456` | Largest accepted `uncompressed_size` of a compression envelope, in bytes. |
| `auto_decompress` | `true` | Accept compression envelopes; when false they raise `invalid_envelope`. |
| `hmac_key` | `null` | Require and verify a signed envelope. |
| `timestamps` | `'datetime'` | `'datetime'` returns `DateTimeImmutable`; `'object'` returns `Timestamp` / `Date` / `DateTime`. |
| `binary_as_object` | `false` | Return binaries as `BTOON\Binary` instead of strings. |

## Errors

Every failure is a `BTOON\BtoonException` carrying a SPEC §7 error code, shared by all
BTOON implementations:

```php
try {
    decode($bytes);
} catch (BTOON\BtoonException $e) {
    $e->errorCode;      // e.g. 'truncated'
    $e->getErrorCode(); // the same string
    $e->error;          // BTOON\ErrorCode::Truncated
    $e->getMessage();   // includes the byte offset for decoding errors
}
```

| Code | Meaning |
|---|---|
| `truncated` | The input ends early, or a length or count runs past the input. |
| `invalid_marker` | The reserved marker `0xc1`. |
| `trailing_data` | Bytes after the top-level value (strict mode). |
| `invalid_utf8` | A string, map key or tabular column name isn't valid UTF-8 (when decoding in strict mode, or a map key when encoding). |
| `non_string_key` | A map key that isn't a string. |
| `duplicate_key` | The same map key twice (strict mode). |
| `max_depth` | Nesting deeper than `max_depth`, including cyclic data when encoding. |
| `invalid_extension` | A malformed timestamp, date, datetime, bigint or vector payload. |
| `invalid_tabular` | A malformed tabular payload. |
| `invalid_envelope` | A malformed compression or signed envelope, or a corrupt compressed payload. |
| `size_limit` | A declared size above `max_decompressed_size`, or data too large for the format (over 4 GiB). |
| `unsupported_compression` | An unknown or unavailable compression algorithm. |
| `authentication_failed` | Missing or wrong signature, or a signature without a key. |
| `invalid_argument` | A value that can't be encoded, or an invalid option. |

## Security notes

- Decoding is bounded by the input: element counts are checked against the remaining
  bytes before any loop, and nesting stops at `max_depth`. Keep `max_depth` modest; the
  decoder is recursive.
- Strict mode, the default, rejects duplicate keys and invalid UTF-8 so that two
  conforming decoders can't disagree about what a document means.
- Verify signatures on the bytes you received (that's what `hmac_key` does); don't
  re-encode and compare.

## Performance

10,000 records (`id`, `name`, `email`, `age`, `score`, `active`), the dataset the other
BTOON libraries use. PHP 8.5.12 on an Apple M4 Max, best of 7 (`php bench/bench.php`):

| | size | encode | decode | encode, JIT | decode, JIT |
|---|---:|---:|---:|---:|---:|
| `BTOON\encode` / `BTOON\decode` | 447 KB | 5.5 ms | 5.8 ms | 3.7 ms | 3.7 ms |
| the same, `auto_tabular => false` | 767 KB | 7.3 ms | 12.3 ms | 4.8 ms | 6.0 ms |
| `json_encode` / `json_decode` | 1,018 KB | 2.1 ms | 3.6 ms | 2.2 ms | 3.6 ms |

"JIT" is `php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M`.
JSON is a C extension. This library is pure PHP, so it is slower than `json_*`, but the
output is 56% smaller and byte-identical to what the C++, Python and Go libraries write.

## Development

```sh
composer install
composer test           # PHPUnit: unit tests, conformance vectors, mutation test
composer stan           # PHPStan, level max, on src/
composer sync-vectors   # copy ../btoon-core/tests/vectors/btoon-vectors.json
                        # (BTOON_CORE=/path/to/btoon-core to use another checkout)
```

- `tests/vectors/btoon-vectors.json` is a verbatim copy of the core conformance vectors;
  don't edit it by hand.
- `tests/Fixtures/reference-cases.json` holds extra cases produced by core's independent
  Python encoder. Regenerate them with
  `python3 tests/Fixtures/generate-reference-cases.py` (needs `msgpack`).
- The mutation test decodes about 30,000 corrupted inputs and fails on anything other
  than a `BtoonException`, including PHP warnings. Set `BTOON_MUTATIONS` (iterations per
  vector, default 30) to run more.
- CI runs PHP 8.2–8.5, a run without ext-gmp, and a run with ext-zstd, ext-lz4 and
  ext-snappy built from source.

## License

MIT.
